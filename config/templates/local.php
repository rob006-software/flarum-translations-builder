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

/**
 * Adjustments of configuration for local installation of application.
 */
$localConfig = [];

$localConfig = ArrayHelper::merge($localConfig, [
	'components' => [
		'githubApi' => [
			'authToken' => null, // @todo fill me
		],
	],
]);

if (APP_CONTEXT === 'web') {
	$localConfig = ArrayHelper::merge($localConfig, [
		'components' => [
			'request' => [
				// !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
				'cookieValidationKey' => '',
			],
		],
	]);
}

if (APP_CONTEXT === 'console') {
	$localConfig = ArrayHelper::merge($localConfig, [
		'components' => [
		],
	]);
}

return $localConfig;
