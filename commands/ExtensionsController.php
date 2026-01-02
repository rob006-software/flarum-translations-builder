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
use app\components\extensions\exceptions\SoftFailureInterface;
use app\components\extensions\exceptions\UnprocessableExtensionExceptionInterface;
use app\components\extensions\NewExtensionPullRequestGenerator;
use app\components\extensions\PendingSummaryGenerator;
use app\models\ForkRepository;
use app\models\Translations;
use Yii;
use function array_filter;
use function array_keys;
use function array_merge;
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

	public $removeOutdated = false;
	public $useCache = false;

	public function options($actionID) {
		if ($actionID === 'update-cache') {
			return array_merge(parent::options($actionID), [
				'removeOutdated',
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

		$extensions = Yii::$app->extensionsRepository->getValidExtensions($this->useCache);
		foreach ($translations->getExtensionsComponents() as $component) {
			unset($extensions[$component->getId()]);
		}
		foreach ($extensions as $index => $extension) {
			try {
				if (!$extension->hasTranslationSource() && !$extension->hasBetaTranslationSource()) {
					unset($extensions[$index]);
				}
			} catch (UnprocessableExtensionExceptionInterface $exception) {
				Yii::$app->frequencyLimiter->run(
					__METHOD__ . '#' . $exception->getMessage(),
					31 * 24 * 3600,
					static function () use ($exception) {
						if (!$exception instanceof SoftFailureInterface) {
							Yii::warning($exception->getMessage());
						}
					}
				);
				unset($extensions[$index]);
			}
		}

		Yii::$app->locks->acquireRepoLock(APP_ROOT . '/runtime/translations-fork');
		$repository = new ForkRepository(
			Yii::$app->params['translationsForkRepository'],
			Yii::$app->params['translationsRepository'],
			null,
			APP_ROOT . '/runtime/translations-fork'
		);
		$generator = new NewExtensionPullRequestGenerator($repository);
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

		Yii::$app->locks->acquireRepoLock(APP_ROOT . '/runtime/translations-fork');
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

		$generator = new PendingSummaryGenerator($translations);
		foreach ($branches as $branch) {
			$extensionId = explode('/', $branch, 2)[1];
			$generator->addExtension($extensionId);
		}

		file_put_contents($translations->getDir() . '/status/pending.md', $generator->generate());
		$this->postProcessRepository($translations->getRepository(), 'Update list of pending extensions.');
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
				'subscriptionPlansCount' => $extension->getSubscriptionPlansCount(),
			];
		}

		$cachePath = $translations->getDir() . '/cache/extiverse.json';
		if (!$this->removeOutdated && file_exists($cachePath)) {
			$oldCache = (array) json_decode(file_get_contents($cachePath), true, 512, JSON_THROW_ON_ERROR);
			$result = array_merge($oldCache, $result);
		}

		ksort($result);
		file_put_contents(
			$cachePath,
			json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
		);

		$this->postProcessRepository($translations->getRepository(), 'Update cache for premium extensions.');
		$this->updateLimit(__METHOD__);
	}
}
