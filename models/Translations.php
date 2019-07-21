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
use mindplay\readable;
use yii\base\InvalidArgumentException;
use yii\helpers\ArrayHelper;
use function dir;
use function implode;
use function in_array;
use function is_dir;
use function json_encode;
use function md5;
use function md5_file;
use function pathinfo;

/**
 * Class Translations.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Translations {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $hash;
	private $sourcesDir;
	private $translationsDir;
	private $projects = [];
	private $subsplits = [];
	private $languages;

	private $repository;

	public function __construct(array $config) {
		$this->hash = md5(json_encode($config));
		$this->repository = new Repository($config['repository'], 'master', $config['dir']);
		$this->sourcesDir = $config['sourcesDir'];
		$this->translationsDir = $config['translationsDir'];
		$this->languages = $config['languages'];
		foreach ($config['projects'] as $projectName => $projectConfig) {
			$languages = ArrayHelper::remove($projectConfig, 'languages', $this->languages);
			$sourcesDir = ArrayHelper::remove($projectConfig, 'sourcesDir', $this->sourcesDir);
			$translationsDir = ArrayHelper::remove($projectConfig, 'translationsDir', $this->translationsDir);
			$this->projects[$projectName] = new Project($projectName, $projectConfig, $languages, $sourcesDir, $translationsDir);
		}
		foreach ($config['subsplits'] as $subsplitName => $subsplitConfig) {
			$this->subsplits[$subsplitName] = new Subsplit(
				$subsplitName,
				$subsplitConfig['language'],
				$subsplitConfig['repository'],
				$subsplitConfig['branch'],
				$subsplitConfig['path']
			);
		}
	}

	/**
	 * @return Project[]
	 */
	public function getProjects(): array {
		return $this->projects;
	}

	/**
	 * @return Subsplit[]
	 */
	public function getSubsplits(): array {
		return $this->subsplits;
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

	public function getRepository(): Repository {
		return $this->repository;
	}

	public function getHash(): string {
		return $this->hash;
	}

	public function getSourcesHash(): string {
		return $this->getDirectoryHash($this->sourcesDir, 'json');
	}

	public function getTranslationsHash(): string {
		return $this->getDirectoryHash($this->translationsDir, 'json');
	}

	private function getDirectoryHash(string $directory, string $extension): string {
		if (!is_dir($directory)) {
			throw new InvalidArgumentException(readable::value($directory) . ' is not a valid directory.');
		}

		$dir = dir($directory);
		$hashes = [];
		while (($file = $dir->read()) !== false) {
			if (!in_array($file, ['.', '..'], true)) {
				if (is_dir($directory . '/' . $file)) {
					$hashes[] = $this->getDirectoryHash("$directory/$file", $extension);
				} elseif (pathinfo($file, PATHINFO_EXTENSION) === $extension) {
					$hashes[] = md5_file("$directory/$file");
				}
			}
		}

		$dir->close();

		return md5(implode(':', $hashes));
	}
}
