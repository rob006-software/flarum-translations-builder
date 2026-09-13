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
use app\jobs\AnnounceReleaseOnForumJob;
use app\jobs\QueueMergeReleasePullRequestJob;
use app\models\Subsplit;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Github\Exception\RuntimeException;
use Yii;
use yii\base\InvalidArgumentException;
use function array_column;
use function date;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function sleep;
use function time;

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

	/** Label which marks pull request as queued for automatic merge. */
	public const AUTO_MERGE_LABEL = 'ci-merge-queued';
	/**
	 * How long the auto-merge label must be present on the pull request before we're allowed to merge it. Removing
	 * the label always postpones automatic merge by at least this amount of time, even if the label is added again
	 * right away by `release/check-pull-requests`.
	 */
	public const MIN_AUTO_MERGE_LABEL_AGE = 24 * 60 * 60;
	/** Delay for job which queues pull request for automatic merge. */
	public const AUTO_MERGE_QUEUE_DELAY = 6 * 24 * 60 * 60;
	/** @todo automatic merge is tested only on single language pack for now */
	public const AUTO_MERGE_SUBSPLITS = ['pl'];

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
		if (!$this->generator->hasChanges()) {
			return;
		}
		$newChangelog = $this->generator->generateChangelog(true);
		$branchName = "release/{$this->repository->getBranch()}";
		if ($this->repository->hasBranch($branchName)) {
			$this->repository->checkoutBranch($branchName);
			$this->repository->update(false);

			file_put_contents($this->generator->getChangelogPath(), $newChangelog);
			$this->repository->commit('Update changelog');
			$this->repository->push();

			$this->updatePullRequest($branchName);
			$this->repository->checkoutBranch($this->repository->getBranch());
			return;
		}

		$this->repository->createBranch($branchName);

		file_put_contents($this->generator->getChangelogPath(), $newChangelog);
		$this->repository->commit('Update changelog');
		$this->repository->push();

		$this->openPullRequest($branchName);
		$this->repository->checkoutBranch($this->repository->getBranch());
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

		$reviews = $this->githubApi->getReviewsForPullRequest($this->subsplit->getRepositoryUrl(), $pullRequest['number']);
		foreach ($reviews as $review) {
			if (in_array($review['author_association'], self::MAINTAINER_ASSOCIATIONS, true) && $review['state'] === 'APPROVED') {
				$this->mergePullRequest($pullRequest, $branchName);

				return;
			}
		}
	}

	/**
	 * Merges release pull request without maintainer approval. This is a fallback for situations when maintainer is
	 * not available - pull request is merged only if it was marked as queued for automatic merge (and maintainer did
	 * not disable it by removing label from pull request) for at least `MIN_AUTO_MERGE_LABEL_AGE`.
	 */
	public function autoMerge(int $pullRequestNumber): void {
		$branchName = "release/{$this->repository->getBranch()}";
		$pullRequest = $this->githubApi->getPullRequest($this->subsplit->getRepositoryUrl(), $pullRequestNumber);
		if ($pullRequest === null) {
			throw new PullRequestMergeException("There is no PR #$pullRequestNumber in {$this->subsplit->getRepositoryUrl()}.");
		}
		if ($pullRequest['state'] !== 'open') {
			// pull request was already closed - nothing to do
			return;
		}
		if ($pullRequest['head']['ref'] !== $branchName) {
			// make sure that we're not touching pull request from other Flarum version line
			throw new PullRequestMergeException("PR #$pullRequestNumber is not a release PR for branch $branchName.");
		}
		if (!self::hasAutoMergeLabel($pullRequest)) {
			// automatic merge was disabled by maintainer
			return;
		}
		$labelAddDate = $this->githubApi->getLabelAddDate(
			$this->subsplit->getRepositoryUrl(),
			$pullRequestNumber,
			self::AUTO_MERGE_LABEL
		);
		if ($labelAddDate !== null && $labelAddDate > time() - self::MIN_AUTO_MERGE_LABEL_AGE) {
			// label was added recently - most likely maintainer removed it to postpone the merge and it was added
			// again by `release/check-pull-requests`. Maintainer should get the full amount of time to react, counted
			// from the last time when the label was added, so skip the merge - it will be queued again by
			// `release/check-pull-requests`.
			return;
		}

		$this->mergePullRequest($pullRequest, $branchName);
	}

	public static function hasAutoMergeLabel(array $pullRequest): bool {
		return in_array(self::AUTO_MERGE_LABEL, array_column($pullRequest['labels'] ?? [], 'name'), true);
	}

	/**
	 * Updates changelog with release date, merges pull request and releases a new version.
	 */
	private function mergePullRequest(array $pullRequest, string $branchName): void {
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

		$this->repository->syncBranchesWithRemote();
		$this->repository->checkoutBranch($branchName);
		$this->repository->update(false);

		$newChangelog = file_get_contents($this->generator->getChangelogPath());
		$pos = strpos($newChangelog, 'XXXX-XX-XX');
		if ($pos !== false) {
			$newChangelog = substr_replace($newChangelog, date('Y-m-d'), $pos, strlen('XXXX-XX-XX'));
		}
		file_put_contents($this->generator->getChangelogPath(), $newChangelog);
		$this->repository->commit('Update changelog');
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
			'message' => "Update CHANGELOG.md for {$this->generator->getNextVersion()} release",
		]);
		$this->repository->checkoutBranch($this->repository->getBranch());
		$this->repository->deleteBranch($branchName);

		$this->generator->release();

		if ($this->subsplit->getDiscussThreadId() !== null) {
			Yii::$app->queue->push(new AnnounceReleaseOnForumJob([
				'subsplit' => $this->subsplit->getId(),
				'pullRequestNumber' => $pullRequest['number'],
				'announcement' => $this->generator->getAnnouncement(),
			]));
		} else {
			$this->githubApi->addPullRequestComment(
				$this->subsplit->getRepositoryUrl(),
				$pullRequest['number'],
				[
					'body' => $this->generateAfterMergeComment(),
				]
			);
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
		if (in_array($this->subsplit->getId(), self::AUTO_MERGE_SUBSPLITS, true)) {
			Yii::$app->queue->delay(self::AUTO_MERGE_QUEUE_DELAY)->push(new QueueMergeReleasePullRequestJob([
				'subsplit' => $this->subsplit->getId(),
				'pullRequestNumber' => $pullRequest['number'],
			]));
		}
		if (!empty($this->subsplit->getMaintainers())) {
			try {
				$this->githubApi->addPullRequestAssignees($this->subsplit->getRepositoryUrl(), $pullRequest['number'], $this->subsplit->getMaintainers());
				$this->githubApi->addPullRequestRequestedReviewers($this->subsplit->getRepositoryUrl(), $pullRequest['number'], $this->subsplit->getMaintainers());
			} catch (RuntimeException $exception) {
				// this may happen if user have not yet accepted his invitation in repository (or maintainer changed
				// his name) - treat this as soft failure and only log this error
				Yii::warning("An error occurred while assigning maintainers to PR for {$this->subsplit->getId()} language pack: {$exception->getMessage()}");
				Yii::error($exception);
			}
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
		[$userName, $repoName] = $this->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		$approvePrUrl = 'https://github.com/rob006-software/flarum-translations/wiki/How-to-approve-release-pull-request';
		return <<<MD
			This is a draft of changelog for the `{$this->generator->getNextVersion()}` release.
			Here you can find all related translations changes: [{$this->generator->getPreviousVersion()}...{$this->repository->getBranch()}](https://github.com/$userName/$repoName/compare/{$this->generator->getPreviousVersion()}...{$this->repository->getBranch()}).
			
			> ### ⚠️ Do not merge this pull request manually ⚠️
			> 
			> You should **[approve]($approvePrUrl)** this pull request instead. After approving, I will automatically update it (release date needs to be adjusted), merge, and tag a new release. You can read more about the process on [forum](https://discuss.flarum.org/d/20807-simplify-translation-process-with-weblate/76).
			
			<details>
			<summary>Show release notes preview</summary>
			
			## {$this->generator->getNextVersion()}
			
			<!-- release-notes-begin -->
			{$this->generator->getChangelogEntryContent()}
			<!-- release-notes-end -->
			
			</details>
			MD;
	}

	private function generateAfterMergeComment(): string {
		$forumUrl = $this->generator->getSubsplit()->getThreadUrl() ?? 'https://discuss.flarum.org/t/languages';
		return <<<MD
			Success! Now you can announce new release on [forum]({$forumUrl}):
			
			~~~markdown
			{$this->generator->getAnnouncement()}
			~~~
			MD;
	}
}
