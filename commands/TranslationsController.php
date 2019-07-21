<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\commands;

use app\models\Repository;
use app\models\Translations;
use Yii;
use yii\console\Controller;

/**
 * Class TranslationsController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class TranslationsController extends Controller {

	public $defaultAction = 'update';

	public $commit = false;
	public $push = false;
	public $verbose = false;

	public function options($actionId) {
		return array_merge(parent::options($actionId), [
			'commit',
			'push',
			'verbose',
		]);
	}

	public function actionUpdate(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getProjects() as $project) {
			$catalogue = $project->updateSources();
			foreach ($project->getLanguages() as $language) {
				$project->updateComponents($language, $catalogue);
			}
		}

		$this->postProcessRepository($translations->getRepository(), 'Update sources from extensions.');
	}

	public function actionSplit(?array $subsplits = null, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getSubsplits() as $subsplit) {
			if ($subsplits === null || in_array($subsplit->getLanguage(), $subsplits, true)) {
				$subsplit->splitProjects($translations->getProjects());
			}
			$this->postProcessRepository($subsplit->getRepository(), 'Sync translations with main repository.');
		}
	}

	private function getTranslations(string $configFile): Translations {
		$translations = new Translations(require Yii::getAlias($configFile));
		$output = $translations->getRepository()->update();
		if ($this->verbose) {
			echo $output;
		}

		return $translations;
	}

	private function postProcessRepository(Repository $repository, string $commitMessage): void {
		if ($this->commit || $this->push) {
			$output = $repository->commit($commitMessage);
			if ($this->verbose) {
				echo $output;
			}
		}
		if ($this->push) {
			$output = $repository->push();
			if ($this->verbose) {
				echo $output;
			}
		}
	}
}
