<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\models;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use yii\helpers\ArrayHelper;

/**
 * Class Config.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Config {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $sourcesDir;
	private $translationsDir;
	private $projects = [];
	private $languages;

	public function __construct(array $config) {
		$this->sourcesDir = $config['sourcesDir'];
		$this->translationsDir = $config['translationsDir'];
		$this->languages = $config['languages'];
		foreach ($config['projects'] as $projectName => $projectConfig) {
			$languages = ArrayHelper::remove($projectConfig, 'languages', $this->languages);
			$sourcesDir = ArrayHelper::remove($projectConfig, 'sourcesDir', $this->sourcesDir);
			$translationsDir = ArrayHelper::remove($projectConfig, 'translationsDir', $this->translationsDir);
			$this->projects[$projectName] = new Project($projectName, $projectConfig, $languages, $sourcesDir, $translationsDir);
		}
	}

	/**
	 * @return Project[]
	 */
	public function getProjects(): array {
		return $this->projects;
	}

	/**
	 * @return string[]
	 */
	public function getLanguages(): array {
		return $this->languages;
	}

	public function getSourcesDir(): string {
		return $this->sourcesDir;
	}

	public function getTranslationsDir(): string {
		return $this->translationsDir;
	}
}
