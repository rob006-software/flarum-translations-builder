<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\queue;

use Yii;
use yii\queue\file\Command;

/**
 * Class QueueCommand.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class QueueCommand extends Command {

	public function actionSingleListen($timeout = 3) {
		if (Yii::$app->mutex->acquire(__METHOD__ . "({$this->uniqueId})")) {
			return $this->actionListen($timeout);
		}
	}

	protected function isWorkerAction($actionID) {
		return $actionID === 'single-listen' || parent::isWorkerAction($actionID);
	}
}
