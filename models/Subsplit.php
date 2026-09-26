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
use yii\helpers\ArrayHelper;
use function array_keys;
use function arsort;
use function basename;
use function file_exists;
use function file_get_contents;
use function is_array;
use function json_decode;
use function preg_match;
use const JSON_THROW_ON_ERROR;

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
	private $releaseVersion;
	private $repositoryUrl;
	private $locale;
	private $defaultLocale;
	private $maintainers;
	private $weblateMaintainers;
	private $discussThreadId;

	public function __construct(
		string $id,
		$repository,
		string $branch,
		string $path,
		?string $releaseVersion,
		array $maintainers,
		array $weblateMaintainers,
		?int $discussThreadId = null
	) {
		$this->id = $id;
		$this->path = $path;
		$this->releaseVersion = $releaseVersion;
		if (is_array($repository)) {
			$this->repositoryUrl = $repository[0];
			$this->repository = $repository;
		} elseif ($repository instanceof Repository) {
			$this->repositoryUrl = $repository->getRemote();
			$this->repository = $repository;
		} else {
			$this->repositoryUrl = $repository;
			$this->repository = [$repository, $branch, static::generateRepositoryPath($id, $repository)];
		}
		$this->maintainers = $maintainers;
		$this->weblateMaintainers = $weblateMaintainers;
		$this->discussThreadId = $discussThreadId;
	}

	/**
	 * Creates subsplit from its config (from `config/translations/subsplits.php`).
	 *
	 * @param array $config Subsplit config with `branch` from `metadata/branches.json` of translations repository.
	 * @param string|null $releaseVersion Version from `metadata/versions.json` of translations repository.
	 */
	abstract public static function createFromConfig(string $id, array $config, ?string $releaseVersion): Subsplit;

	public static function generateRepositoryPath(string $subsplitId, string $repositoryUrl): string {
		$repoDirectory = $subsplitId . '__' . strtr($repositoryUrl, [
				'/' => '-',
				':' => '-',
				'.' => '-',
				'@' => '-',
				'?' => '-',
			]);
		return APP_ROOT . "/runtime/subsplits/$repoDirectory";
	}

	public function setRepository(Repository $repository): void {
		$this->repository = $repository;
	}

	public function getRepository(): Repository {
		if (is_array($this->repository)) {
			Yii::$app->locks->acquireRepoLock($this->repository[2]);
			$this->repository = new Repository(...$this->repository);
		}
		return $this->repository;
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	public function getLocale(): SubsplitLocale {
		if ($this->locale === null) {
			$this->locale = new SubsplitLocale($this->getLocaleLanguage(), self::getLocalePath($this->id), self::getLocalePath('en'));
		}
		return $this->locale;
	}

	/**
	 * Locale with default (English) phrases, ignoring subsplit-specific translations.
	 */
	public function getDefaultLocale(): SubsplitLocale {
		if ($this->defaultLocale === null) {
			$this->defaultLocale = new SubsplitLocale('en', null, self::getLocalePath('en'));
		}
		return $this->defaultLocale;
	}

	/**
	 * @return string[] Codes of all languages included in this subsplit.
	 */
	abstract public function getLanguages(): array;

	/**
	 * @return string Language code used for formatting localized phrases (plural rules etc.).
	 */
	abstract protected function getLocaleLanguage(): string;

	private static function getLocalePath(string $id): string {
		return APP_ROOT . "/resources/locale/subsplits/$id.json";
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

	public function getMaintainers(): array {
		return $this->maintainers;
	}

	/**
	 * @return string[] Usernames of maintainers on Weblate.
	 */
	public function getWeblateMaintainers(): array {
		return $this->weblateMaintainers;
	}

	/**
	 * ID of discussion on Flarum forum, where releases of this language pack are announced.
	 */
	public function getDiscussThreadId(): ?int {
		return $this->discussThreadId;
	}

	abstract public function isValidForComponent(Component $component): bool;

	abstract public function getTranslationsHash(Translations $translations): string;

	abstract public function split(Translations $translations): void;

	abstract public function createReadmeGenerator(Translations $translations): ReadmeGenerator;

	public function hasReleaseGenerator(): bool {
		return $this->releaseVersion !== null;
	}

	/**
	 * @return string|null Major and minor version (like `1.4`) used for new releases, or `null` if releases are not
	 * enabled for this subsplit.
	 * @see Translations::getReleaseVersion()
	 */
	public function getReleaseVersion(): ?string {
		return $this->releaseVersion;
	}

	public function createReleaseGenerator(Translations $translations): ReleaseGenerator {
		if ($this->releaseVersion === null) {
			throw new InvalidConfigException('Release version is not configured for this subsplit.');
		}

		return new ReleaseGenerator($this, $translations);
	}

	/**
	 * @return string[]
	 */
	abstract protected function getSourcesPaths(Translations $translations): array;

	public function hasTranslationForComponent(Component $component): bool {
		return file_exists($this->getDir() . $this->getPath() . "/{$component->getId()}.yml");
	}

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
		if ($lastCommit === null) {
			return [];
		}

		$authors = [];
		foreach ($this->getSourcesPaths($translations) as $path) {
			$response = $translations->getRepository()
				->getShortlog('-sne', '--no-merges', "$lastCommit..HEAD", '--', $path);
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

	public function getPackageName(): string {
		return $this->getComposerJsonContent()['name'];
	}

	public function getThreadUrl(): ?string {
		$composerJson = $this->getComposerJsonContent();
		$url = ArrayHelper::getValue($composerJson, 'extra.extiverse.discuss') ?? ArrayHelper::getValue($composerJson, 'extra.flagrow.discuss');
		return !empty($url) ? $url : null;
	}

	public function getComposerJsonContent(): array {
		return json_decode(file_get_contents($this->getRepository()->getPath() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
	}
}
