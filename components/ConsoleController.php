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

namespace app\components;

use app\models\Repository;
use app\models\Translations;
use Yii;
use yii\console\Controller;
use function time;

/**
 * Class ConsoleController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
abstract class ConsoleController extends Controller {

	public $update = true;
	public $commit = false;
	public $push = false;
	public $verbose = false;
	/** @var int */
	public $frequency;

	protected function isLimited(string $hash): bool {
		if ($this->frequency <= 0) {
			return false;
		}

		$lastRun = Yii::$app->cache->get($hash);
		if ($lastRun > 0) {
			return time() - $lastRun < $this->frequency;
		}

		return false;
	}

	protected function updateLimit(string $hash): void {
		Yii::$app->cache->set($hash, time(), 31 * 24 * 60 * 60);
	}

	protected function getTranslations(string $configFile): Translations {
		$config = require Yii::getAlias($configFile);
		Yii::$app->locks->acquireRepoLock($config['dir']);
		$translations = new Translations(Yii::$app->params['translationsRepository'], null, $config);
		if ($this->update) {
			$output = $translations->getRepository()->update();
			if ($this->verbose) {
				echo $output;
			}
		}

		return $translations;
	}

	protected function commitRepository(Repository $repository, string $commitMessage): void {
		if ($this->commit || $this->push) {
			$output = $repository->commit($commitMessage);
			if ($this->verbose) {
				echo $output;
			}
		}
	}

	protected function pushRepository(Repository $repository): void {
		if ($this->push) {
			$output = $repository->push();
			if ($this->verbose) {
				echo $output;
			}
		}
	}

	protected function postProcessRepository(Repository $repository, string $commitMessage): void {
		$this->commitRepository($repository, $commitMessage);
		$this->pushRepository($repository);
	}
}
