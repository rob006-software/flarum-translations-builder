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

use app\helpers\FlarumVersion;
use app\models\Translations;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Class AnnounceReleaseOnForumJob.
 *
 * Publishes release announcement on Flarum forum and reports it in release pull request.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class AnnounceReleaseOnForumJob extends BaseObject implements JobInterface {

	public $configFile = '@app/translations/config.php';
	public $subsplit;
	public $pullRequestNumber;
	public $announcement;

	public function execute($queue) {
		$config = require Yii::getAlias($this->configFile);
		$translations = new Translations(Yii::$app->params['translationsRepository'], FlarumVersion::branch(), $config);
		$subsplit = $translations->getSubsplit($this->subsplit);
		$discussThreadId = $subsplit->getDiscussThreadId();
		if ($discussThreadId === null) {
			Yii::warning("Subsplit '{$this->subsplit}' does not have discussion thread configured.");
			return;
		}

		$post = Yii::$app->flarumApi->createPost($discussThreadId, $this->announcement);
		$postUrl = Yii::$app->flarumApi->getPostUrl($discussThreadId, (int) $post['attributes']['number']);

		Yii::$app->githubApi->addPullRequestComment(
			$subsplit->getRepositoryUrl(),
			(int) $this->pullRequestNumber,
			[
				'body' => "Success! New release was announced on [forum]($postUrl).",
			]
		);
	}
}
