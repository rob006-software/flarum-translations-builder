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
use app\components\release\ReleasePullRequestGenerator;
use app\components\translations\TranslationsImporter;
use Yii;

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

		$this->postProcessRepository($translations->getRepository(), 'Update sources from extensions.');
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

		foreach ($subsplits as $subsplit) {
			$subsplitToken = __METHOD__ . '#' . $subsplit->getId() . '#' . $subsplit->getTranslationsHash($translations);
			if ($this->isLimited($subsplitToken)) {
				continue;
			}
			$subsplit->getRepository()->update();
			$subsplit->split($translations);
			$this->postProcessRepository(
				$subsplit->getRepository(),
				$subsplit->processCommitMessage($translations, 'Sync translations with main repository.')
			);
			$subsplit->markAsProcessed($translations);
			if ($subsplit->hasReleaseGenerator()) {
				(new ReleasePullRequestGenerator($subsplit))->generate();
			}
			Yii::$app->locks->releaseRepoLock($subsplit->getRepository()->getPath());
			$this->updateLimit($subsplitToken);
		}
		$this->updateLimit($token);
	}

	public function actionImport(string $source, string $component, string $language, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$importer = new TranslationsImporter($translations, $translations->getComponent($component));
		$importer->import(Yii::getAlias($source), $language);

		$this->postProcessRepository(
			$translations->getRepository(),
			strtr('Importing "{component}" component from "{source}".', [
				'{component}' => $component,
				'{source}' => $source,
			])
		);
	}
}
