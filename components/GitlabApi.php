<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components;

use app\components\extensions\exceptions\GitlabApiException;
use mindplay\readable;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use function parse_url;
use function strncmp;
use function substr;
use function trim;
use function urlencode;
use const PHP_URL_PATH;

/**
 * Class GitlabApi.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class GitlabApi extends Component {

	private $_client;

	public function getDefaultBranch(string $repoUrl): ?string {
		$repoName = urlencode($this->extractRepoName($repoUrl));
		try {
			$branches = $this->getClient()
				->request('GET', "https://gitlab.com/api/v4/projects/$repoName/repository/branches/")
				->toArray();
		} catch (HttpExceptionInterface $exception) {
			throw new GitlabApiException(
				"Unable to get GitLab API data for https://gitlab.com/api/v4/projects/$repoName/repository/branches/.",
				$exception->getCode(),
				$exception
			);
		}

		foreach ($branches as $branch) {
			if (!empty($branch['default'])) {
				return $branch['name'];
			}
		}

		return null;
	}

	public function getTags(string $repoUrl): array {
		$repoName = urlencode($this->extractRepoName($repoUrl));
		try {
			return $this->getClient()
				->request('GET', "https://gitlab.com/api/v4/projects/$repoName/repository/tags/")
				->toArray();
		} catch (HttpExceptionInterface $exception) {
			throw new GitlabApiException(
				"Unable to get GitLab API data for https://gitlab.com/api/v4/projects/$repoName/repository/tags/.",
				$exception->getCode(),
				$exception
			);
		}
	}

	public function getTagsUrl(string $repoUrl): string {
		$repoName = $this->extractRepoName($repoUrl);
		return "https://gitlab.com/$repoName/-/tags";
	}

	public function getTagUrl(string $repoUrl, string $tagName): string {
		$repoName = $this->extractRepoName($repoUrl);
		return "https://gitlab.com/$repoName/-/tags/$tagName";
	}

	private function getClient(): HttpClientInterface {
		if ($this->_client === null) {
			$this->_client = HttpClient::create();
		}

		return $this->_client;
	}

	private function extractRepoName(string $repoUrl): string {
		if (strncmp($repoUrl, 'https://gitlab.com/', 19) === 0) {
			$path = trim(parse_url($repoUrl, PHP_URL_PATH), '/');
		} elseif (strncmp($repoUrl, 'git@gitlab.com:', 15) === 0) {
			$path = trim(substr($repoUrl, 15), '/');
		} else {
			throw new InvalidArgumentException('Invalid GitLab repo URL: ' . readable::value($repoUrl) . '.');
		}

		if (substr($path, -4) === '.git') {
			$path = substr($path, 0, -4);
		}
		return $path;
	}
}
