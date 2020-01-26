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

use app\components\ConsoleController;
use app\components\readme\MainReadmeGenerator;
use app\models\LanguageSubsplit;
use app\models\Repository;
use app\models\Translations;
use mindplay\readable;
use Yii;
use yii\base\InvalidArgumentException;
use function file_get_contents;
use function strpos;
use function substr;

/**
 * Class ReadmeController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ReadmeController extends ConsoleController {

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

	public function actionUpdate(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}
		$readme = file_get_contents($translations->getDir() . '/README.md');
		foreach ($translations->getProjects() as $project) {
			$generator = new MainReadmeGenerator($project, $translations->getVendors($project->getId()));
			foreach ($project->getComponents() as $component) {
				$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
				if ($extension !== null) {
					$generator->addExtension($extension);
				}
			}

			if (
				strpos($readme, "<!-- {$project->getId()}-extensions-list-start -->") !== false
				&& strpos($readme, "<!-- {$project->getId()}-extensions-list-stop -->") !== false
			) {
				$readme = $this->replaceBetween(
					"<!-- {$project->getId()}-extensions-list-start -->",
					"<!-- {$project->getId()}-extensions-list-stop -->",
					$readme,
					$generator->generate()
				);
			}
		}

		file_put_contents($translations->getDir() . '/README.md', $readme);

		$this->postProcessRepository($translations->getRepository(), 'Update translations status in README.');
		$this->updateLimit($token);
	}

	public function actionUpdateSubsplits(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getTranslationsHash();
		if ($this->isLimited($token)) {
			return;
		}
		foreach ($translations->getSubsplits() as $subsplit) {
			if (
				(empty($subsplits) || in_array($subsplit->getId(), $subsplits, true))
				&& $subsplit->shouldUpdateReadme()
			) {
				$readme = file_get_contents($subsplit->getDir() . '/README.md');
				foreach ($translations->getProjects() as $project) {
					$generator = $subsplit->getReadmeGenerator($translations, $project);
					foreach ($project->getComponents() as $component) {
						if (
							(!($subsplit instanceof LanguageSubsplit) || $component->isValidForLanguage($subsplit->getLanguage()))
							&& $subsplit->isValidForComponent($project->getId(), $component->getId())
							&& $subsplit->hasTranslationForComponent($component->getId())
						) {
							$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
							if ($extension !== null) {
								$generator->addExtension($extension);
							}
						}
					}

					$readme = $this->replaceBetween(
						"<!-- {$project->getId()}-extensions-list-start -->",
						"<!-- {$project->getId()}-extensions-list-stop -->",
						$readme,
						$generator->generate()
					);
				}

				file_put_contents($subsplit->getDir() . '/README.md', $readme);
				$this->postProcessRepository($subsplit->getRepository(), 'Update translations status in README.');
			}
		}
		$this->updateLimit($token);
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
