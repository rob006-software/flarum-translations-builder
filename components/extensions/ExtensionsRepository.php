<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\extensions;

use app\components\extensions\exceptions\GithubApiException;
use app\components\extensions\exceptions\InvalidRepositoryUrlException;
use app\components\extensions\exceptions\UnableLoadComposerJsonException;
use app\models\Extension;
use app\models\packagist\SearchResult;
use Github\Exception\RuntimeException as GithubRuntimeException;
use mindplay\readable;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Yii;
use yii\base\Component;
use function json_decode;
use function strlen;
use function strncmp;

/**
 * Class ExtensionsFinder.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ExtensionsRepository extends Component {

	public $packagistCacheDuration = 6 * 60 * 60;
	public $githubCacheDuration = 6 * 60 * 60;

	private $_extensions;
	private $_client;

	/**
	 * @param bool $useCache
	 * @return Extension[]
	 */
	public function getExtensions(bool $useCache = true): array {
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
		return $this->getExtensions($useCache)[$id] ?? null;
	}

	public function getPackagistData(string $name): ?SearchResult {
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

	public function detectTranslationSourceUrl(string $repositoryUrl): string {
		$possiblePaths = [
			'resources/locale/en.yml',
			'locale/en.yml',
			'resources/locale/en.yaml',
			'locale/en.yaml',
		];
		foreach ($possiblePaths as $possiblePath) {
			$url = $this->generateRawUrl($repositoryUrl, $possiblePath);
			$response = $this->getClient()->request('GET', $url);
			if ($response->getStatusCode() < 300 && $response->getContent() !== '') {
				return $url;
			}
		}

		return $this->generateRawUrl($repositoryUrl, 'no-translation-source.yml');
	}

	private function generateRawUrl(string $repositoryUrl, string $file, ?string $branch = null): string {
		$path = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');
		if (substr($path, '-4') === '.git') {
			$path = substr($path, 0, -4);
		}
		if ($this->isGithubRepo($repositoryUrl)) {
			try {
				$branch = $branch ?? Yii::$app->githubApi->getRepoInfo($repositoryUrl)['default_branch'] ?? 'master';
			} catch (GithubRuntimeException $exception) {
				throw new GithubApiException(
					'Unable to get GitHub API data for ' . readable::value($repositoryUrl) . '.',
					$exception->getCode(),
					$exception
				);
			}
			return "https://raw.githubusercontent.com/{$path}/{$branch}/{$file}";
		}
		if ($this->isGitlabRepo($repositoryUrl)) {
			// @todo add gitlab API support ot check branch
			$branch = $branch ?? 'master';
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
