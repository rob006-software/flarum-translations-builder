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
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function preg_replace;

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

	public function actionT(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);

		if (empty($subsplits)) {
			$subsplits = $translations->getSubsplits();
		} else {
			foreach ($subsplits as $key => $subsplitId) {
				$subsplits[$key] = $translations->getSubsplit($subsplitId);
			}
		}

		foreach ($subsplits as $subsplit) {
			$subsplit->getRepository()->update();
			$readme = file_get_contents($subsplit->getDir() . '/README.md');

			$readme = preg_replace([
				//'/composer require (")?flarum-lang\/([a-z-]+)(")?(?![^ \n\r])/',
				//'/composer require (")?flarum-lang\/([a-z-]+):dev-[a-z-]+(")?/',
				//'/([` ])1\.(\d+)(\.\d+)?/',
				'/(weblate\.rob006\.net\/[a-zA-Z0-9\/]*)\/flarum\//'
			], [
				//'composer require "flarum-lang/$2:*"',
				//'composer require "flarum-lang/$2:@dev"',
				//'${1}2.0.0',
				'$1/flarum2/'
			], $readme);

			file_put_contents($subsplit->getDir() . '/README.md', $readme);

			$dir = escapeshellarg($subsplit->getDir());

			passthru("cd $dir && git diff");

			if ($this->confirm('Commit?', true)) {
				$this->postProcessRepository(
					$subsplit->getRepository(),
					$subsplit->processCommitMessage($translations, 'Update README.md')
				);
			}
		}
	}
}
