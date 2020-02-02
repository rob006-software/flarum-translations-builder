<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\commands;

use app\models\Extension;
use app\models\ForkRepository;
use app\models\Translations;
use Yii;
use yii\console\Controller;
use yii\helpers\FileHelper;
use function array_combine;
use function array_filter;
use function array_merge;
use function filemtime;
use function strtotime;
use function unlink;

/**
 * Class JanitorController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class JanitorController extends Controller {

	public $useCache = false;

	public function options($actionId) {
		if ($actionId === 'logs') {
			return parent::options($actionId);
		}

		return array_merge(parent::options($actionId), [
			'useCache',
		]);
	}

	public function actionBranches(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$extensions = Yii::$app->extensionsRepository->getValidExtensions(
			$translations->getSupportedVersions(),
			$this->useCache
		);
		$extensions = array_filter($extensions, static function (Extension $extension) {
			return $extension->hasTranslationSource();
		});

		$repository = new ForkRepository(
			Yii::$app->params['translationsForkRepository'],
			Yii::$app->params['translationsRepository'],
			null,
			APP_ROOT . '/runtime/translations-fork'
		);
		$repository->rebase();
		$repository->syncBranchesWithRemote();

		$branches = array_filter($repository->getBranches(), static function ($name) {
			return strncmp($name, 'new/', 4) === 0;
		});
		$orphanedBranches = array_combine($branches, $branches);
		foreach ($extensions as $extension) {
			unset($orphanedBranches["new/{$extension->getId()}"]);
		}

		if (empty($orphanedBranches)) {
			echo "No unnecessary branches found.\n";
		} else {
			foreach ($orphanedBranches as $branch) {
				echo $branch, "\n";
			}
		}
	}

	public function actionComponents(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$extensions = Yii::$app->extensionsRepository->getValidExtensions(
			$translations->getSupportedVersions(),
			$this->useCache
		);
		$extensions = array_filter($extensions, static function (Extension $extension) {
			return $extension->hasTranslationSource();
		});

		$found = false;
		foreach ($translations->getProjects() as $project) {
			foreach ($project->getExtensionsComponents() as $component) {
				if (!isset($extensions[$component->getId()])) {
					$found = true;
					echo $component->getId(), "\n";
				}
			}
		}

		if (!$found) {
			echo "No outdated components found.\n";
		}
	}

	public function actionLogs() {
		$files = FileHelper::findFiles(APP_ROOT . '/runtime/git-logs');
		foreach ($files as $file) {
			if (filemtime($file) < strtotime('-1 month')) {
				unlink($file);
			}
		}
	}

	private function getTranslations(string $configFile): Translations {
		$translations = new Translations(
			Yii::$app->params['translationsRepository'],
			null,
			require Yii::getAlias($configFile)
		);
		$translations->getRepository()->update();

		return $translations;
	}
}
