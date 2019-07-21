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
use Yii;
use yii\console\Controller;
use const APP_ROOT;

/**
 * Class SelfUpdateController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SelfUpdateController extends Controller {

	public $defaultAction = 'run';

	public function actionRun() {
		$repository = new Repository(
			Yii::$app->params['repository'],
			'master',
			APP_ROOT
		);

		$repository->update();
	}
}
