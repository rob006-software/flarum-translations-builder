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

use app\components\extensions\ConfigGenerator;
use app\models\Extension;
use app\models\ForkRepository;
use app\models\Translations;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;
use function array_combine;
use function array_filter;
use function array_flip;
use function array_merge;
use function file_exists;
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

	public function actionRemoveExtension(string $extensionsId, string $projectId, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$configGenerator = new ConfigGenerator(
			$translations->getDir() . '/config/' . $projectId . '-project.php'
		);
		$configGenerator->removeExtension($extensionsId);

		$sourcePath = $translations->getProject($projectId)->getComponentSourcePath($extensionsId);
		if (file_exists($sourcePath)) {
			unlink($sourcePath);
		}

		foreach ($translations->getLanguages() as $language) {
			$translationPath = $translations->getProject($projectId)->getComponentTranslationPath($extensionsId, $language);
			if (file_exists($translationPath)) {
				unlink($translationPath);
			}
		}
	}

	public function actionOrphans(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$expectedFiles = [];
		foreach ($translations->getProjects() as $project) {
			foreach ($project->getComponents() as $component) {
				$expectedFiles[] = $translations->getProject($project->getId())->getComponentSourcePath($component->getId());
				foreach ($component->getLanguages() as $language) {
					$expectedFiles[] = $translations->getProject($project->getId())->getComponentTranslationPath($component->getId(), $language);
				}
			}
		}

		$existingFile = array_merge(
			FileHelper::findFiles($translations->getSourcesDir()),
			FileHelper::findFiles($translations->getTranslationsDir())
		);

		$expectedFilesMap = array_flip($expectedFiles);
		$orphans = [];
		foreach ($existingFile as $file) {
			if (!isset($expectedFilesMap[$file])) {
				$orphans[] = $file;
			}
		}

		if (empty($orphans)) {
			echo "No orphans found.\n";
			return ExitCode::OK;
		}

		echo count($orphans), " orphans found:\n", implode("\n", $orphans), "\n";
		if ($this->confirm('Remove?')) {
			foreach ($orphans as $orphan) {
				unlink($orphan);
				echo "$orphan removed.\n";
			}
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
