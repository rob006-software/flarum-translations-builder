<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use yii\caching\FileCache;
use yii\db\Connection;
use yii\log\FileTarget;
use yii\swiftmailer\Mailer;

error_reporting(-1);

/**
 * General configuration shared between console and web application.
 */
return [
	'basePath' => dirname(__DIR__),
	'bootstrap' => [
		'log',
	],
	'aliases' => [
		'@bower' => '@vendor/bower-asset',
		'@npm' => '@vendor/npm-asset',
	],
	'components' => [
		'cache' => [
			'class' => FileCache::class,
		],
		'mailer' => [
			'class' => Mailer::class,
			// send all mails to a file by default. You have to set
			// 'useFileTransport' to false and configure a transport
			// for the mailer to send real emails.
			'useFileTransport' => true,
		],
		'log' => [
			'targets' => [
				[
					'class' => FileTarget::class,
					'levels' => ['error', 'warning'],
					'except' => [
						'yii\web\HttpException:*',
					],
				],
				[
					'class' => FileTarget::class,
					'levels' => ['error', 'warning'],
					'logFile' => '@app/runtime/logs/http.log',
					'categories' => [
						'yii\web\HttpException:*',
					],
				],
			],
		],
		'db' => [
			'class' => Connection::class,
			'dsn' => 'sqlite:@app/runtime/database.db',
			'charset' => 'utf8',
			'enableSchemaCache' => true,
			'attributes' => [
				PDO::ATTR_TIMEOUT => 60,
			],
		],
		'urlManager' => [
			'enablePrettyUrl' => true,
			'showScriptName' => false,
			'rules' => [
			],
		],
	],
	'params' => [
		'repository' => 'git@github.com:rob006-software/flarum-translations-builder.git',
		'translationsRepository' => 'git@github.com:rob006-software/flarum-translations.git',
	],
];
