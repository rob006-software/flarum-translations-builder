<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components;

use Cache\Adapter\Filesystem\FilesystemCachePool;
use Github\Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use mindplay\readable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use function strncmp;
use const APP_ROOT;

/**
 * Class GithubApi.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class GithubApi extends Component {

	public $authToken;
	public $authPassword;
	public $authMethod = Client::AUTH_HTTP_TOKEN;

	/** @var Client */
	private $githubApiClient;

	public function init(): void {
		parent::init();

		$this->githubApiClient = new Client();
		$this->githubApiClient->authenticate($this->authToken, $this->authPassword, $this->authMethod);

		$filesystemAdapter = new Local(APP_ROOT . '/runtime/github-cache');
		$filesystem = new Filesystem($filesystemAdapter);

		$pool = new FilesystemCachePool($filesystem);
		$this->githubApiClient->addCache($pool);
	}

	public function getRepoInfo(string $repoUrl): array {
		[$userName, $repoName] = $this->explodeRepoUrl($repoUrl);
		return $this->githubApiClient->repo()->show($userName, $repoName);
	}

	public function openPullRequest(string $targetRepository, string $sourceRepository, string $branch, array $settings): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		[$sourceUserName,] = $this->explodeRepoUrl($sourceRepository);
		return $this->githubApiClient->pullRequest()
			->create($targetUserName, $targetRepoName, [
				'base' => $settings['base'] ?? 'master',
				'head' => $sourceUserName === $targetUserName ? $branch : "$sourceUserName:$branch",
				'maintainer_can_modify' => true,
				'title' => $settings['title'],
				'body' => $settings['body'],
			]);
	}

	public function addPullRequestComment(string $targetRepository, string $sourceRepository, string $branch, array $settings): array {
		$pullRequest = $this->getPullRequestForBranch($targetRepository, $sourceRepository, $branch);
		if ($pullRequest === null) {
			throw new InvalidArgumentException("There is no PR for branch $branch.");
		}

		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		return $this->githubApiClient->issues()->comments()
			->create($targetUserName, $targetRepoName, $pullRequest['number'], $settings);
	}

	public function getPullRequestForBranch(string $targetRepository, string $sourceRepository, string $branch): ?array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		[$sourceUserName,] = $this->explodeRepoUrl($sourceRepository);
		$info = $this->githubApiClient->pullRequest()->all($targetUserName, $targetRepoName, [
			'head' => $sourceUserName === $targetUserName ? $branch : "$sourceUserName:$branch",
		]);

		// in case of multiple PRs for the same branch, we pick the most recent one (newest first is default GitHub sorting)
		return $info[0] ?? null;
	}

	private function explodeRepoUrl(string $repoUrl): array {
		if (strncmp($repoUrl, 'https://github.com/', 19) === 0) {
			$path = trim(parse_url($repoUrl, PHP_URL_PATH), '/');
		} elseif (strncmp($repoUrl, 'git@github.com:', 15) === 0) {
			$path = trim(substr($repoUrl, 15), '/');
		} else {
			throw new InvalidArgumentException('Invalid GiHub repo URL: ' . readable::value($repoUrl) . '.');
		}

		if (substr($path, '-4') === '.git') {
			$path = substr($path, 0, -4);
		}
		return explode('/', trim($path, '/'), 2);
	}
}
