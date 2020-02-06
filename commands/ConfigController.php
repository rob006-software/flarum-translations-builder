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
use app\components\extensions\ConfigGenerator;
use app\models\Repository;
use app\models\Translations;
use Yii;
use function array_merge;
use function in_array;
use function time;

/**
 * Class ConfigController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ConfigController extends ConsoleController {

	public $defaultAction = 'update';

	public $update = true;
	public $commit = true;
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
			'update',
		]);
	}

	public function actionUpdate(array $projects = [], string $configFile = '@app/translations/config.php') {
		if ($this->isLimited(__METHOD__)) {
			return;
		}
		$translations = $this->getTranslations($configFile);

		foreach ($translations->getProjects() as $project) {
			if (empty($projects) || in_array($project->getId(), $projects, true)) {
				$configGenerator = new ConfigGenerator(
					$translations->getDir() . '/config/' . $project->getId() . '-project.php'
				);

				foreach ($project->getExtensionsComponents() as $component) {
					$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
					if ($extension === null) {
						Yii::warning("Unable to update {$component->getId()} extension.", __METHOD__);
						continue;
					}

					$configGenerator->updateExtension($extension);
					$this->commitRepository(
						$translations->getRepository(),
						"Update config for {$component->getId()}.\n\n{$extension->getTagsUrl()}"
					);
				}
			}
		}

		$this->pushRepository($translations->getRepository());
		$this->updateLimit(__METHOD__);
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

	private function commitRepository(Repository $repository, string $commitMessage): void {
		if ($this->commit || $this->push) {
			$output = $repository->commit($commitMessage);
			if ($this->verbose) {
				echo $output;
			}
		}
	}

	private function pushRepository(Repository $repository): void {
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
