<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\commands;

use app\components\ConsoleController;
use function array_merge;

/**
 * Class TestController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class TestController extends ConsoleController {

	public $update = false;
	/* @noinspection PropertyInitializationFlawsInspection */
	public $commit = false;
	/* @noinspection PropertyInitializationFlawsInspection */
	public $push = false;
	/* @noinspection PropertyInitializationFlawsInspection */
	public $verbose = false;

	public function options($actionID) {
		return array_merge(parent::options($actionID), [
			'update',
			'commit',
			'push',
			'verbose',
		]);
	}

	public function actionT(string $configFile = '@app/translations/config.php') {
		/* @noinspection PhpUnusedLocalVariableInspection */
		$translations = $this->getTranslations($configFile);
	}
}
