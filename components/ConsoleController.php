<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components;

use Yii;
use yii\base\Exception;
use yii\console\Controller;

/**
 * Class ConsoleController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ConsoleController extends Controller {

	public function beforeAction($action) {
		if (!Yii::$app->mutex->acquire(__CLASS__, 900)) {
			throw new Exception('Cannot acquire lock.');
		}

		return parent::beforeAction($action);
	}
}
