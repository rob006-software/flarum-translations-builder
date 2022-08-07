<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/* @noinspection PhpIllegalPsrClassPathInspection */
/* @noinspection PhpMultipleClassesDeclarationsInOneFile */
/* @noinspection PhpFullyQualifiedNameUsageInspection */

declare(strict_types=1);

use app\components\extensions\ExtensionsRepository;
use app\components\extensions\StatsRepository;
use app\components\ExtiverseApi;
use app\components\FrequencyLimiter;
use app\components\GithubApi;
use app\components\GitlabApi;
use app\components\locks\Locker;
use app\components\weblate\WeblateApi;
use yii\caching\ArrayCache;
use yii\mutex\FileMutex;

/**
 * Fake class to define code completion for IDE.
 *
 * Define your components as properties of this class to get code completion for `Yii::$app->`.
 *
 * @author Robert Korulczyk
 */
class Yii extends \yii\BaseYii {

	/**
	 * @var BaseApplication|WebApplication|ConsoleApplication the application instance
	 */
	public static $app;
}

abstract class BaseApplication extends \yii\base\Application {

	/** @var ExtensionsRepository */
	public $extensionsRepository;
	/** @var GithubApi */
	public $githubApi;
	/** @var GitlabApi */
	public $gitlabApi;
	/** @var ExtiverseApi */
	public $extiverseApi;
	/** @var WeblateApi */
	public $weblateApi;
	/** @var FileMutex */
	public $mutex;
	/** @var FrequencyLimiter */
	public $frequencyLimiter;
	/** @var ArrayCache */
	public $arrayCache;
	/** @var StatsRepository */
	public $stats;
	/** @var Locker */
	public $locks;
}

class WebApplication extends \yii\web\Application {

}

class ConsoleApplication extends \yii\console\Application {

}
