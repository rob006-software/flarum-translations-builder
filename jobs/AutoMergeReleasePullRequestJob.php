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
 * Class AutoMergeReleasePullRequestJob.
 *
 * Merges release pull request without maintainer approval - this is a fallback for situations when maintainer is
 * not available.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class AutoMergeReleasePullRequestJob extends BaseObject implements JobInterface {

	public $configFile = '@app/translations/config.php';
	public $subsplit;
	public $pullRequestNumber;

	public function execute($queue) {
		$config = require Yii::getAlias($this->configFile);
		$translations = new Translations(Yii::$app->params['translationsRepository'], FlarumVersion::branch(), $config);
		(new ReleasePullRequestGenerator($translations->getSubsplit($this->subsplit)))
			->autoMerge((int) $this->pullRequestNumber);
	}
}
