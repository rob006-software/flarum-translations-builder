<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\extensions;

use app\components\GithubApi;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;

/**
 * Class IssueGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class IssueGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var GithubApi */
	private $githubApi;

	public function __construct(?GithubApi $githubApi = null) {
		$this->githubApi = $githubApi ?? Yii::$app->githubApi;
	}

	public function generateForMigration(string $oldName, string $newName): void {
		$this->githubApi->createIssueIfNotExist(
			Yii::$app->params['translationsRepository'],
			"Migrate $oldName to $newName"
		);
	}
}
