<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\commands;

use app\components\ConsoleController;
use app\components\weblate\WeblateApi;
use app\models\Extension;
use app\models\PremiumExtension;
use app\models\Translations;
use Yii;
use yii\base\Exception;
use function array_merge;
use function time;

/**
 * Class WeblateController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class WeblateController extends ConsoleController {

	private const STATS_TO_PRIORITY_MAP = [
		200 => WeblateApi::PRIORITY_HIGH,
		100 => WeblateApi::PRIORITY_MEDIUM,
		20 => WeblateApi::PRIORITY_LOW,
		0 => WeblateApi::PRIORITY_VERY_LOW,
	];

	public $update = true;
	public $verbose = false;
	/** @var int */
	public $frequency;

	public function beforeAction($action) {
		// we release standard repository lock at the end of `getTranslations()`, so we need additional lock in order to
		// avoid concurrent executions of the same command
		if (!Yii::$app->mutex->acquire(__CLASS__ . '#' . $action->id, 900)) {
			throw new Exception('Cannot acquire action lock.');
		}

		return parent::beforeAction($action);
	}

	public function options($actionID) {
		return array_merge(parent::options($actionID), [
			'verbose',
			'update',
			'frequency',
		]);
	}

	public function actionUpdatePriorities(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getExtensionsComponents() as $component) {
			$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
			if ($extension !== null) {
				Yii::$app->weblateApi->updateComponentPriority($extension->getId(), $this->calculatePriority($extension));
			}
		}
	}

	public function actionUpdateUnitsFlags(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getSourcesHash();
		if ($this->isLimited($token)) {
			return;
		}
		foreach ($translations->getComponents() as $component) {
			foreach (Yii::$app->weblateApi->getUnits($component->getId()) as $unit) {
				if (strpos($unit['source'][0], '=> ') === 0 && strpos($unit['extra_flags'], 'ignore-same') === false) {
					Yii::$app->weblateApi->updateUnit($unit['id'], [
						'extra_flags' => trim("{$unit['extra_flags']},ignore-same", ','),
					]);
				}
			}
		}
		$this->updateLimit($token);
	}

	private function calculatePriority(Extension $extension): int {
		if ($extension->getVendor() === 'flarum') {
			return WeblateApi::PRIORITY_VERY_HIGH;
		}

		if ($extension instanceof PremiumExtension) {
			$downloads = Yii::$app->stats->getStats($extension)->getSubscribersCount() * 2;
		} else {
			$downloads = Yii::$app->stats->getStats($extension)->getMonthlyDownloads();
		}

		foreach (self::STATS_TO_PRIORITY_MAP as $count => $priority) {
			if ($downloads >= $count) {
				return $priority;
			}
		}

		return WeblateApi::PRIORITY_VERY_LOW;
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
		$this->releaseLock();

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