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

use app\models\Repository;
use Symfony\Component\Process\Process;
use Yii;
use yii\console\Controller;
use function md5_file;
use function time;
use const APP_ROOT;

/**
 * Class SelfUpdateController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SelfUpdateController extends Controller {

	public $defaultAction = 'run';

	public function actionRun(bool $updateTranslations = true) {
		$repository = new Repository(Yii::$app->params['repository'], 'master', APP_ROOT);
		$repository->update();

		$hash = md5_file(APP_ROOT . '/composer.lock');
		if (Yii::$app->cache->get($hash) === false) {
			$process = new Process(['composer', 'install', '-o'], APP_ROOT);
			$process->start();
			Yii::$app->cache->set($hash, time(), 30 * 24 * 60 * 60);
		}

		if ($updateTranslations) {
			$translationsRepository = new Repository(Yii::$app->params['translationsRepository'], 'master', APP_ROOT . '/translations');
			$translationsRepository->update();
		}
	}
}
