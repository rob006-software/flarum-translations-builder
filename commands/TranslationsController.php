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

use app\components\translations\TranslationsImporter;
use app\models\Repository;
use app\models\Translations;
use mindplay\readable;
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
	/** @var int */
	public $frequency;

	public function options($actionId) {
		return array_merge(parent::options($actionId), [
			'commit',
			'push',
			'verbose',
			'frequency',
		]);
	}

	public function actionUpdate(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}
		foreach ($translations->getProjects() as $project) {
			$catalogue = $project->updateSources();
			foreach ($project->getLanguages() as $language) {
				$project->updateComponents($language, $catalogue);
			}
		}

		$this->postProcessRepository($translations->getRepository(), 'Update sources from extensions.');
		$this->updateLimit($token);
	}

	public function actionSplit(?array $subsplits = null, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getTranslationsHash();
		if ($this->isLimited($token)) {
			return;
		}
		foreach ($translations->getSubsplits() as $subsplit) {
			if ($subsplits === null || in_array($subsplit->getId(), $subsplits, true)) {
				$subsplit->split($translations);
				$this->postProcessRepository($subsplit->getRepository(), 'Sync translations with main repository.');
			}
		}
		$this->updateLimit($token);
	}

	public function actionImport(string $source, string $project, string $component, string $language, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$importer = new TranslationsImporter(
			$translations->getProject($project),
			$translations->getProject($project)->getComponent($component)
		);
		$importer->import(Yii::getAlias($source), $language);

		$this->postProcessRepository(
			$translations->getRepository(),
			strtr('Importing {component} component in {project} project from {source}.', [
				'{component}' => readable::value($component),
				'{project}' => readable::value($project),
				'{source}' => readable::value($source),
			])
		);
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

	private function isLimited(string $hash): bool {
		if ($this->frequency <= 0) {
			return false;
		}

		$lastRun = Yii::$app->cache->get($hash);
		if ($lastRun > 0) {
			return time() - $lastRun < $this->frequency;
		}

		return false;
	}

	private function updateLimit(string $hash): void {
		Yii::$app->cache->set($hash, time(), 31 * 24 * 60 * 60);
	}
}
