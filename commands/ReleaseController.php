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

namespace app\commands;

use app\components\ConsoleController;
use app\components\release\ReleasePullRequestGenerator;
use app\jobs\QueueMergeReleasePullRequestJob;
use app\models\Subsplit;
use Throwable;
use Yii;
use yii\helpers\Console;
use function array_merge;
use function in_array;
use function strtotime;
use function time;

/**
 * Class ReleaseController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ReleaseController extends ConsoleController {

	/** Age of the pull request which qualifies it for automatic merge. */
	private const STALE_PULL_REQUEST_AGE = 7 * 24 * 60 * 60;
	/**
	 * Time which needs to pass since closing pull request (or since last commit in branch without any pull request)
	 * before we can delete release branch. This protects us against race condition with concurrent process which may
	 * be merging pull request or creating a new one at the same time.
	 */
	private const BRANCH_REMOVAL_GRACE_PERIOD = 60 * 60;

	/** @var string */
	public $previousVersion = '';
	/** @var string */
	public $nextVersion = '';
	/** @var bool */
	public $dryRun = false;

	public function options($actionID): array {
		$options = array_merge(parent::options($actionID), [
			'update',
			'verbose',
			'previousVersion',
			'nextVersion',
		]);
		if ($actionID === 'pr') {
			$options = array_merge(parent::options($actionID), [
				'previousVersion',
				'nextVersion',
			]);
		}
		if ($actionID === 'check-pull-requests') {
			$options = array_merge(parent::options($actionID), [
				'update',
				'verbose',
				'dryRun',
			]);
		}

		return $options;
	}

	public function actionPr(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$prGenerator = (new ReleasePullRequestGenerator($translations->getSubsplit($subsplit)));
		if ($this->previousVersion !== '') {
			$prGenerator->getGenerator()->setPreviousVersion($this->previousVersion);
		}
		if ($this->nextVersion !== '') {
			$prGenerator->getGenerator()->setNextVersion($this->nextVersion);
		}
		$prGenerator->generate();
	}

	public function actionMerge(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		(new ReleasePullRequestGenerator($translations->getSubsplit($subsplit)))->merge();
	}

	/**
	 * Fallback for lost jobs - verifies state of release branches and pull requests for all subsplits. Orphaned
	 * release branches are removed and stale pull requests are queued for automatic merge.
	 */
	public function actionCheckPullRequests(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		if (empty($subsplits)) {
			$subsplits = $translations->getSubsplits();
		} else {
			foreach ($subsplits as $key => $subsplitId) {
				$subsplits[$key] = $translations->getSubsplit($subsplitId);
			}
		}

		foreach ($subsplits as $subsplit) {
			if (!$subsplit->hasReleaseGenerator()) {
				continue;
			}
			// @todo automatic merge is tested only on single language pack for now
			if (!in_array($subsplit->getId(), ReleasePullRequestGenerator::AUTO_MERGE_SUBSPLITS, true)) {
				$this->log($subsplit, 'automatic merge is not enabled for this subsplit - skip.');
				continue;
			}

			try {
				// acquire repository lock outside of try/finally below - there is nothing to release if this fails
				$repository = $subsplit->getRepository();
			} catch (Throwable $exception) {
				$this->reportError($subsplit, $exception);
				continue;
			}

			try {
				$this->checkSubsplitPullRequest($subsplit);
			} catch (Throwable $exception) {
				$this->reportError($subsplit, $exception);
			} finally {
				Yii::$app->locks->releaseRepoLock($repository->getPath());
			}
		}
	}

	private function checkSubsplitPullRequest(Subsplit $subsplit): void {
		$repository = $subsplit->getRepository();
		// use only branch for current Flarum version line, to avoid touching branches from the other one
		$branchName = "release/{$repository->getBranch()}";
		// make sure that repository is in clean state and that we have fresh info about remote branches
		$repository->update();
		if (!$repository->hasBranch("remotes/origin/$branchName", false)) {
			$this->log($subsplit, "there is no $branchName branch - skip.");
			return;
		}

		$pullRequest = Yii::$app->githubApi->getPullRequestForBranch(
			$subsplit->getRepositoryUrl(),
			$subsplit->getRepositoryUrl(),
			$branchName
		);

		if ($pullRequest === null) {
			// there was never any pull request for this branch - it may be a leftover from failed release process,
			// but it also may be a branch which was just created by concurrent process
			$lastCommitDate = $repository->getLastCommitDate("origin/$branchName");
			if ($lastCommitDate > time() - self::BRANCH_REMOVAL_GRACE_PERIOD) {
				$this->log($subsplit, "$branchName branch has no pull request, but it was created recently - skip.");
				return;
			}

			$this->deleteBranch($subsplit, $branchName, 'there is no pull request for this branch');
			return;
		}

		if ($pullRequest['state'] !== 'open') {
			// pull request could be merged by concurrent process right now - give it some time to clean up branch
			$closedAt = strtotime($pullRequest['closed_at'] ?? '');
			if ($closedAt === false || $closedAt > time() - self::BRANCH_REMOVAL_GRACE_PERIOD) {
				$this->log($subsplit, "PR #{$pullRequest['number']} for $branchName branch was closed recently - skip.");
				return;
			}

			$this->deleteBranch($subsplit, $branchName, "PR #{$pullRequest['number']} for this branch is already closed");
			return;
		}

		$createdAt = strtotime($pullRequest['created_at']);
		if ($createdAt === false || $createdAt > time() - self::STALE_PULL_REQUEST_AGE) {
			$this->log($subsplit, "PR #{$pullRequest['number']} is not old enough for automatic merge - skip.");
			return;
		}

		if ($this->dryRun) {
			echo "{$subsplit->getId()}: PR #{$pullRequest['number']} would be queued for automatic merge.\n";
			return;
		}

		Yii::$app->queue->push(new QueueMergeReleasePullRequestJob([
			'subsplit' => $subsplit->getId(),
			'pullRequestNumber' => $pullRequest['number'],
		]));
		echo "{$subsplit->getId()}: PR #{$pullRequest['number']} queued for automatic merge.\n";
	}

	private function deleteBranch(Subsplit $subsplit, string $branchName, string $reason): void {
		if ($this->dryRun) {
			echo "{$subsplit->getId()}: $branchName branch would be deleted - $reason.\n";
			return;
		}

		$repository = $subsplit->getRepository();
		// local branch is required to delete it from remote
		$repository->syncBranchesWithRemote();
		if ($repository->getCurrentBranch() === $branchName) {
			$repository->checkoutBranch($repository->getBranch());
		}
		$repository->deleteBranch($branchName);
		echo "{$subsplit->getId()}: $branchName branch deleted - $reason.\n";
	}

	private function reportError(Subsplit $subsplit, Throwable $exception): void {
		Yii::warning("An error occurred while checking release pull request for {$subsplit->getId()} subsplit: {$exception->getMessage()}");
		Yii::error($exception);
		echo Console::renderColoredString("%r{$subsplit->getId()}: {$exception->getMessage()}%n"), "\n";
	}

	private function log(Subsplit $subsplit, string $message): void {
		if ($this->verbose) {
			echo "{$subsplit->getId()}: $message\n";
		}
	}
}
