<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

Yii::setAlias('@webroot', dirname(__DIR__) . '/public');
Yii::setAlias('@web', '/');

/**
 * Configuration adjustments for console application.
 */
return [
	'id' => 'app-console',
	'controllerNamespace' => 'app\commands',
];
