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
use app\components\inheritors\InheritorInterface;
use app\components\inheritors\InheritorsStatusGenerator;
use app\components\release\ReleasePullRequestGenerator;
use app\components\translations\TranslationsImporter;
use app\helpers\FlarumVersion;
use app\models\Subsplit;
use Throwable;
use Yii;
use yii\helpers\Console;
use function array_merge;

/**
 * Class TranslationsController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class TranslationsController extends ConsoleController {

	public $defaultAction = 'update';

	public function options($actionID) {
		return array_merge(parent::options($actionID), [
			'commit',
			'push',
			'verbose',
			'frequency',
			'update',
		]);
	}

	public function actionUpdate(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}
		$catalogue = $translations->updateSources();
		foreach ($translations->getLanguages() as $language) {
			$translations->updateComponents($language, $catalogue);
		}

		$flarumVersion = FlarumVersion::lineName();
		$this->postProcessRepository(
			$translations->getRepository(),
			"[{$flarumVersion}] Update sources from extensions"
		);
		$this->updateLimit($token);
	}

	public function actionSplit(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getTranslationsHash() . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}

		if (empty($subsplits)) {
			$subsplits = $translations->getSubsplits();
		} else {
			foreach ($subsplits as $key => $subsplitId) {
				$subsplits[$key] = $translations->getSubsplit($subsplitId);
			}
		}

		$hasErrors = false;
		foreach ($subsplits as $subsplit) {
			$subsplitToken = __METHOD__ . '#' . $subsplit->getId() . '#' . $subsplit->getTranslationsHash($translations);
			if ($this->isLimited($subsplitToken)) {
				continue;
			}

			try {
				// acquire repository lock outside of try/finally below - there is nothing to release if this fails
				$repository = $subsplit->getRepository();
			} catch (Throwable $exception) {
				$hasErrors = true;
				$this->reportError($subsplit, $exception);
				continue;
			}

			try {
				$repository->update();
				$subsplit->split($translations);
				$this->postProcessRepository(
					$repository,
					$subsplit->processCommitMessage($translations, 'Sync translations with main repository')
				);
				$subsplit->markAsProcessed($translations);
				if ($subsplit->hasReleaseGenerator()) {
					(new ReleasePullRequestGenerator($subsplit))->generate();
				}
			} catch (Throwable $exception) {
				$hasErrors = true;
				$this->reportError($subsplit, $exception);
				continue;
			} finally {
				Yii::$app->locks->releaseRepoLock($repository->getPath());
			}

			$this->updateLimit($subsplitToken);
		}

		// do not mark the whole run as done if some subsplits failed - they should be retried on the next run
		if (!$hasErrors) {
			$this->updateLimit($token);
		}
	}

	public function actionInherit(array $inheritors = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		if (empty($inheritors)) {
			$inheritors = $translations->getInheritors();
		} else {
			foreach ($inheritors as $key => $inheritorId) {
				$inheritors[$key] = $translations->getInheritor($inheritorId);
			}
		}

		foreach ($inheritors as $inheritor) {
			assert($inheritor instanceof InheritorInterface);
			$inheritorToken = __METHOD__ . '#' . $inheritor->getId() . '#' . $inheritor->getHash();
			if ($this->isLimited($inheritorToken)) {
				continue;
			}
			$inheritor->inherit();
			$this->postProcessRepository(
				$translations->getRepository(),
				strtr('[{flarumVersion}] Inherit translations from {sourceLabel}', [
					'{flarumVersion}' => FlarumVersion::lineName(),
					'{sourceLabel}' => $inheritor->getInheritFromLabel(),
				])
			);

			// generate hash again, since inheritance may change the `inheritToTranslations` directory, and hash depends on it
			$inheritorToken = __METHOD__ . '#' . $inheritor->getId() . '#' . $inheritor->getHash();
			$this->updateLimit($inheritorToken);
		}
	}

	public function actionUpdateInheritorsStatus(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$token = __METHOD__ . '#' . $translations->getHash() . '#' . $translations->getSourcesHash();
		$inheritors = [];
		foreach ($translations->getInheritors() as $inheritor) {
			$inheritors[] = $inheritor;
			$token .= '#' . $inheritor->getHash();
		}
		if ($this->isLimited($token)) {
			return;
		}

		$generator = new InheritorsStatusGenerator($translations->getRepository()->getPath() . '/status/inheritors');
		$generator->generate($inheritors);

		$flarumVersion = FlarumVersion::lineName();
		$this->postProcessRepository(
			$translations->getRepository(),
			"[{$flarumVersion}] Update inherited translations status"
		);
		$this->updateLimit($token);
	}

	public function actionImport(string $source, string $component, string $language, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$importer = new TranslationsImporter($translations, $translations->getComponent($component));
		$importer->import(Yii::getAlias($source), $language);

		$this->postProcessRepository(
			$translations->getRepository(),
			strtr('[{flarumVersion}] Importing "{component}" component from "{source}"', [
				'{flarumVersion}' => FlarumVersion::lineName(),
				'{component}' => $component,
				'{source}' => $source,
			])
		);
	}

	public function actionUpdateOutdatedTranslationsMetadata(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getLanguages() as $language) {
			$translations->updateOutdatedTranslationsMetadata($language);
		}

		$flarumVersion = FlarumVersion::lineName();
		$this->postProcessRepository(
			$translations->getRepository(),
			"[{$flarumVersion}] Update outdated translations metadata"
		);
	}

	public function actionCleanupOutdatedTranslations(string $range = '-1 year', string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getLanguages() as $language) {
			$translations->cleanupOutdatedTranslations($language, $range);
		}

		$flarumVersion = FlarumVersion::lineName();
		$this->postProcessRepository(
			$translations->getRepository(),
			"[{$flarumVersion}] Cleanup outdated translations"
		);
	}

	public function actionUpdateOutdatedSubsplitsMetadata(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getSubsplits() as $subsplit) {
			try {
				$repository = $subsplit->getRepository();
			} catch (Throwable $exception) {
				$this->reportError($subsplit, $exception);
				continue;
			}

			try {
				$repository->update();
				$translations->updateOutdatedSubsplitMetadata($subsplit);
			} catch (Throwable $exception) {
				$this->reportError($subsplit, $exception);
			} finally {
				Yii::$app->locks->releaseRepoLock($repository->getPath());
			}
		}

		$flarumVersion = FlarumVersion::lineName();
		$this->postProcessRepository(
			$translations->getRepository(),
			"[{$flarumVersion}] Update outdated subsplits metadata"
		);
	}

	public function actionCleanupOutdatedSubsplits(string $range = '-1 year', string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		// release lock early, since we're only using config, and these actions can take a while
		Yii::$app->locks->releaseRepoLock($translations->getRepository()->getPath());

		foreach ($translations->getSubsplits() as $subsplit) {
			try {
				$repository = $subsplit->getRepository();
			} catch (Throwable $exception) {
				$this->reportError($subsplit, $exception);
				continue;
			}

			try {
				$repository->update();
				$translations->cleanupOutdatedSubsplit($subsplit, $range);

				$this->postProcessRepository(
					$repository,
					'Cleanup outdated components'
				);
			} catch (Throwable $exception) {
				$this->reportError($subsplit, $exception);
			} finally {
				Yii::$app->locks->releaseRepoLock($repository->getPath());
			}
		}
	}

	private function reportError(Subsplit $subsplit, Throwable $exception): void {
		Yii::warning("An error occurred while processing {$subsplit->getId()} subsplit: {$exception->getMessage()}");
		Yii::error($exception);
		echo Console::renderColoredString("%r{$subsplit->getId()}: {$exception->getMessage()}%n"), "\n";
	}
}
