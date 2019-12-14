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
use app\models\Repository;
use Symfony\Component\Process\Process;
use Yii;
use const APP_ROOT;

/**
 * Class SelfUpdateController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SelfUpdateController extends ConsoleController {

	public $defaultAction = 'run';

	public $update = true;

	public function options($actionId) {
		return array_merge(parent::options($actionId), [
			'update',
		]);
	}

	public function actionRun() {
		$repository = new Repository(Yii::$app->params['repository'], 'master', APP_ROOT);
		$repository->update();

		$process = new Process(['composer', 'install', '-o'], APP_ROOT);
		$process->setTimeout(5 * 60);
		$process->mustRun();

		if ($this->update) {
			$translationsRepository = new Repository(Yii::$app->params['translationsRepository'], 'master', APP_ROOT . '/translations');
			$translationsRepository->update();
		}
	}
}
