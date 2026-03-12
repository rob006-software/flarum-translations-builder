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
use app\components\release\ReleasePullRequestGenerator;
use function array_merge;

/**
 * Class ReleaseController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ReleaseController extends ConsoleController {

	/** @var string */
	public $previousVersion = '';
	/** @var string */
	public $nextVersion = '';

	public function options($actionID): array {
		$options = array_merge(parent::options($actionID), [
			'update',
			'verbose',
			'previousVersion',
			'nextVersion',
		]);
		if ($actionID === 'pr') {
			$options = array_merge(parent::options($actionID), [
				'previousVersion',
				'nextVersion',
			]);
		}

		return $options;
	}

	public function actionPr(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$prGenerator = (new ReleasePullRequestGenerator($translations->getSubsplit($subsplit)));
		if ($this->previousVersion !== '') {
			$prGenerator->getGenerator()->setPreviousVersion($this->previousVersion);
		}
		if ($this->nextVersion !== '') {
			$prGenerator->getGenerator()->setNextVersion($this->nextVersion);
		}
		$prGenerator->generate();
	}

	public function actionMerge(string $subsplit, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		(new ReleasePullRequestGenerator($translations->getSubsplit($subsplit)))->merge();
	}
}
