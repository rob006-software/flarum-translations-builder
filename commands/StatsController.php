<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\commands;

use app\components\extensions\LanguageStatsGenerator;
use app\models\Repository;
use app\models\Translations;
use Yii;
use yii\console\Controller;
use function array_merge;
use function in_array;
use function time;

/**
 * Class StatsController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class StatsController extends Controller {

	public $defaultAction = 'update';

	public $update = true;
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
			'update',
		]);
	}

	public function actionUpdate(array $languages = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . LanguageStatsGenerator::getWeek() . '#' . $this->getComponentsListHash($translations);
		if ($this->isLimited($token)) {
			return;
		}

		$repository = $translations->getRepository();

		foreach ($translations->getLanguages() as $language) {
			if (empty($languages) || in_array($language, $languages, true)) {
				$generator = new LanguageStatsGenerator($language);
				foreach ($translations->getExtensionsComponents() as $component) {
					$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
					if ($extension !== null) {
						$generator->addExtension(
							$extension,
							!$component->isValidForLanguage($language),
							$extension->isOutdated(
								$translations->getSupportedVersions(),
								$translations->getUnsupportedVersions()
							)
						);
					}
				}

				file_put_contents($repository->getPath() . "/status/$language.md", $generator->generate());
			}
		}

		$date = date('Y-m-d');
		$this->postProcessRepository($repository, "Update translations status as per $date.");
		$this->updateLimit($token);
	}

	private function getComponentsListHash(Translations $translations): string {
		return md5(implode(';', array_keys($translations->getComponents())));
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
