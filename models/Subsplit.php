<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\models;

use app\components\readme\ReadmeGenerator;
use app\components\release\ReleaseGenerator;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;
use function array_keys;
use function arsort;
use function basename;
use function preg_match;

/**
 * Class Subsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
abstract class Subsplit {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $id;
	private $repository;
	private $path;
	private $components;
	private $releaseGenerator;
	private $repositoryUrl;

	public function __construct(
		string $id,
		string $repository,
		string $branch,
		string $path,
		?array $components,
		?string $releaseGenerator = null
	) {
		$this->id = $id;
		$this->path = $path;
		$this->components = $components;
		$this->releaseGenerator = $releaseGenerator;
		$this->repositoryUrl = $repository;
		$repoDirectory = $id . '__' . Inflector::slug($repository);
		$this->repository = new Repository($repository, $branch, APP_ROOT . "/runtime/subsplits/$repoDirectory");
	}

	public function getRepository(): Repository {
		return $this->repository;
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getPath(): string {
		return $this->path;
	}

	public function getDir(): string {
		return $this->getRepository()->getPath();
	}

	public function isValidForComponent(string $componentId): bool {
		return $this->components === null || in_array($componentId, $this->components, true);
	}

	abstract public function split(Translations $translations): void;

	abstract public function getReadmeGenerator(Translations $translations): ReadmeGenerator;

	public function createReleaseGenerator(): ReleaseGenerator {
		if ($this->releaseGenerator === null) {
			throw new InvalidConfigException('$releaseGenerator is not configured for this subsplit.');
		}

		return new $this->releaseGenerator($this);
	}

	/**
	 * @return string[]
	 */
	abstract protected function getSourcesPaths(Translations $translations): array;

	public function processCommitMessage(Translations $translations, string $commitMessage): string {
		$authors = $this->getAuthorsSinceLastChange($translations);
		if (empty($authors)) {
			return $commitMessage;
		}

		$commitMessage .= "\n\n";
		foreach ($authors as $author) {
			$commitMessage .= "\nCo-authored-by: $author";
		}

		return $commitMessage;
	}

	private function getAuthorsSinceLastChange(Translations $translations): array {
		$lastCommit = $this->getLastProcessedHash();

		$authors = [];
		foreach ($this->getSourcesPaths($translations) as $path) {
			if ($lastCommit === null) {
				$firstCommit = $translations->getRepository()->getFirstCommitHash();
				$response = $translations->getRepository()
					->getShortlog('-sne', '--no-merges', "$firstCommit..HEAD", '--', $path);
			} else {
				$response = $translations->getRepository()
					->getShortlog('-sne', '--no-merges', "$lastCommit..HEAD", '--', $path);
			}
			$authors = $this->processAuthors($response, $authors);
		}

		// no need to include bot - he is already author of the commit
		unset($authors[$this->getRepository()->getCurrentAuthor()]);
		arsort($authors);
		return array_keys($authors);
	}

	private function processAuthors(string $input, array $authors): array {
		foreach (explode("\n", $input) as $row) {
			$row = trim($row);
			if (preg_match('/\s*(\d+)\s*(.*)/', $row, $matches)) {
				$count = (int) trim($matches[1]);
				$author = trim($matches[2]);
				$authors[$author] = $count + ($authors[$author] ?? 0);
			}
		}

		return $authors;
	}

	protected function getLastProcessedHash(): ?string {
		$cache = Yii::$app->cache->get($this->getLastProcessedHashCacheKey());
		return $cache === false ? null : $cache;
	}

	protected function setLastProcessedHash(string $hash): void {
		Yii::$app->cache->set($this->getLastProcessedHashCacheKey(), $hash, 30 * 24 * 3600);
	}

	private function getLastProcessedHashCacheKey(): string {
		return static::class . '#' . basename($this->getRepository()->getPath());
	}

	public function markAsProcessed(Translations $translations): void {
		$this->setLastProcessedHash($translations->getRepository()->getCurrentRevisionHash());
	}
}
