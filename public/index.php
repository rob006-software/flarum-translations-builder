<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use yii\helpers\ArrayHelper;
use yii\web\Application;

define('APP_ROOT', dirname(__DIR__));
const APP_CONTEXT = 'web';

require APP_ROOT . '/config/environment.php';

require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/vendor/yiisoft/yii2/Yii.php';

$config = ArrayHelper::merge(
	require APP_ROOT . '/config/main.php',
	require APP_ROOT . '/config/web.php',
	require APP_ROOT . '/config/local.php'
);

(new Application($config))->run();
