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

use app\models\ForkRepository;
use app\models\Translations;
use Yii;
use yii\console\Controller;
use function array_combine;
use function array_merge;

/**
 * Class JanitorController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class JanitorController extends Controller {

	public $useCache = false;

	public function options($actionId) {
		return array_merge(parent::options($actionId), [
			'useCache',
		]);
	}

	public function actionBranches(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		$extensions = Yii::$app->extensionsRepository->getValidExtensions(
			$translations->getSupportedVersions(),
			$this->useCache
		);
		$repository = new ForkRepository(
			Yii::$app->params['translationsForkRepository'],
			Yii::$app->params['translationsRepository'],
			null,
			APP_ROOT . '/runtime/translations-fork'
		);
		$repository->rebase();
		$repository->syncBranchesWithRemote();

		$branches = array_filter($repository->getBranches(), static function ($name) {
			return strncmp($name, 'new/', 4) === 0;
		});
		$orphanedBranches = array_combine($branches, $branches);
		foreach ($extensions as $extension) {
			unset($orphanedBranches["new/{$extension->getId()}"]);
		}

		if (empty($orphanedBranches)) {
			echo "No unnecessary branches found.\n";
		} else {
			foreach ($orphanedBranches as $branch) {
				echo $branch, "\n";
			}
		}
	}

	private function getTranslations(string $configFile): Translations {
		$translations = new Translations(
			Yii::$app->params['translationsRepository'],
			null,
			require Yii::getAlias($configFile)
		);
		$translations->getRepository()->update();

		return $translations;
	}
}
