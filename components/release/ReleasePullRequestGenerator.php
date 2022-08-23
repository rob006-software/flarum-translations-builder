<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\release;

use app\components\GithubApi;
use app\components\release\exceptions\PullRequestMergeException;
use app\helpers\StringHelper;
use app\models\Subsplit;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use yii\base\InvalidArgumentException;
use function date;
use function file_get_contents;
use function file_put_contents;
use function sleep;

/**
 * Class ReleasePullRequestGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ReleasePullRequestGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	public const MAINTAINER_ASSOCIATIONS = [
		'OWNER',
		'MEMBER',
		'COLLABORATOR',
	];

	private $subsplit;
	private $repository;
	private $githubApi;
	private $generator;

	public function __construct(Subsplit $subsplit, ?GithubApi $githubApi = null) {
		$this->subsplit = $subsplit;
		$this->repository = $subsplit->getRepository();
		$this->githubApi = $githubApi ?? Yii::$app->githubApi;

		$this->generator = $this->subsplit->createReleaseGenerator();
	}

	public function getGenerator(): ReleaseGenerator {
		return $this->generator;
	}

	public function generate(): void {
		$this->repository->syncBranchesWithRemote();
		$newChangelog = $this->generator->generateChangelog(true);
		$branchName = "release/{$this->repository->getBranch()}";
		if ($this->repository->hasBranch($branchName)) {
			$this->repository->checkoutBranch($branchName);
			$this->repository->update(false);

			file_put_contents($this->generator->getChangelogPath(), $newChangelog);
			$this->repository->commit("Update changelog.");
			$this->repository->push();

			$this->updatePullRequest($branchName);
			return;
		}

		$this->repository->createBranch($branchName);

		file_put_contents($this->generator->getChangelogPath(), $newChangelog);
		$this->repository->commit("Update changelog.");
		$this->repository->push();

		$this->openPullRequest($branchName);
	}

	public function merge(): void {
		$branchName = "release/{$this->repository->getBranch()}";
		$pullRequest = $this->githubApi->getPullRequestForBranch(
			$this->subsplit->getRepositoryUrl(),
			$this->subsplit->getRepositoryUrl(),
			$branchName
		);
		if ($pullRequest === null) {
			throw new PullRequestMergeException("There is no PR for branch $branchName.");
		}
		if ($pullRequest['state'] !== 'open') {
			return;
		}

		$version = StringHelper::getBetween($pullRequest['title'], '`', '`');
		if ($version === null) {
			throw new PullRequestMergeException("PR #{$pullRequest['number']} does not have version in title.");
		}
		$this->generator->setNextVersion($version);
		$changes = StringHelper::getBetween($pullRequest['body'], '<!-- release-notes-begin -->', '<!-- release-notes-end -->');
		if ($changes === null) {
			throw new PullRequestMergeException("PR #{$pullRequest['number']} does not have release notes in body.");
		}
		$this->generator->setChangelogEntryContent($changes);
		$reviews = Yii::$app->githubApi->getReviewsForPullRequest($this->subsplit->getRepositoryUrl(), $pullRequest['number']);
		foreach ($reviews as $review) {
			if (in_array($review['author_association'], self::MAINTAINER_ASSOCIATIONS, true) && $review['state'] === 'APPROVED') {
				$this->repository->syncBranchesWithRemote();
				$this->repository->checkoutBranch($branchName);
				$this->repository->update(false);

				$newChangelog = strtr(file_get_contents($this->generator->getChangelogPath()), ['XXXX-XX-XX' => date('Y-m-d')]);
				file_put_contents($this->generator->getChangelogPath(), $newChangelog);
				$this->repository->commit("Update changelog.");
				$this->repository->push();

				if ($pullRequest['draft']) {
					$this->githubApi->markPullRequestAsReadyForReview($pullRequest['node_id']);
				}

				$mergeableTries = 0;
				$lastCommitHash = $this->repository->getLastCommitHash();
				do {
					if ($mergeableTries > 24) {
						throw new PullRequestMergeException("PR #{$pullRequest['number']} is not mergeable.");
					}
					if ($mergeableTries > 0) {
						sleep(5);
					}
					$mergeableTries++;

					$pullRequest = $this->githubApi->getPullRequest($this->subsplit->getRepositoryUrl(), $pullRequest['number']);
				} while ($pullRequest['mergeable'] !== true || $pullRequest['head']['sha'] !== $lastCommitHash);

				$this->githubApi->mergePullRequest($this->subsplit->getRepositoryUrl(), $pullRequest['number'], [
					'sha' => $pullRequest['head']['sha'],
					'mergeMethod' => 'squash',
					'message' => "Update CHANGELOG.md for {$this->generator->getNextVersion()} release.",
				]);
				$this->repository->checkoutBranch($this->repository->getBranch());
				$this->repository->deleteBranch($branchName);

				$this->generator->release();

				$this->githubApi->addPullRequestComment(
					$this->subsplit->getRepositoryUrl(),
					$pullRequest['number'],
					[
						'body' => $this->generateAfterMergeComment(),
					]
				);

				return;
			}
		}
	}

	private function openPullRequest(string $branchName): void {
		$pullRequest = $this->githubApi->openPullRequest(
			$this->subsplit->getRepositoryUrl(),
			$this->subsplit->getRepositoryUrl(),
			$branchName,
			[
				'base' => $this->repository->getBranch(),
				'title' => "Release `{$this->generator->getNextVersion()}`",
				'body' => $this->generatePullRequestBody(),
				'draft' => true,
			]
		);
		if (!empty($this->generator->maintainers)) {
			$this->githubApi->addPullRequestAssignees($this->subsplit->getRepositoryUrl(), $pullRequest['number'], $this->generator->maintainers);
			$this->githubApi->addPullRequestRequestedReviewers($this->subsplit->getRepositoryUrl(), $pullRequest['number'], $this->generator->maintainers);
		}
	}

	private function updatePullRequest(string $branchName): void {
		$pullRequest = $this->githubApi->getPullRequestForBranch(
			$this->subsplit->getRepositoryUrl(),
			$this->subsplit->getRepositoryUrl(),
			$branchName
		);
		if ($pullRequest === null) {
			throw new InvalidArgumentException("There is no PR for branch $branchName.");
		}
		if ($pullRequest['state'] === 'open') {
			$this->githubApi->updatePullRequest(
				$this->subsplit->getRepositoryUrl(),
				$pullRequest['number'],
				[
					'title' => "Release `{$this->generator->getNextVersion()}`",
					'body' => $this->generatePullRequestBody(),
				]
			);
		}
	}

	private function generatePullRequestBody(): string {
		[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		$approvePrUrl = 'https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/reviewing-changes-in-pull-requests/approving-a-pull-request-with-required-reviews';
		return <<<MD
			This is draft of changelog for `{$this->generator->getNextVersion()}` release.
			
			If you [approve]($approvePrUrl) this pull request it will be automatically merged and new release will be tagged.
			
			Here you can see all changes in this release: [{$this->generator->getPreviousVersion()}...{$this->repository->getBranch()}](https://github.com/$userName/$repoName/compare/{$this->generator->getPreviousVersion()}...{$this->repository->getBranch()})
			
			## Release notes preview
			
			<!-- release-notes-begin -->
			{$this->generator->getChangelogEntryContent()}
			<!-- release-notes-end -->
			MD;
	}

	private function generateAfterMergeComment(): string {
		$forumUrl = $this->generator->getThreadUrl() ?? 'https://discuss.flarum.org/t/languages';
		return <<<MD
			Success! Now you can announce new release on [forum]({$forumUrl}):
			
			~~~markdown
			{$this->generator->getAnnouncement()}
			~~~
			MD;
	}
}