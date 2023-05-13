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

namespace app\components;

use app\components\extensions\exceptions\GithubApiException;
use app\helpers\HttpClient;
use Github\AuthMethod;
use Github\Client;
use Github\Exception\RuntimeException as GithubRuntimeException;
use Github\HttpClient\Builder;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use mindplay\readable;
use Symfony\Component\HttpClient\HttplugClient;
use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use function strncmp;

/**
 * Class GithubApi.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class GithubApi extends Component {

	public $authToken;
	public $authPassword;
	public $authMethod = AuthMethod::ACCESS_TOKEN;

	/** @var Client */
	private $githubApiClient;

	public function init(): void {
		parent::init();

		$httpClient = new HttplugClient(HttpClient::create());
		$httpBuilder = new Builder($httpClient);
		$httpBuilder->addPlugin(new HeaderSetPlugin(['User-Agent' => HttpClient::USER_AGENT]));
		$this->githubApiClient = new Client($httpBuilder);
		$this->githubApiClient->authenticate($this->authToken, $this->authPassword, $this->authMethod);
	}

	public function getDefaultBranch(string $repoUrl): ?string {
		return Yii::$app->cache->getOrSet(__METHOD__ . '#' . $repoUrl, function () use ($repoUrl) {
			return $this->getRepoInfo($repoUrl)['default_branch'];
		}, 7 * 24 * 3600);
	}

	public function getRepoInfo(string $repoUrl): array {
		[$userName, $repoName] = $this->explodeRepoUrl($repoUrl);
		try {
			return Yii::$app->arrayCache->getOrSet(__METHOD__ . '#' . $repoUrl, function () use ($userName, $repoName) {
				return $this->githubApiClient->repo()->show($userName, $repoName);
			});
		} catch (GithubRuntimeException $exception) {
			throw new GithubApiException(
				'Unable to get GitHub API data for ' . readable::value($repoUrl) . '.',
				$exception->getCode(),
				$exception
			);
		}
	}

	public function getTags(string $repoUrl): array {
		[$userName, $repoName] = $this->explodeRepoUrl($repoUrl);
		try {
			return Yii::$app->arrayCache->getOrSet(__METHOD__ . '#' . $repoUrl, function () use ($userName, $repoName) {
				return $this->githubApiClient->repo()->tags($userName, $repoName);
			});
		} catch (GithubRuntimeException $exception) {
			throw new GithubApiException(
				'Unable to get GitHub API data for ' . readable::value($repoUrl) . '.',
				$exception->getCode(),
				$exception
			);
		}
	}

	public function getTagsUrl(string $repoUrl): string {
		[$userName, $repoName] = $this->explodeRepoUrl($repoUrl);
		return "https://github.com/$userName/$repoName/releases";
	}

	public function getTagUrl(string $repoUrl, string $tagName): string {
		[$userName, $repoName] = $this->explodeRepoUrl($repoUrl);
		return "https://github.com/$userName/$repoName/releases/tag/$tagName";
	}

	public function getPullRequestUrl(string $repoUrl, int $number): string {
		[$userName, $repoName] = $this->explodeRepoUrl($repoUrl);
		return "https://github.com/$userName/$repoName/pull/$number";
	}

	public function openPullRequest(string $targetRepository, string $sourceRepository, string $branch, array $settings): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		[$sourceUserName,] = $this->explodeRepoUrl($sourceRepository);
		return $this->githubApiClient->pullRequests()
			->create($targetUserName, $targetRepoName, [
				'base' => $settings['base'] ?? 'master',
				'head' => $sourceUserName === $targetUserName ? $branch : "$sourceUserName:$branch",
				'maintainer_can_modify' => true,
				'title' => $settings['title'],
				'body' => $settings['body'],
				'draft' => $settings['draft'] ?? false,
			]);
	}

	public function updatePullRequest(string $targetRepository, int $number, array $parameters): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		return $this->githubApiClient->pullRequests()
			->update($targetUserName, $targetRepoName, $number, $parameters);
	}

	public function markPullRequestAsReadyForReview(string $nodeId) {
		// REST API does not allow to change draft status, so we need to use GraphQL
		$this->githubApiClient->graphql()->execute(
			<<<'GRAPHQL'
				mutation($prId: ID!) {
					markPullRequestReadyForReview(input: {pullRequestId: $prId}){
						clientMutationId 
					}
				}
			GRAPHQL,
			[
				'prId' => $nodeId,
			]
		);
	}

	public function mergePullRequest(string $targetRepository, int $number, array $settings): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		return $this->githubApiClient->pullRequests()
			->merge(
				$targetUserName,
				$targetRepoName,
				$number,
				$settings['message'] ?? '',
				$settings['sha'] ?? '',
				$settings['mergeMethod'] ?? 'merge',
				$settings['title'] ?? null
			);
	}

	public function addPullRequestRequestedReviewers(string $targetRepository, int $number, array $reviewers): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		return $this->githubApiClient->pullRequests()->reviewRequests()
			->create($targetUserName, $targetRepoName, $number, $reviewers);
	}

	public function addPullRequestAssignees(string $targetRepository, int $number, array $assignees): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		return $this->githubApiClient->issues()->assignees()
			->add($targetUserName, $targetRepoName, $number, ['assignees' => $assignees]);
	}

	public function createRelease(string $repository, string $tagName, array $settings): array {
		[$username, $repositoryName] = $this->explodeRepoUrl($repository);
		return $this->githubApiClient->repos()->releases()
			->create($username, $repositoryName, ['tag_name' => $tagName] + $settings);
	}

	public function addPullRequestComment(string $targetRepository, int $number, array $settings): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		return $this->githubApiClient->issues()->comments()
			->create($targetUserName, $targetRepoName, $number, $settings);
	}

	public function getPullRequest(string $repository, int $number): ?array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($repository);
		return $this->githubApiClient->pullRequests()->show($targetUserName, $targetRepoName, $number);
	}

	public function getPullRequestForBranch(string $targetRepository, string $sourceRepository, string $branch): ?array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		[$sourceUserName,] = $this->explodeRepoUrl($sourceRepository);
		$info = $this->githubApiClient->pullRequests()->all($targetUserName, $targetRepoName, [
			'head' => "$sourceUserName:$branch",
			'state' => 'all',
		]);

		// in case of multiple PRs for the same branch, we pick the most recent one (newest first is default GitHub sorting)
		return $info[0] ?? null;
	}

	public function getReviewsForPullRequest(string $targetRepository, int $number): array {
		[$targetUserName, $targetRepoName] = $this->explodeRepoUrl($targetRepository);
		$page = 1;
		$result = [];
		do {
			$response = $this->githubApiClient->pullRequests()->reviews()
				->all($targetUserName, $targetRepoName, $number, ['per_page' => 100, 'page' => $page]);
			$result = array_merge($result, $response);
		} while (count($response) === 100);

		return $result;
	}

	public function createIssueIfNotExist(string $repository, string $title, array $settings = []): ?array {
		[$userName, $repoName] = $this->explodeRepoUrl($repository);
		$issues = $this->githubApiClient->issues()->all($userName, $repoName);
		foreach ($issues as $issue) {
			if ($issue['title'] === $title) {
				return null;
			}
		}

		$settings['title'] = $title;
		return $this->githubApiClient->issues()->create($userName, $repoName, $settings);
	}

	public function createIssue(string $repository, array $settings): array {
		[$userName, $repoName] = $this->explodeRepoUrl($repository);
		return $this->githubApiClient->issues()->create($userName, $repoName, $settings);
	}

	public function explodeRepoUrl(string $repoUrl): array {
		if (strncmp($repoUrl, 'https://github.com/', 19) === 0) {
			$path = trim(parse_url($repoUrl, PHP_URL_PATH), '/');
		} elseif (strncmp($repoUrl, 'git@github.com:', 15) === 0) {
			$path = trim(substr($repoUrl, 15), '/');
		} else {
			throw new InvalidArgumentException('Invalid GitHub repo URL: ' . readable::value($repoUrl) . '.');
		}

		if (substr($path, -4) === '.git') {
			$path = substr($path, 0, -4);
		}
		return explode('/', trim($path, '/'), 2);
	}
}
