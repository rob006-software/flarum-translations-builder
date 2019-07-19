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

use app\models\Config;
use Yii;
use yii\console\Controller;

/**
 * Class UpdateController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class UpdateController extends Controller {

	public $defaultAction = 'run';

	public function actionRun(string $configFile = '@app/translations/config.php') {
		$config = new Config(require Yii::getAlias($configFile));
		foreach ($config->getProjects() as $project) {
			$catalogue = $project->updateSources();
			foreach ($project->getLanguages() as $language) {
				$project->updateComponents($language, $catalogue);
			}
		}
	}
}
