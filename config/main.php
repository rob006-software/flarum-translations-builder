<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use app\components\extensions\ExtensionsRepository;
use app\components\GithubApi;
use yii\caching\FileCache;
use yii\db\Connection;
use yii\log\FileTarget;
use yii\mutex\FileMutex;
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
					'maxFileSize' => 1024,
					'maxLogFiles' => 3,
					'logVars' => [],
					'levels' => ['warning'],
					'except' => [
						'yii\web\HttpException:*',
					],
				],
				[
					'class' => FileTarget::class,
					'maxFileSize' => 1024,
					'maxLogFiles' => 3,
					'logFile' => '@runtime/logs/error.log',
					'levels' => ['error'],
					'except' => [
						'yii\web\HttpException:*',
					],
				],
				[
					'class' => FileTarget::class,
					'maxFileSize' => 1024,
					'maxLogFiles' => 3,
					'logVars' => [],
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
		'mutex' => [
			'class' => FileMutex::class,
		],
		'extensionsRepository' => [
			'class' => ExtensionsRepository::class,
		],
		'githubApi' => [
			'class' => GithubApi::class,
		],
	],
	'params' => [
		'repository' => 'git@github.com:rob006-software/flarum-translations-builder.git',
		'translationsRepository' => 'git@github.com:rob006-software/flarum-translations.git',
		'translationsForkRepository' => 'git@github.com:robbot006/flarum-translations.git',
	],
];
