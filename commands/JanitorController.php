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
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use function array_combine;
use function array_filter;
use function array_flip;
use function array_merge;
use function file_exists;
use function filemtime;
use function rename;
use function strtotime;
use function unlink;

/**
 * Class JanitorController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class JanitorController extends Controller {

	public $update = true;
	public $verbose = false;
	public $useCache = false;

	public function options($actionID) {
		if ($actionID === 'logs') {
			return parent::options($actionID);
		}

		return array_merge(parent::options($actionID), [
			'useCache', 'update', 'verbose',
		]);
	}

	public function actionBranches(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$components = $translations->getExtensionsComponents();
		$extensions = Yii::$app->extensionsRepository->getValidExtensions(
			$translations->getSupportedVersions(),
			$translations->getUnsupportedVersions(),
			$translations->getIgnoredExtensions(),
			$this->useCache
		);
		$extensions = array_filter($extensions, static function (Extension $extension) use ($components) {
			return !isset($components[$extension->getId()]) && $extension->hasTranslationSource();
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
			$translations->getUnsupportedVersions(),
			$translations->getIgnoredExtensions(),
			$this->useCache
		);
		$extensions = array_filter($extensions, static function (Extension $extension) {
			return $extension->hasTranslationSource();
		});

		$found = false;
		foreach ($translations->getExtensionsComponents() as $component) {
			if (!isset($extensions[$component->getId()])) {
				$found = true;
				$extension = Yii::$app->extensionsRepository->getExtension($component->getId(), $this->useCache);
				assert($extension instanceof Extension);
				$outdated = $extension->isOutdated(
					$translations->getSupportedVersions(),
					$translations->getUnsupportedVersions()
				);
				$outdatedIcon = $outdated === null ? '?' : '!';
				echo " - $outdatedIcon [`{$component->getId()}`]({$extension->getRepositoryUrl()})"
					. " - [`{$extension->getPackageName()}`](https://packagist.org/packages/{$extension->getPackageName()})"
					. "\n";
			}
		}

		if (!$found) {
			echo "No outdated components found.\n";
		}
	}

	public function actionRemoveExtension(string $extensionId, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$configGenerator = new ConfigGenerator($translations->getDir() . '/config/components.php');
		$configGenerator->removeExtension($extensionId);

		$sourcePath = $translations->getComponentSourcePath($extensionId);
		if (file_exists($sourcePath)) {
			unlink($sourcePath);
			echo "Removed $sourcePath source.\n";
		}

		foreach ($translations->getLanguages() as $language) {
			$translationPath = $translations->getComponentTranslationPath($extensionId, $language);
			if (file_exists($translationPath)) {
				unlink($translationPath);
				echo "Removed $sourcePath translation.\n";
			}
		}
	}

	public function actionMigrateExtension(
		string $oldExtensionId,
		string $newExtensionId,
		string $configFile = '@app/translations/config.php'
	) {
		$translations = $this->getTranslations($configFile);
		$extension = Yii::$app->extensionsRepository->getExtension($newExtensionId, false);
		if ($extension === null) {
			throw new InvalidConfigException("Invalid extension: $newExtensionId.");
		}

		$configGenerator = new ConfigGenerator($translations->getDir() . '/config/components.php');
		$configGenerator->removeExtension($oldExtensionId);
		$configGenerator->updateExtension($extension);

		$oldSourcePath = $translations->getComponentSourcePath($oldExtensionId);
		$newSourcePath = $translations->getComponentSourcePath($newExtensionId);
		if (file_exists($oldSourcePath)) {
			rename($oldSourcePath, $newSourcePath);
			echo "Moved $oldSourcePath source to $newSourcePath.\n";
		} else {
			echo Console::renderColoredString("Translation file not found at $oldSourcePath.", Console::BG_RED), "\n";
		}

		foreach ($translations->getLanguages() as $language) {
			$oldTranslationPath = $translations->getComponentTranslationPath($oldExtensionId, $language);
			$newTranslationPath = $translations->getComponentTranslationPath($newExtensionId, $language);
			if (file_exists($oldTranslationPath)) {
				rename($oldTranslationPath, $newTranslationPath);
				echo "Moved $oldTranslationPath translation to $newTranslationPath.\n";
			} else {
				echo Console::renderColoredString("Translation file not found at $oldTranslationPath.", Console::BG_RED), "\n";
			}
		}
	}

	public function actionOrphans(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$expectedFiles = [];
		foreach ($translations->getComponents() as $component) {
			$expectedFiles[] = $translations->getComponentSourcePath($component->getId());
			foreach ($component->getLanguages() as $language) {
				$expectedFiles[] = $translations->getComponentTranslationPath($component->getId(), $language);
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

	public function actionCleanupTranslations(array $languages, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($languages as $language) {
			$translations->cleanupComponents($language);
		}
	}

	public function actionCleanupSubsplit(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$subsplitObject = $translations->getSubsplit($subsplit);
		$dir = $subsplitObject->getDir() . $subsplitObject->getPath();
		foreach (FileHelper::findFiles($dir, ['only' => ['/*.yml']]) as $file) {
			unlink($file);
		}

		$subsplitObject->split($translations);
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
		if ($this->update) {
			$output = $translations->getRepository()->update();
			if ($this->verbose) {
				echo $output;
			}
		}

		return $translations;
	}
}
