<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\commands;

use app\components\readme\MainReadmeGenerator;
use app\models\Repository;
use app\models\Translations;
use mindplay\readable;
use Yii;
use yii\base\InvalidArgumentException;
use yii\console\Controller;
use function file_get_contents;
use function strpos;
use function substr;

/**
 * Class ReadmeController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ReadmeController extends Controller {

	public $defaultAction = 'update';

	public $commit = false;
	public $push = false;
	public $verbose = false;
	/** @var int */
	public $frequency;

	public function options($actionId) {
		return array_merge(parent::options($actionId), [
			'commit',
			'push',
			'verbose',
			'frequency',
		]);
	}

	public function actionUpdate(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		if ($this->isLimited($translations->getHash())) {
			return;
		}
		$readme = file_get_contents($translations->getDir() . '/README.md');
		foreach ($translations->getProjects() as $project) {
			$generator = new MainReadmeGenerator($project, $translations->getVendors($project->getId()));
			foreach ($project->getComponents() as $component) {
				$extension = $translations->getExtension($component);
				if ($extension !== null) {
					$generator->addExtension($extension);
				}
			}

			$readme = $this->replaceBetween(
				"<!-- {$project->getId()}-extensions-list-start -->",
				"<!-- {$project->getId()}-extensions-list-stop -->",
				$readme,
				$generator->generate()
			);
		}

		file_put_contents($translations->getDir() . '/README.md', $readme);

		$this->postProcessRepository($translations->getRepository(), 'Update translations status in README.');
		$this->updateLimit($translations->getHash());
	}

	private function replaceBetween(string $begin, string $end, string $string, string $replacement): string {
		$positionBegin = strpos($string, $begin);
		if ($positionBegin === false) {
			throw new InvalidArgumentException('$string does not contain ' . readable::value($begin) . '.');
		}
		$positionEnd = strpos($string, $end, $positionBegin);
		if ($positionEnd === false) {
			throw new InvalidArgumentException('$string does not contain ' . readable::value($end) . '.');
		}

		return substr($string, 0, $positionBegin) . $begin . $replacement . substr($string, $positionEnd);
	}

	private function getTranslations(string $configFile): Translations {
		$translations = new Translations(require Yii::getAlias($configFile));
		$output = $translations->getRepository()->update();
		if ($this->verbose) {
			echo $output;
		}

		return $translations;
	}

	private function postProcessRepository(Repository $repository, string $commitMessage): void {
		if ($this->commit || $this->push) {
			$output = $repository->commit($commitMessage);
			if ($this->verbose) {
				echo $output;
			}
		}
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
