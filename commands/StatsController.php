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

use app\components\ConsoleController;
use app\components\extensions\LanguageStatsGenerator;
use app\components\extensions\StatsRepository;
use app\models\Translations;
use Yii;
use function array_merge;
use function in_array;

/**
 * Class StatsController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class StatsController extends ConsoleController {

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

	public function actionUpdate(array $languages = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__
			. '#' . StatsRepository::getWeek()
			. '#' . $this->getComponentsListHash($translations)
			. '#' . $this->getLanguagesListHash($translations);
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
							$extension->isOutdated()
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

	private function getLanguagesListHash(Translations $translations): string {
		return md5(implode(';', $translations->getLanguages()));
	}
}
