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

/**
 * Class QueueMergeReleasePullRequestJob.
 *
 * Marks release pull request as queued for automatic merge - maintainer has 24 hours to react before pull request
 * will be merged by `AutoMergeReleasePullRequestJob`.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class QueueMergeReleasePullRequestJob extends BaseObject implements JobInterface {

	/** Delay for auto-merge job - maintainer should have about 24 hours to react on comment about planned merge. */
	public const AUTO_MERGE_DELAY = 23 * 60 * 60;

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
			Yii::warning(
				"PR #$pullRequestNumber in $repositoryUrl is not a release pull request for '$branchName' branch."
			);
			return;
		}

		if (!ReleasePullRequestGenerator::hasAutoMergeLabel($pullRequest)) {
			Yii::$app->githubApi->addPullRequestComment($repositoryUrl, $pullRequestNumber, [
				'body' => $this->generateComment(),
			]);
			Yii::$app->githubApi->addPullRequestLabels($repositoryUrl, $pullRequestNumber, [
				ReleasePullRequestGenerator::AUTO_MERGE_LABEL,
			]);
		}

		Yii::$app->queue->delay(self::AUTO_MERGE_DELAY)->push(new AutoMergeReleasePullRequestJob([
			'configFile' => $this->configFile,
			'subsplit' => $this->subsplit,
			'pullRequestNumber' => $pullRequestNumber,
		]));
	}

	private function generateComment(): string {
		$label = ReleasePullRequestGenerator::AUTO_MERGE_LABEL;
		$approvePrUrl = 'https://github.com/rob006-software/flarum-translations/wiki/How-to-approve-release-pull-request';
		return <<<MD
			This pull request is waiting for review for a long time, so it will be merged automatically in 24 hours.
			
			If you want to release a new version right now, you can still **[approve]($approvePrUrl)** this pull request.
			If you're not ready for a new release yet, you can remove the `$label` label to postpone automatic merge.
			Keep in mind that this is only a temporary solution - the label will be added again on the next day,
			so you need to repeat it every day until you're ready to review this pull request.
			MD;
	}
}
