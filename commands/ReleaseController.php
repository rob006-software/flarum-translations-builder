<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\commands;

use app\components\ConsoleController;
use app\components\release\ReleasePullRequestGenerator;
use yii\console\ExitCode;
use function array_merge;
use function file_put_contents;

/**
 * Class ReleaseController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ReleaseController extends ConsoleController {

	public $defaultAction = 'release';

	public $draft = false;
	/** @var string */
	public $previousVersion = '';
	/** @var string */
	public $nextVersion = '';

	public function options($actionID): array {
		$options = array_merge(parent::options($actionID), [
			'update',
			'verbose',
			'draft',
			'previousVersion',
			'nextVersion',
		]);
		if (in_array($actionID, ['pr', 'release'], true)) {
			$options = array_merge(parent::options($actionID), [
				'previousVersion',
				'nextVersion',
			]);
		}
		if ($actionID === 'release') {
			$options = array_merge(parent::options($actionID), [
				'draft',
			]);
		}

		return $options;
	}

	public function actionPr(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$prGenerator = (new ReleasePullRequestGenerator($translations->getSubsplit($subsplit)));
		if ($this->previousVersion !== '') {
			$prGenerator->getGenerator()->setPreviousVersion($this->previousVersion);
		}
		if ($this->nextVersion !== '') {
			$prGenerator->getGenerator()->setNextVersion($this->nextVersion);
		}
		$prGenerator->generate();
	}

	public function actionMerge(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		(new ReleasePullRequestGenerator($translations->getSubsplit($subsplit)))->merge();
	}

	public function actionRelease(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$generator = $translations->getSubsplit($subsplit)->createReleaseGenerator();
		if ($this->previousVersion !== '') {
			$generator->setPreviousVersion($this->previousVersion);
		}
		if ($this->nextVersion !== '') {
			$generator->setNextVersion($this->nextVersion);
		}

		$newChangelog = $generator->generateChangelog();
		file_put_contents($generator->getChangelogPath(), $newChangelog);
		echo $generator->getRepository()->getDiff();
		if (!$this->confirm('OK?')) {
			return ExitCode::UNSPECIFIED_ERROR;
		}
		$output = $generator->commit();
		if ($this->verbose) {
			echo $output;
		}

		if ($this->confirm('Generate release?', true)) {
			$generator->release($this->draft);
		}

		echo $generator->getAnnouncement();
	}
}
