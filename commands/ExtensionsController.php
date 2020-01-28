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

use app\components\ConsoleController;
use app\components\extensions\PullRequestGenerator;
use app\models\ForkRepository;
use app\models\Translations;
use Yii;
use const APP_ROOT;

/**
 * Class ExtensionsController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ExtensionsController extends ConsoleController {

	public $update = true;
	public $verbose = false;
	public $useCache = false;
	/** @var int */
	public $frequency;

	public function options($actionId) {
		return array_merge(parent::options($actionId), [
			'frequency',
			'verbose',
			'useCache',
			'update',
		]);
	}

	public function actionList(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$extensions = Yii::$app->extensionsRepository->getAllExtensions($this->useCache);
		foreach ($translations->getProjects() as $project) {
			foreach ($project->getExtensionsComponents() as $component) {
				if (!isset($extensions[$component->getId()])) {
					continue;
				}
				$extension = $extensions[$component->getId()];
				echo $component->getId() . ' - '
					. "[`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})"
					. "\n";
			}
		}
	}

	public function actionDetectNew(int $limit = 2, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}

		$extensions = Yii::$app->extensionsRepository->getValidExtensions(
			$translations->getSupportedVersions(),
			$this->useCache
		);
		foreach ($translations->getProjects() as $project) {
			foreach ($project->getExtensionsComponents() as $component) {
				unset($extensions[$component->getId()]);
			}
		}

		$repository = new ForkRepository(
			Yii::$app->params['translationsForkRepository'],
			Yii::$app->params['translationsRepository'],
			null,
			APP_ROOT . '/runtime/translations-fork'
		);
		$generator = new PullRequestGenerator($repository, $translations);
		$generator->generateForNewExtensions($extensions, $limit);

		$this->updateLimit($token);
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
