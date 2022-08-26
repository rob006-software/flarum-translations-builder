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

namespace app\jobs;

use app\components\release\ReleasePullRequestGenerator;
use app\models\Translations;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Class MergeSubsplitReleasePullRequestJob.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class MergeReleasePullRequestJob extends BaseObject implements JobInterface {

	public $configFile = '@app/translations/config.php';
	public $subsplit;

	public function execute($queue) {
		$config = require Yii::getAlias($this->configFile);
		$translations = new Translations(Yii::$app->params['translationsRepository'], null, $config);
		(new ReleasePullRequestGenerator($translations->getSubsplit($this->subsplit)))->merge();
	}
}
