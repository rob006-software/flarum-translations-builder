<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\jobs;

use app\components\release\ReleasePullRequestGenerator;
use app\helpers\FlarumVersion;
use app\models\Translations;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use function intdiv;
use function time;

/**
 * Class QueueMergeReleasePullRequestJob.
 *
 * Marks release pull request as queued for automatic merge - maintainer has 24 hours to react before pull request
 * will be merged by `AutoMergeReleasePullRequestJob`. If maintainer removed the label, it is not added again until
 * `ReleasePullRequestGenerator::AUTO_MERGE_POSTPONE_TIME` passes - unless pull request is old enough for forced
 * automatic merge (see `ReleasePullRequestGenerator::isAutoMergeForced()`).
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class QueueMergeReleasePullRequestJob extends BaseObject implements JobInterface {

	/**
	 * Delay for auto-merge job - maintainer should have 24 hours to react on comment about planned merge. The actual
	 * deadline is enforced by `ReleasePullRequestGenerator::MIN_AUTO_MERGE_LABEL_AGE`, so this delay only needs to be
	 * a bit longer than that - otherwise the job would be executed exactly on the boundary and the label could still
	 * be considered too fresh.
	 */
	public const AUTO_MERGE_DELAY = ReleasePullRequestGenerator::MIN_AUTO_MERGE_LABEL_AGE + 60 * 60;

	private const APPROVE_PR_URL = 'https://github.com/rob006-software/flarum-translations/wiki/How-to-approve-release-pull-request';

	public $configFile = '@app/translations/config.php';
	public $subsplit;
	public $pullRequestNumber;

	public function execute($queue) {
		$config = require Yii::getAlias($this->configFile);
		$translations = new Translations(Yii::$app->params['translationsRepository'], FlarumVersion::branch(), $config);
		$subsplit = $translations->getSubsplit($this->subsplit);
		$repositoryUrl = $subsplit->getRepositoryUrl();
		$pullRequestNumber = (int) $this->pullRequestNumber;

		$pullRequest = Yii::$app->githubApi->getPullRequest($repositoryUrl, $pullRequestNumber);
		if ($pullRequest === null || $pullRequest['state'] !== 'open') {
			// pull request was already closed - nothing to do
			return;
		}

		$branchName = "release/{$subsplit->getRepository()->getBranch()}";
		if ($pullRequest['head']['ref'] !== $branchName) {
			// make sure that we're not touching pull request from other Flarum version line
			Yii::error(
				"PR #$pullRequestNumber in $repositoryUrl is not a release pull request for '$branchName' branch."
			);
			return;
		}

		if (!ReleasePullRequestGenerator::hasAutoMergeLabel($pullRequest)) {
			if (ReleasePullRequestGenerator::isAutoMergeForced($pullRequest)) {
				// removing the label no longer postpones the merge - add it again with the final notice, but only once
				$comments = Yii::$app->githubApi->getPullRequestComments($repositoryUrl, $pullRequestNumber);
				if (ReleasePullRequestGenerator::getForcedAutoMergeNoticeDate($comments) === null) {
					$this->addAutoMergeLabel($repositoryUrl, $pullRequestNumber, $this->generateForcedMergeComment());
				}
			} else {
				$labelEvents = Yii::$app->githubApi->getLabelEvents(
					$repositoryUrl,
					$pullRequestNumber,
					ReleasePullRequestGenerator::AUTO_MERGE_LABEL
				);
				$labelRemoveDate = ReleasePullRequestGenerator::getLastLabelEventDate($labelEvents, 'unlabeled');
				if ($labelRemoveDate !== null && $labelRemoveDate > time() - ReleasePullRequestGenerator::AUTO_MERGE_POSTPONE_TIME) {
					// maintainer postponed automatic merge by removing the label - it will be queued again by
					// `release/check-pull-requests` once the postpone time passes
					return;
				}
				$this->addAutoMergeLabel($repositoryUrl, $pullRequestNumber, $this->generateComment());
			}
		}

		Yii::$app->queue->delay(self::AUTO_MERGE_DELAY)->push(new AutoMergeReleasePullRequestJob([
			'configFile' => $this->configFile,
			'subsplit' => $this->subsplit,
			'pullRequestNumber' => $pullRequestNumber,
		]));
	}

	private function addAutoMergeLabel(string $repositoryUrl, int $pullRequestNumber, string $comment): void {
		Yii::$app->githubApi->addPullRequestComment($repositoryUrl, $pullRequestNumber, [
			'body' => $comment,
		]);
		Yii::$app->githubApi->addPullRequestLabels($repositoryUrl, $pullRequestNumber, [
			ReleasePullRequestGenerator::AUTO_MERGE_LABEL,
		]);
	}

	private function generateComment(): string {
		$label = ReleasePullRequestGenerator::AUTO_MERGE_LABEL;
		$approvePrUrl = self::APPROVE_PR_URL;
		$postponeDays = intdiv(ReleasePullRequestGenerator::AUTO_MERGE_POSTPONE_TIME, 24 * 60 * 60);
		$forcedMergeDays = intdiv(ReleasePullRequestGenerator::FORCED_AUTO_MERGE_PULL_REQUEST_AGE, 24 * 60 * 60);
		// @todo replace the last sentence once forced automatic merge is enabled in
		//       `ReleasePullRequestGenerator::isAutoMergeForced()`, for example:
		//       "Pull requests open for more than $forcedMergeDays days are merged regardless of this label."
		return <<<MD
			This pull request is waiting for review for a long time, so it will be merged automatically in 24 hours.
			
			If you want to release a new version right now, you can still **[approve]($approvePrUrl)** this pull request.
			If you're not ready for a new release yet, you can remove the `$label` label to postpone automatic merge.
			Keep in mind that this is only a temporary solution - the label will be added again after $postponeDays days,
			so you need to repeat it every $postponeDays days until you're ready to review this pull request.
			Soon pull requests open for more than $forcedMergeDays days will be merged regardless of this label.
			MD;
	}

	private function generateForcedMergeComment(): string {
		$label = ReleasePullRequestGenerator::AUTO_MERGE_LABEL;
		$approvePrUrl = self::APPROVE_PR_URL;
		$forcedMergeDays = intdiv(ReleasePullRequestGenerator::FORCED_AUTO_MERGE_PULL_REQUEST_AGE, 24 * 60 * 60);
		$marker = ReleasePullRequestGenerator::FORCED_AUTO_MERGE_NOTICE_MARKER;
		return <<<MD
			$marker
			This pull request is open for more than $forcedMergeDays days, so it will be merged automatically in 24 hours.
			Removing the `$label` label will not postpone automatic merge anymore.
			
			If you want to release a new version right now, you can still **[approve]($approvePrUrl)** this pull request.
			MD;
	}
}
