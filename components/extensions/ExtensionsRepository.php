<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\extensions;

use app\components\extensions\exceptions\InvalidPackageNameException;
use app\components\extensions\exceptions\InvalidRepositoryUrlException;
use app\components\extensions\exceptions\SoftFailureInterface;
use app\components\extensions\exceptions\UnprocessableExtensionExceptionInterface;
use app\helpers\HttpClient;
use app\models\Extension;
use app\models\packagist\SearchResult;
use app\models\PremiumExtension;
use app\models\RegularExtension;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use mindplay\readable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use UnexpectedValueException;
use Yii;
use yii\base\Component;
use function array_filter;
use function count;
use function in_array;
use function is_array;
use function reset;
use function strlen;
use function strncmp;
use function strtotime;

/**
 * Class ExtensionsFinder.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ExtensionsRepository extends Component {

	public const NO_TRANSLATION_FILE = 'no-translation-source.yml';

	public $cacheDuration = 3600;
	public $packagistCacheDuration = 3600;

	private $_extensions;
	private $_client;

	/**
	 * @param bool $useCache
	 * @return Extension[]
	 */
	public function getAllExtensions(bool $useCache = true): array {
		if ($this->_extensions === null) {
			if ($useCache) {
				$this->_extensions = Yii::$app->cache->getOrSet(__METHOD__, function () {
					return $this->fetchExtensions();
				}, $this->cacheDuration);
			} else {
				$this->_extensions = $this->fetchExtensions();
			}
		}

		return $this->_extensions;
	}

	/**
	 * @param string[] $supportedVersions
	 * @param string[] $unsupportedVersions
	 * @param string[] $ignoredExtensions
	 * @param bool $useCache
	 * @return Extension[]
	 */
	public function getValidExtensions(
		array $supportedVersions,
		array $unsupportedVersions,
		array $ignoredExtensions,
		bool $useCache = true
	): array {
		$extensions = $this->getAllExtensions($useCache);
		foreach ($extensions as $index => $extension) {
			try {
				if (
					in_array($extension->getPackageName(), $ignoredExtensions, true)
					|| $this->isExtensionRateLimited($extension->getPackageName())
				) {
					unset($extensions[$index]);
				} elseif ($extension->isAbandoned()) {
					unset($extensions[$index]);
					$this->registerAbandonedExtensionDetection($extension->getPackageName());
				} elseif ($extension->isLanguagePack()) {
					$this->registerLanguagePackDetection($extension->getPackageName());
					unset($extensions[$index]);
				} elseif ($extension->isOutdated($supportedVersions, $unsupportedVersions) !== false) {
					if ($extension instanceof RegularExtension) {
						$this->registerOutdatedExtensionDetection($extension);
					}
					unset($extensions[$index]);
				}
			} catch (UnprocessableExtensionExceptionInterface $exception) {
				if (!$exception instanceof SoftFailureInterface) {
					Yii::warning($exception->getMessage());
					$this->registerExtensionUpdateFailure($extension->getPackageName());
				}
				unset($extensions[$index]);
			}
		}

		return $extensions;
	}

	private function isExtensionRateLimited(string $name): bool {
		return Yii::$app->cache->get(__CLASS__ . '#rateLimit#' . $name) !== false
			|| Yii::$app->cache->get(__CLASS__ . '#languagePackDetected#' . $name) !== false
			|| Yii::$app->cache->get(__CLASS__ . '#outdatedExtensionDetected#' . $name) !== false
			|| Yii::$app->cache->get(__CLASS__ . '#abandonedExtensionDetected#' . $name) !== false;
	}

	public function resetRateLimitCache(string $extensionName): int {
		$count = 0;
		if (Yii::$app->cache->get(__CLASS__ . '#rateLimit#' . $extensionName) !== false) {
			Yii::$app->cache->delete(__CLASS__ . '#rateLimit#' . $extensionName);
			$count++;
		}
		if (Yii::$app->cache->get(__CLASS__ . '#languagePackDetected#' . $extensionName) !== false) {
			Yii::$app->cache->delete(__CLASS__ . '#languagePackDetected#' . $extensionName);
			$count++;
		}
		if (Yii::$app->cache->get(__CLASS__ . '#outdatedExtensionDetected#' . $extensionName) !== false) {
			Yii::$app->cache->delete(__CLASS__ . '#outdatedExtensionDetected#' . $extensionName);
			$count++;
		}
		if (Yii::$app->cache->get(__CLASS__ . '#abandonedExtensionDetected#' . $extensionName) !== false) {
			Yii::$app->cache->delete(__CLASS__ . '#abandonedExtensionDetected#' . $extensionName);
			$count++;
		}

		return $count;
	}

	private function registerExtensionUpdateFailure(string $name): void {
		if (!Yii::$app->frequencyLimiter->run(__METHOD__ . '#' . $name, 7 * 24 * 3600, null, 5)) {
			if (Yii::$app->mutex->acquire(__METHOD__ . "#$name", 10)) {
				try {
					$monthsCount = Yii::$app->cache->get(__CLASS__ . '#rateLimitFailuresCounter#' . $name) ?: 0;
					$monthsCount++;
					Yii::$app->cache->set(
						__CLASS__ . '#rateLimitFailuresCounter#' . $name,
						$monthsCount,
						($monthsCount + 1) * 31 * 24 * 3600
					);
					if ($monthsCount > 6) {
						$monthsCount = 6;
					}
					Yii::warning("Ignore $name extension for next $monthsCount months due to exceeding failures threshold.");
					Yii::$app->cache->set(__CLASS__ . '#rateLimit#' . $name, true, $monthsCount * 31 * 24 * 3600);
				} finally {
					Yii::$app->mutex->release(__METHOD__ . "#$name");
				}
			} else {
				Yii::warning('Cannot acquire lock "' . __METHOD__ . "#$name\"");
			}
		}
	}

	private function registerLanguagePackDetection(string $name): void {
		Yii::$app->cache->set(__CLASS__ . '#languagePackDetected#' . $name, true, 31 * 24 * 3600);
	}

	private function registerAbandonedExtensionDetection(string $name): void {
		Yii::$app->cache->set(__CLASS__ . '#abandonedExtensionDetected#' . $name, true, 31 * 24 * 3600);
	}

	private function registerOutdatedExtensionDetection(RegularExtension $extension): void {
		$rateLimitKey = __METHOD__ . '#' . $extension->getPackageName();
		if (Yii::$app->cache->get($rateLimitKey) === false) {
			$versionData = $this->getPackagistLastReleaseData($extension->getPackageName());
			$time = $versionData === null ? 0 : strtotime($versionData['time']);
			$cacheKey = __CLASS__ . '#outdatedExtensionDetected#' . $extension->getPackageName();
			if ($time < strtotime('-12 months')) {
				Yii::$app->cache->set($cacheKey, true, 31 * 24 * 3600);
			} elseif ($time < strtotime('-6 months')) {
				Yii::$app->cache->set($cacheKey, true, 7 * 24 * 3600);
			} else {
				Yii::$app->cache->set($rateLimitKey, true, 7 * 24 * 3600);
			}
		}
	}

	private function getClient(): HttpClientInterface {
		if ($this->_client === null) {
			$this->_client = HttpClient::create();
		}

		return $this->_client;
	}

	private function fetchExtensions(): array {
		$extensions = [];
		foreach (Yii::$app->extiverseApi->getCachedExtensions() as $result) {
			$extension = PremiumExtension::createFromExtiverseCache($result);
			if (
				!isset($extensions[$extension->getId()])
				// handle ID conflicts
				|| $this->compareExtensions($extension, $extensions[$extension->getId()]) > 0
			) {
				$extensions[$extension->getId()] = $extension;
			}
		}

		$results = $this->searchPackagist([
			'type' => 'flarum-extension',
			'per_page' => 100,
		]);
		foreach ($results as $result) {
			assert($result instanceof SearchResult);
			$extension = RegularExtension::createFromPackagistSearchResult($result);
			if (
				!isset($extensions[$extension->getId()])
				// handle ID conflicts
				|| $this->compareExtensions($extension, $extensions[$extension->getId()]) > 0
			) {
				$extensions[$extension->getId()] = $extension;
			}
		}

		foreach ($this->getPackagesList() as $packageName) {
			$id = Extension::nameToId($packageName);
			if (
				isset($extensions[$id])
				&& $extensions[$id]->getPackageName() === $packageName
				// Prefer free extensions over premium - some of them may be registered both as premium on Extiverse
				// and free on Packagist. In that case always compare these extensions even if they have the same
				// package name.
				// @see https://discuss.flarum.org/d/23473-websockets-locally-hosted-alternative-for-pusher-now-free/175
				&& $extensions[$id] instanceof RegularExtension
			) {
				continue;
			}
			$extension = new RegularExtension($packageName);
			if (
				!isset($extensions[$id])
				// handle ID conflicts
				|| $this->compareExtensions($extension, $extensions[$id]) > 0
			) {
				$extensions[$id] = $extension;
			}
		}

		return $extensions;
	}

	private function compareExtensions(Extension $a, Extension $b): int {
		$cacheKey = implode('#', [__METHOD__, 'abandoned',  $a->getPackageName(), $b->getPackageName()]);
		$result = Yii::$app->cache->getOrSet($cacheKey, static function () use ($a, $b) {
			if ($b->isAbandoned() && !$a->isAbandoned()) {
				return 1;
			}
			if ($a->isAbandoned() && !$b->isAbandoned()) {
				return -1;
			}

			return 0;
		}, 31 * 24 * 3600);

		if ($result !== 0) {
			return $result;
		}

		if ($b instanceof PremiumExtension && !$a instanceof PremiumExtension) {
			return 1;
		}
		if ($a instanceof PremiumExtension && !$b instanceof PremiumExtension) {
			return -1;
		}

		// `a-b-c` ID could be created by `a/b-c` package or `a-b/c` package. Prefer this one with shorter vendor - it
		// will be harder to create malicious package with conflicting ID.
		if ($a->getVendor() !== $b->getVendor()) {
			return strlen($b->getVendor()) - strlen($a->getVendor());
		}

		// If vendor is the same, prefer this one with shorter name - it is probably migration from
		// `vendor/flarum-ext-name` to `vendor/name`.
		return strlen($b->getPackageName()) - strlen($a->getPackageName());
	}

	public function getExtension(string $id, bool $useCache = true): ?Extension {
		return $this->getAllExtensions($useCache)[$id] ?? null;
	}

	public function getPackagistData(string $name): ?array {
		return Yii::$app->cache->getOrSet(__METHOD__ . '#' . $name, function () use ($name) {
			try {
				$response = $this->getClient()->request('GET', "https://packagist.org/packages/$name.json")->toArray();
			} catch (HttpExceptionInterface $exception) {
				return null;
			}

			return $response['package'] ?? null;
		}, $this->packagistCacheDuration);
	}

	public function getPackagistLastReleaseData(string $name): ?array {
		return $this->getPackagistReleasesData($name)[0] ?? null;
	}

	public function getPackagistReleasesData(string $name): array {
		return Yii::$app->arrayCache->getOrSet(__METHOD__ . '#' . $name, function () use ($name) {
			try {
				$result = $this->getClient()->request('GET', "https://repo.packagist.org/p2/{$name}.json")->toArray();
				return MetadataMinifier::expand($result['packages'][$name]);
			} catch (HttpExceptionInterface $exception) {
				throw new InvalidPackageNameException(
					"Unable to get Packagist data from: https://repo.packagist.org/p2/{$name}.json.",
					$exception->getCode(),
					$exception
				);
			}
		});
	}

	public function detectTranslationSourceUrl(string $repositoryUrl, ?string $branch = null, ?array $possiblePaths = null): string {
		$possiblePaths = $possiblePaths ?? [
				'resources/locale/en.yml',
				'locale/en.yml',
				'resources/locale/en.yaml',
				'locale/en.yaml',
			];
		foreach ($possiblePaths as $possiblePath) {
			$url = $this->generateRawUrl($repositoryUrl, $possiblePath, $branch);
			if ($this->testSourceUrl($url)) {
				return $url;
			}
		}

		return $this->generateRawUrl($repositoryUrl, self::NO_TRANSLATION_FILE, 'master');
	}

	public function testSourceUrl(string $url, int $tries = 5): bool {
		while ($tries-- > 0) {
			$response = $this->getClient()->request('GET', $url);
			if ($response->getStatusCode() < 300) {
				if ($response->getContent() === '') {
					return false;
				}
				try {
					if (is_array(Yaml::parse($response->getContent()))) {
						return true;
					}
				} catch (ParseException $exception) {
					// ignore exception, we will log warning bellow
				}
				Yii::warning(
					"Cannot load YAML from $url: " . readable::value($response->getContent()),
					__METHOD__ . ':' . $response->getStatusCode()
				);
				return false;
			}
			if (in_array($response->getStatusCode(), [404, 403], true)) {
				return false;
			}
			Yii::warning(
				"Cannot load $url: " . readable::values($response->getInfo()),
				__METHOD__ . ':' . $response->getStatusCode()
			);
			sleep(1);
		}

		return false;
	}

	public function findTagForCommit(string $repositoryUrl, string $commitHash): ?string {
		$tags = [];
		if ($this->isGithubRepo($repositoryUrl)) {
			foreach (Yii::$app->githubApi->getTags($repositoryUrl) as $tag) {
				if ($tag['commit']['sha'] === $commitHash) {
					$tags[] = $tag['name'];
				}
			}
		} elseif ($this->isGitlabRepo($repositoryUrl)) {
			foreach (Yii::$app->gitlabApi->getTags($repositoryUrl) as $tag) {
				if ($tag['commit']['id'] === $commitHash) {
					$tags[] = $tag['name'];
				}
			}
		} else {
			throw new InvalidRepositoryUrlException('Invalid repository URL: ' . readable::value($repositoryUrl) . '.');
		}

		// remove non-semver tags
		$parser = new VersionParser();
		$tags = array_filter($tags, static function ($name) use ($parser) {
			try {
				$parser->normalize($name);
				return true;
			} catch (UnexpectedValueException $exception) {
				return false;
			}
		});

		if (empty($tags)) {
			return null;
		}
		if (count($tags) === 1) {
			return reset($tags);
		}

		return Semver::rsort($tags)[0];
	}

	public function getTagsUrl(string $repositoryUrl): string {
		if ($this->isGithubRepo($repositoryUrl)) {
			return Yii::$app->githubApi->getTagsUrl($repositoryUrl);
		}

		if ($this->isGitlabRepo($repositoryUrl)) {
			return Yii::$app->gitlabApi->getTagsUrl($repositoryUrl);
		}

		throw new InvalidRepositoryUrlException('Invalid repository URL: ' . readable::value($repositoryUrl) . '.');
	}

	public function getTagUrl(string $repositoryUrl, string $tagName): string {
		if ($this->isGithubRepo($repositoryUrl)) {
			return Yii::$app->githubApi->getTagUrl($repositoryUrl, $tagName);
		}

		if ($this->isGitlabRepo($repositoryUrl)) {
			return Yii::$app->gitlabApi->getTagUrl($repositoryUrl, $tagName);
		}

		throw new InvalidRepositoryUrlException('Invalid repository URL: ' . readable::value($repositoryUrl) . '.');
	}

	private function generateRawUrl(string $repositoryUrl, string $file, ?string $branch = null): string {
		$path = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');
		if (substr($path, -4) === '.git') {
			$path = substr($path, 0, -4);
		}
		if ($this->isGithubRepo($repositoryUrl)) {
			$branch = $branch ?? Yii::$app->githubApi->getDefaultBranch($repositoryUrl) ?? 'master';
			return "https://raw.githubusercontent.com/{$path}/{$branch}/{$file}";
		}
		if ($this->isGitlabRepo($repositoryUrl)) {
			$branch = $branch ?? Yii::$app->gitlabApi->getDefaultBranch($repositoryUrl) ?? 'master';
			return "https://gitlab.com/{$path}/raw/{$branch}/{$file}";
		}

		throw new InvalidRepositoryUrlException('Invalid repository URL: ' . readable::value($repositoryUrl) . '.');
	}

	public function isGithubRepo(string $url): bool {
		return strncmp('https://github.com/', $url, 19) === 0 || strncmp('git@github.com:', $url, 15) === 0;
	}

	public function isGitlabRepo(string $url): bool {
		return strncmp('https://gitlab.com/', $url, 19) === 0 || strncmp('git@gitlab.com:', $url, 15) === 0;
	}

	/**
	 * @todo Move this to separate component.
	 *
	 * @param array $filters
	 * @return SearchResult[]
	 */
	private function searchPackagist(array $filters = []): array {
		$response = [
			'next' => 'https://packagist.org/search.json?' . http_build_query($filters),
		];

		$results = [];
		do {
			$response = $this->getClient()->request('GET', $response['next'])->toArray();
			foreach ($response['results'] as $item) {
				$result = SearchResult::createFromApiResponse($item);
				if ($result->isFromGithub() || $result->isFromGitlab()) {
					$results[$result->getName()] = $result;
				} else {
					Yii::$app->frequencyLimiter->run(
						__METHOD__ . '#Unsupported repository: ' . readable::value($result->getRepository()),
						31 * 24 * 3600,
						static function () use ($result) {
							Yii::warning('Unsupported repository: ' . readable::value($result->getRepository()));
						}
					);
				}
			}
		} while (isset($response['next']));

		return $results;
	}

	private function getPackagesList(): array {
		$response = $this->getClient()
			->request('GET', 'https://packagist.org/packages/list.json?type=flarum-extension')
			->toArray();
		return $response['packageNames'];
	}
}
