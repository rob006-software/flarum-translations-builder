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

use app\components\ConsoleController;
use app\components\extensions\ConfigGenerator;
use app\components\extensions\exceptions\SoftFailureInterface;
use app\components\extensions\exceptions\UnprocessableExtensionExceptionInterface;
use app\models\Extension;
use app\models\ForkRepository;
use app\models\PremiumExtension;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use function array_combine;
use function array_filter;
use function array_flip;
use function array_merge;
use function file_exists;
use function filemtime;
use function in_array;
use function rename;
use function strtolower;
use function strtotime;
use function unlink;

/**
 * Class JanitorController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class JanitorController extends ConsoleController {

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
		$extensions = Yii::$app->extensionsRepository->getValidExtensions($this->useCache);
		$extensions = array_filter($extensions, static function (Extension $extension) use ($components) {
			try {
				return !isset($components[$extension->getId()]) && $extension->hasTranslationSource();
			} catch (UnprocessableExtensionExceptionInterface $exception) {
				if (!$exception instanceof SoftFailureInterface) {
					Yii::warning($exception->getMessage());
				}
				return false;
			}
		});

		Yii::$app->locks->acquireRepoLock(APP_ROOT . '/runtime/translations-fork');
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

		$extensions = Yii::$app->extensionsRepository->getValidExtensions($this->useCache);
		$extensions = array_filter($extensions, static function (Extension $extension) {
			try {
				return $extension->hasTranslationSource();
			} catch (UnprocessableExtensionExceptionInterface $exception) {
				if (!$exception instanceof SoftFailureInterface) {
					Yii::warning($exception->getMessage());
				}
				return false;
			}
		});

		$found = false;
		foreach ($translations->getExtensionsComponents() as $component) {
			if (!isset($extensions[$component->getId()])) {
				$found = true;
				$extension = Yii::$app->extensionsRepository->getExtension($component->getId(), $this->useCache);
				assert($extension instanceof Extension);
				if ($extension->isAbandoned()) {
					$outdatedIcon = 'X';
				} else {
					$outdated = $extension->isOutdated();
					$outdatedIcon = $outdated === null ? '?' : '!';
				}
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

	public function actionRedundantTranslations(array $languages = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$alternativeLanguagesGenerator = static function (string $language) {
			yield $language;

			if (strpos($language, '_')) {
				yield strtr($language, ['_' => '-']);

				$parts = explode('_', $language, 2);
				$newLanguage = $parts[0] . '_' . strtolower($parts[1]);
				if ($language !== $newLanguage) {
					yield $newLanguage;
					yield strtr($newLanguage, ['_' => '-']);
				}
			}
		};

		foreach ($translations->getExtensionsComponents() as $component) {
			$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
			foreach ($component->getSources() as $source) {
				foreach ($translations->getLanguages() as $language) {
					if (
						(!empty($languages) && !in_array($language, $languages, true))
						|| $extension instanceof PremiumExtension
						|| $extension->isOutdated() !== false
					) {
						continue;
					}

					if ($component->isValidForLanguage($language)) {
						foreach ($alternativeLanguagesGenerator($language) as $alternativeLanguage) {
							$url = strtr($source, ['/en.' => "/$alternativeLanguage."]);
							if (Yii::$app->extensionsRepository->testSourceUrl($url)) {
								echo "{$component->getId()} - $language: $url\n";
							}
						}
					} else {
						$exists = false;
						foreach ($alternativeLanguagesGenerator($language) as $alternativeLanguage) {
							$url = strtr($source, ['/en.' => "/$alternativeLanguage."]);
							if (Yii::$app->extensionsRepository->testSourceUrl($url)) {
								$exists = true;
								break;
							}
						}

						if (!$exists) {
							echo "{$component->getId()} - missing translation for $language\n";
						}
					}
				}
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

	public function actionResetRateLimitForExtension(string $extensionsName) {
		$count = Yii::$app->extensionsRepository->resetRateLimitCache($extensionsName);
		if ($count > 0) {
			echo "Extension rate limit has been reset.\n";
		} else {
			echo "Extension is not rate limited.\n";
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
}
