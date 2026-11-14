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

use app\helpers\FlarumVersion;
use app\models\Repository;
use app\models\Subsplit;
use app\models\Translations;
use Throwable;
use Yii;
use yii\base\Exception;
use yii\console\Controller;
use yii\helpers\Console;
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

	public function beforeAction($action) {
		// make sure we don't run the same actions concurrently
		if (!Yii::$app->mutex->acquire(static::class . '#' . $action->id, 900)) {
			throw new Exception("Cannot acquire lock for {$action->getUniqueId()} action.");
		}

		return parent::beforeAction($action);
	}

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
		$translations = new Translations(Yii::$app->params['translationsRepository'], FlarumVersion::branch(), $config);
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

	/**
	 * @param string[] $subsplitIds
	 * @return iterable|Subsplit[]
	 */
	protected function resolveSubsplits(Translations $translations, array $subsplitIds): iterable {
		if (empty($subsplitIds)) {
			return $translations->getSubsplits();
		}

		$subsplits = [];
		foreach ($subsplitIds as $subsplitId) {
			$subsplits[] = $translations->getSubsplit($subsplitId);
		}
		return $subsplits;
	}

	/**
	 * Updates repository of each subsplit, calls `$callback` to modify files, then commits and pushes changes
	 * (depending on `--commit` and `--push` options). Errors are reported and do not stop processing other subsplits.
	 *
	 * @param iterable|Subsplit[] $subsplits
	 * @param string $label Name of updated files, used in error messages.
	 * @param callable $callback `function (Subsplit $subsplit, Repository $repository): void`
	 */
	protected function updateSubsplitsFiles(iterable $subsplits, string $label, string $commitMessage, callable $callback): void {
		foreach ($subsplits as $subsplit) {
			try {
				// acquire repository lock outside of try/finally below - there is nothing to release if this fails
				$repository = $subsplit->getRepository();
			} catch (Throwable $exception) {
				$this->reportSubsplitError($subsplit, $exception, $label);
				continue;
			}

			try {
				$repository->update();
				$callback($subsplit, $repository);
				$this->postProcessRepository($repository, $commitMessage);
			} catch (Throwable $exception) {
				$this->reportSubsplitError($subsplit, $exception, $label);
			} finally {
				Yii::$app->locks->releaseRepoLock($repository->getPath());
			}
		}
	}

	private function reportSubsplitError(Subsplit $subsplit, Throwable $exception, string $label): void {
		Yii::warning("An error occurred while updating $label for {$subsplit->getId()} subsplit: {$exception->getMessage()}");
		Yii::error($exception);
		echo Console::renderColoredString("%r{$subsplit->getId()}: {$exception->getMessage()}%n"), "\n";
	}
}
