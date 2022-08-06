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
use app\models\Translations;
use Yii;
use yii\console\ExitCode;
use function array_merge;

/**
 * Class ReleaseController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ReleaseController extends ConsoleController {

	public $defaultAction = 'release';

	public $update = true;
	public $verbose = false;
	public $draft = false;
	/** @var string */
	public $previousVersion = '';
	/** @var string */
	public $nextVersion = '';

	public function options($actionID): array {
		return array_merge(parent::options($actionID), [
			'update',
			'verbose',
			'draft',
			'previousVersion',
			'nextVersion',
		]);
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

		echo $generator->generateChangelog();
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

	private function getTranslations(string $configFile): Translations {
		$translations = new Translations(
			Yii::$app->params['translationsRepository'],
			null,
			require Yii::getAlias($configFile)
		);
		if ($this->update) {
			$output = $translations->getRepository()->update();
			if ($this->verbose) {
				echo $output;
			}
		}

		return $translations;
	}
}
