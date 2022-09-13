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
use app\components\extensions\ConfigGenerator;
use Yii;
use function array_merge;
use function json_encode;

/**
 * Class ConfigController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ConfigController extends ConsoleController {

	public $defaultAction = 'update';

	public $commit = true;

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
		$token = __METHOD__ . '#' . json_encode($translations->getSupportedVersions(), JSON_THROW_ON_ERROR);
		if ($this->isLimited($token)) {
			return;
		}

		$configGenerator = new ConfigGenerator($translations->getDir() . '/config/components.php');

		foreach ($translations->getExtensionsComponents() as $component) {
			$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
			if ($extension === null) {
				Yii::warning("Unable to update {$component->getId()} extension.", __METHOD__);
				continue;
			}
			$configGenerator->updateExtension($extension);
			$this->commitRepository(
				$translations->getRepository(),
				"Update config for `{$extension->getPackageName()}`.\n\n{$extension->getRepositoryUrl()}"
			);
		}

		$this->pushRepository($translations->getRepository());
		$this->updateLimit($token);
	}

	public static function resetFrequencyLimit(): void {
		Yii::$app->cache->delete(__CLASS__ . '::actionUpdate');
	}
}
