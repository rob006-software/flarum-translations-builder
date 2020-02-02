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
use app\components\extensions\exceptions\UnableLoadComposerJsonException;
use app\components\extensions\exceptions\UnprocessableExtensionInterface;
use app\models\Extension;
use app\models\packagist\SearchResult;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use mindplay\readable;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use UnexpectedValueException;
use Yii;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use function array_filter;
use function count;
use function json_decode;
use function reset;
use function strlen;
use function strncmp;

/**
 * Class ExtensionsFinder.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ExtensionsRepository extends Component {

	public const NO_TRANSLATION_FILE = 'no-translation-source.yml';

	public $packagistCacheDuration = 3600;
	public $githubCacheDuration = 3600;

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
				}, $this->packagistCacheDuration);
			} else {
				$this->_extensions = $this->fetchExtensions();
			}
		}

		return $this->_extensions;
	}

	/**
	 * @param string[] $supportedVersions
	 * @param bool $useCache
	 * @return Extension[]
	 */
	public function getValidExtensions(array $supportedVersions, bool $useCache = true): array {
		$extensions = $this->getAllExtensions($useCache);
		foreach ($extensions as $index => $extension) {
			try {
				if ($extension->isAbandoned()) {
					unset($extensions[$index]);
				} elseif ($extension->isLanguagePack()) {
					unset($extensions[$index]);
				} elseif ($extension->isOutdated($supportedVersions)) {
					unset($extensions[$index]);
				}
			} /* @noinspection PhpRedundantCatchClauseInspection */ catch (UnprocessableExtensionInterface $exception) {
				Yii::warning($exception->getMessage());
				unset($extensions[$index]);
			}
		}

		return $extensions;
	}

	private function getClient(): HttpClientInterface {
		if ($this->_client === null) {
			$this->_client = HttpClient::create();
		}

		return $this->_client;
	}

	private function fetchExtensions(): array {
		$results = $this->searchPackagist([
			'type' => 'flarum-extension',
			'per_page' => 100,
		]);

		$extensions = [];
		foreach ($results as $result) {
			assert($result instanceof SearchResult);
			$extension = Extension::createFromPackagistSearchResult($result);
			if (
				!isset($extensions[$extension->getId()])
				// handle ID conflicts
				|| $this->compareExtensions($extension, $extensions[$extension->getId()]) > 0
			) {
				$extensions[$extension->getId()] = $extension;
			}
		}

		return $extensions;
	}

	private function compareExtensions(Extension $a, Extension $b): int {
		if ($b->isAbandoned()) {
			return 1;
		}
		if ($a->isAbandoned()) {
			return -1;
		}

		// `a-b-c` ID could be created by `a/b-c` package or `a-b/c` package. Prefer this one with shorter vendor - it
		// will ber harder to create malicious package with conflicting ID.
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

	public function getPackagistBasicData(string $name): ?SearchResult {
		return Yii::$app->cache->getOrSet(__METHOD__ . '#' . $name, function () use ($name) {
			$results = $this->searchPackagist([
				'q' => $name,
				'type' => 'flarum-extension',
				'per_page' => 100,
			]);

			foreach ($results as $result) {
				assert($result instanceof SearchResult);
				if ($result->getName() === $name) {
					return $result;
				}
			}
		}, $this->packagistCacheDuration);
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

	public function getPackagistReleaseData(string $name, string $version): ?array {
		$data = Yii::$app->arrayCache->getOrSet(__METHOD__ . '#' . $name, function () use ($name) {
			try {
				return $this->getClient()->request('GET', "https://repo.packagist.org/p/{$name}.json")->toArray();
			} catch (HttpExceptionInterface $exception) {
				throw new InvalidPackageNameException(
					"Unable to get Packagist data from: https://repo.packagist.org/p/{$name}.json.",
					$exception->getCode(),
					$exception
				);
			}
		});

		return $data['packages'][$name][$version] ?? null;
	}

	public function getComposerJsonData(string $repositoryUrl, bool $refresh = false): array {
		$callback = function () use ($repositoryUrl): array {
			$url = $this->generateRawUrl($repositoryUrl, 'composer.json');
			$response = $this->getClient()->request('GET', $url);
			try {
				// We cannot use toArray() here - raw.githubusercontent.com always returns content-type as
				// "text/plain; charset=utf-8" while HttpClient expects JSON compatible content-type header.
				$return = json_decode($response->getContent(), true);
				if ($return === null) {
					throw new UnableLoadComposerJsonException(
						'Invalid content of composer.json for ' . readable::value($repositoryUrl) . '.',
						$response->getStatusCode()
					);
				}

				return $return;
			} catch (HttpExceptionInterface $exception) {
				throw new UnableLoadComposerJsonException(
					'Unable to get composer.json for ' . readable::value($repositoryUrl) . '.',
					$response->getStatusCode(),
					$exception
				);
			}
		};

		if ($refresh) {
			$value = $callback();
			Yii::$app->cache->set(__METHOD__ . '#' . $repositoryUrl, $value, $this->githubCacheDuration);
			return $value;
		}

		return Yii::$app->cache->getOrSet(__METHOD__ . '#' . $repositoryUrl, $callback, $this->githubCacheDuration);
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
			$response = $this->getClient()->request('GET', $url);
			if ($response->getStatusCode() < 300 && $response->getContent() !== '') {
				return $url;
			}
		}

		return $this->generateRawUrl($repositoryUrl, static::NO_TRANSLATION_FILE);
	}

	/**
	 * @param string $repositoryUrl
	 * @param string[]|null $prefixes
	 * @return string|null
	 */
	public function detectLastTag(string $repositoryUrl, ?array $prefixes = null): ?string {
		$tags = Yii::$app->arrayCache->getOrSet(__METHOD__ . '#' . $repositoryUrl, function () use ($repositoryUrl) {
			if ($this->isGithubRepo($repositoryUrl)) {
				$tags = ArrayHelper::getColumn(Yii::$app->githubApi->getTags($repositoryUrl), 'name');
			} elseif ($this->isGitlabRepo($repositoryUrl)) {
				$tags = ArrayHelper::getColumn(Yii::$app->gitlabApi->getTags($repositoryUrl), 'name');
			} else {
				throw new InvalidRepositoryUrlException('Invalid repository URL: ' . readable::value($repositoryUrl) . '.');
			}

			// remove non-semver tags
			$parser = new VersionParser();
			return array_filter($tags, static function ($name) use ($parser) {
				try {
					$parser->normalize($name);
					return true;
				} catch (UnexpectedValueException $exception) {
					return false;
				}
			});
		});

		if ($prefixes !== null) {
			$tags = array_filter($tags, static function (string $item) use ($prefixes) {
				foreach ($prefixes as $prefix) {
					if (strncmp($prefix, $item, strlen($prefix)) === 0) {
						return true;
					}
				}

				return false;
			});
		}

		if (empty($tags)) {
			return null;
		}
		if (count($tags) === 1) {
			return reset($tags);
		}

		return Semver::rsort($tags)[0];
	}

	private function generateRawUrl(string $repositoryUrl, string $file, ?string $branch = null): string {
		$path = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');
		if (substr($path, -4) === '.git') {
			$path = substr($path, 0, -4);
		}
		if ($this->isGithubRepo($repositoryUrl)) {
			$branch = $branch ?? Yii::$app->githubApi->getRepoInfo($repositoryUrl)['default_branch'] ?? 'master';
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
					Yii::warning('Unsupported repository: ' . readable::value($result->getRepository()));
				}
			}
		} while (isset($response['next']));

		return $results;
	}
}
