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
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use function array_key_exists;
use function dir;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_encode;
use function md5;
use function md5_file;
use function pathinfo;
use function reset;

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
	private $dir;
	private $sourcesDir;
	private $translationsDir;
	private $projects = [];
	private $subsplits;
	private $extensions;
	private $vendors;
	private $languages;

	private $repository;

	public function __construct(array $config) {
		$this->hash = md5(json_encode($config));
		$this->repository = new Repository($config['repository'], 'master', $config['dir']);
		$this->dir = $config['dir'];
		$this->sourcesDir = $config['sourcesDir'];
		$this->translationsDir = $config['translationsDir'];
		$this->languages = $config['languages'];
		$this->extensions = $config['extensions'];
		$this->vendors = $config['vendors'];
		$this->subsplits = $config['subsplits'];
		foreach ($config['projects'] as $projectId => $projectConfig) {
			$languages = ArrayHelper::remove($projectConfig, '__languages', $this->languages);
			$sourcesDir = ArrayHelper::remove($projectConfig, '__sourcesDir', "$this->sourcesDir/$projectId");
			$translationsDir = ArrayHelper::remove($projectConfig, '__translationsDir', "$this->translationsDir/$projectId");
			$weblateId = ArrayHelper::remove($projectConfig, '__weblateId', $projectId);
			$this->projects[$projectId] = new Project(
				$projectId,
				$weblateId,
				$projectConfig,
				$languages,
				$sourcesDir,
				$translationsDir
			);
		}
	}

	/**
	 * @return Project[]
	 */
	public function getProjects(): array {
		return $this->projects;
	}

	public function getProject(string $id): Project {
		if (!isset($this->projects[$id])) {
			throw new InvalidArgumentException('There is no project with ' . readable::value($id) . ' ID.');
		}

		return $this->projects[$id];
	}

	/**
	 * @return Subsplit[]
	 */
	public function getSubsplits(): iterable {
		foreach ($this->subsplits as $id => $config) {
			yield $this->getSubsplit($id);
		}
	}

	public function getSubsplit(string $id): Subsplit {
		if (!isset($this->subsplits[$id])) {
			throw new InvalidArgumentException('There is no subsplit with ' . readable::value($id) . ' ID.');
		}

		if (!$this->subsplits[$id] instanceof Subsplit) {
			$config = $this->subsplits[$id];
			/* @noinspection DegradedSwitchInspection */
			switch ($config['type']) {
				case LanguageSubsplit::TYPE:
					$this->subsplits[$id] = new LanguageSubsplit(
						$id,
						$config['language'],
						$config['repository'],
						$config['branch'],
						$config['path'],
						$config['updateReadme'] ?? false
					);
					break;
				default:
					throw new InvalidConfigException('Invalid subsplit type: ' . readable::value($id) . '.');
			}
		}

		return $this->subsplits[$id];
	}

	/**
	 * @return string[]
	 */
	public function getLanguages(): array {
		return $this->languages;
	}

	public function getDir(): string {
		return $this->dir;
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

	public function getExtension(Component $component): ?Extension {
		$id = $component->getId();
		if (!array_key_exists($id, $this->extensions)) {
			$sources = $component->getSources();
			$this->extensions[$id] = Extension::createFromGithubusercontentUrl($id, reset($sources));
		} elseif (is_string($this->extensions[$id])) {
			$this->extensions[$id] = new Extension($id, $this->extensions[$id]);
		} elseif (is_array($this->extensions[$id])) {
			$this->extensions[$id] = new Extension($id, $this->extensions[$id]['repositoryUrl'], $this->extensions[$id]);
		}

		return $this->extensions[$id];
	}

	public function getVendors(string $projectId): ?array {
		return $this->vendors[$projectId] ?? null;
	}
}
