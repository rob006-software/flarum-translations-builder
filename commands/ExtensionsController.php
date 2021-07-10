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
use app\components\extensions\PendingSummaryGenerator;
use app\components\extensions\PullRequestGenerator;
use app\models\ForkRepository;
use app\models\Repository;
use app\models\Translations;
use Yii;
use yii\helpers\ArrayHelper;
use function array_filter;
use function array_keys;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function ksort;
use function strncmp;
use const APP_ROOT;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Class ExtensionsController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ExtensionsController extends ConsoleController {

	public $update = true;
	public $commit = false;
	public $push = false;
	public $verbose = false;
	public $useCache = false;
	/** @var int */
	public $frequency;

	public function options($actionID) {
		if ($actionID === 'update-cache') {
			return array_merge(parent::options($actionID), [
				'frequency',
				'verbose',
				'update',
				'commit',
				'push',
			]);
		}

		if (in_array($actionID, ['detect-new', 'pending'], true)) {
			return array_merge(parent::options($actionID), [
				'frequency',
				'verbose',
				'useCache',
				'update',
				'commit',
				'push',
			]);
		}

		return array_merge(parent::options($actionID), [
			'frequency',
			'verbose',
			'useCache',
			'update',
		]);
	}

	public function actionList(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$extensions = Yii::$app->extensionsRepository->getAllExtensions($this->useCache);
		foreach ($translations->getExtensionsComponents() as $component) {
			if (!isset($extensions[$component->getId()])) {
				continue;
			}
			$extension = $extensions[$component->getId()];
			echo $component->getId() . ' - '
				. "[`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})"
				. "\n";
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
			$translations->getUnsupportedVersions(),
			$translations->getIgnoredExtensions(),
			$this->useCache
		);
		foreach ($translations->getExtensionsComponents() as $component) {
			unset($extensions[$component->getId()]);
		}
		foreach ($extensions as $index => $extension) {
			if (!$extension->hasTranslationSource()) {
				unset($extensions[$index]);
			}
		}

		$repository = new ForkRepository(
			Yii::$app->params['translationsForkRepository'],
			Yii::$app->params['translationsRepository'],
			null,
			APP_ROOT . '/runtime/translations-fork'
		);
		$generator = new PullRequestGenerator($repository);
		$generator->generateForNewExtensions($extensions, $limit);

		$this->updatePendingExtensionsList($translations, $repository);

		$this->updateLimit($token);
	}

	public function actionPending(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . implode('#', array_keys($translations->getComponents()));
		if ($this->isLimited($token)) {
			return;
		}

		$repository = new ForkRepository(
			Yii::$app->params['translationsForkRepository'],
			Yii::$app->params['translationsRepository'],
			null,
			APP_ROOT . '/runtime/translations-fork'
		);
		$repository->rebase();
		$repository->syncBranchesWithRemote();

		$this->updatePendingExtensionsList($translations, $repository);

		$this->updateLimit($token);
	}

	private function updatePendingExtensionsList(Translations $translations, ForkRepository $repository): void {
		$branches = array_filter($repository->getBranches(false), static function ($name) {
			return strncmp($name, 'new/', 4) === 0;
		});

		$generator = new PendingSummaryGenerator();
		foreach ($branches as $branch) {
			$extensionId = explode('/', $branch, 2)[1];
			$generator->addExtension($extensionId);
		}

		$readme = <<<MD
			# Pending extensions summary
			
			{$generator->generatePendingExtensions()}
			
			## Dead branches
			
			{$generator->generateDeadBranches()}
			MD;

		file_put_contents($translations->getDir() . '/status/pending.md', $readme);
		$this->commitRepository($translations->getRepository(), 'Update list of pending extensions.');
		$this->pushRepository($translations->getRepository());
	}

	// Save API response to cache, since API is not publicly available.
	public function actionUpdateCache(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		if ($this->isLimited(__METHOD__)) {
			return;
		}

		$extensions = Yii::$app->extiverseApi->searchExtensions();
		ksort($extensions);
		$result = [];
		foreach ($extensions as $id => $extension) {
			$result[$id] = [
				'name' => $extension->getName(),
				'title' => $extension->getTitle(),
				'description' => $extension->getDescription(),
				'version' => $extension->getVersion(),
				'requiredFlarum' => $extension->getRequiredFlarum(),
			];
		}

		$cachePath = $translations->getDir() . '/cache/extiverse.json';
		if (file_exists($cachePath)) {
			$oldCache = (array) json_decode(file_get_contents($cachePath), true, 512, JSON_THROW_ON_ERROR);
			$result = ArrayHelper::merge($oldCache, $result);
		}

		ksort($result);
		file_put_contents(
			$cachePath,
			json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
		);

		$this->commitRepository($translations->getRepository(), 'Update cache for premium extensions.');
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

	/* @noinspection PhpSameParameterValueInspection */
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
