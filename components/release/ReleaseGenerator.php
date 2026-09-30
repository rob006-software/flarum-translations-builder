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

namespace app\components\release;

use app\components\translations\YamlLoader;
use app\helpers\FlarumVersion;
use app\models\LanguageSubsplit;
use app\models\MultiLanguageSubsplit;
use app\models\Repository;
use app\models\Subsplit;
use app\models\SubsplitLocale;
use app\models\Translations;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;
use Yii;
use yii\helpers\ArrayHelper;
use function array_diff_key;
use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_key_last;
use function array_pop;
use function basename;
use function count;
use function date;
use function end;
use function explode;
use function floor;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_array;
use function is_file;
use function json_decode;
use function ksort;
use function ltrim;
use function str_repeat;
use function strlen;
use function strncmp;
use function strpos;
use function substr;
use function trim;
use const JSON_THROW_ON_ERROR;

/**
 * Class ReleaseGenerator.
 *
 * Release notes in `CHANGELOG.md` and GitHub releases are always in English, only forum announcement is localized.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ReleaseGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** Translation files which are not related to extensions, with labels used in release notes. */
	private const CORE_FILES = [
		'core.yml' => 'changelog.updated-core',
		'validation.yml' => 'changelog.updated-validation',
	];
	/** Other files included in release notes, with labels used in release notes. */
	private const OTHER_FILES = [
		'config.js' => 'changelog.updated-config-js',
		'config.css' => 'changelog.updated-config-css',
	];

	private $subsplit;
	private $translations;
	private $repository;

	private $versions;
	private $previousVersion = false;
	private $nextVersion;
	private $changelogEntryContent;

	private $_changes;
	private $_translationsChanges;
	private $_completions = [];

	public function __construct(Subsplit $subsplit, Translations $translations) {
		$this->subsplit = $subsplit;
		$this->translations = $translations;
		$this->repository = $subsplit->getRepository();
		$this->repository->update();
	}

	public function getRepository(): Repository {
		return $this->repository;
	}

	public function getSubsplit(): Subsplit {
		return $this->subsplit;
	}

	public function getChangelogPath(): string {
		return $this->repository->getPath() . '/CHANGELOG.md';
	}

	public function generateChangelog(bool $draft = false): string {
		$oldVersion = ltrim($this->getPreviousVersion() ?? '0.0.0', 'v');
		$newVersion = ltrim($this->getNextVersion(), 'v');
		$changelogPath = $this->getChangelogPath();
		if (file_exists($changelogPath)) {
			$oldChangelog = file_get_contents($changelogPath);
			$position = strpos($oldChangelog, "$oldVersion (");
		} else {
			$oldChangelog = "CHANGELOG\n=========\n\n\n";
			$position = strlen($oldChangelog);
		}

		if ($position === false) {
			$oldChangelog = $this->fillOldVersionsInChangelog($oldChangelog);
			$position = strpos($oldChangelog, "$oldVersion (");
		}

		if ($position === false) {
			throw new RuntimeException("Unable to locate '$oldVersion' in $changelogPath.");
		}

		$date = $draft ? 'XXXX-XX-XX' : date('Y-m-d');
		$versionHeader = "$newVersion ($date)";
		$versionUnderline = str_repeat('-', strlen($versionHeader));
		return substr($oldChangelog, 0, $position)
			. "$versionHeader\n$versionUnderline\n\n"
			. $this->getChangelogEntryContent()
			. substr($oldChangelog, $position);
	}

	private function fillOldVersionsInChangelog(string $changelog): string {
		$versions = $this->getVersions();
		$newContent = '';
		do {
			$new = array_pop($versions);
			$version = ltrim($new, 'v');
			$position = strpos($changelog, "$version (");
			if ($position === false) {
				$versionHeader = "$version (XXXX-XX-XX)";
				$versionUnderline = str_repeat('-', strlen($versionHeader));
				$newContent .= "$versionHeader\n$versionUnderline\n\n";

				$old = empty($versions) ? null : $versions[array_key_last($versions)];
				if ($old !== null) {
					$newContent .= $this->renderAllChangesLink($this->subsplit->getDefaultLocale(), $old, $new);
				}
				$newContent .= "\n\n\n";
			} else {
				return substr($changelog, 0, $position)
					. $newContent
					. substr($changelog, $position);
			}
		} while (!empty ($versions));

		return $changelog;
	}

	public function setChangelogEntryContent(string $content): void {
		$this->changelogEntryContent = $content;
	}

	/**
	 * @return string Release notes for `CHANGELOG.md` and GitHub release (always in English).
	 */
	public function getChangelogEntryContent(): string {
		if ($this->changelogEntryContent === null) {
			$this->changelogEntryContent = $this->renderReleaseNotes($this->subsplit->getDefaultLocale());
		}

		return $this->changelogEntryContent;
	}

	private function renderReleaseNotes(SubsplitLocale $locale): string {
		$content = '';

		$generalChanges = [];
		foreach (self::CORE_FILES as $file => $labelKey) {
			$changes = $this->getTranslationsChanges()[substr($file, 0, -4)] ?? null;
			if ($changes !== null && $this->hasPhrasesChanges($changes)) {
				$generalChanges[] = "{$locale->t($labelKey)} ({$this->renderPhrasesChanges($locale, $changes)})";
			}
		}
		foreach (self::OTHER_FILES as $file => $labelKey) {
			if (isset($this->getOtherFilesChanges()[$file])) {
				$generalChanges[] = $locale->t($labelKey);
			}
		}
		if (!empty($generalChanges)) {
			$content .= "**{$locale->t('changelog.general-changes')}**:\n\n";
			foreach ($generalChanges as $change) {
				$content .= "* $change.\n";
			}
			$content .= "\n\n";
		}

		$added = [];
		$updated = [];
		$removed = [];
		foreach ($this->getExtensionsChanges() as $extensionId => $changes) {
			if (!$changes['existedBefore']) {
				$added[] = $this->renderExtensionName($extensionId)
					. $this->renderDetails([$this->renderCompletion($locale, $extensionId)]);
			} elseif (!$changes['existsNow']) {
				$removed[] = $this->renderExtensionName($extensionId);
			} else {
				$updated[] = $this->renderExtensionName($extensionId)
					. $this->renderDetails([$this->renderPhrasesChanges($locale, $changes), $this->renderCompletion($locale, $extensionId)]);
			}
		}
		foreach (['changelog.extensions-added' => $added, 'changelog.extensions-updated' => $updated, 'changelog.extensions-removed' => $removed] as $labelKey => $extensions) {
			if (!empty($extensions)) {
				$content .= "**{$locale->t($labelKey)}**:\n\n";
				foreach ($extensions as $extension) {
					$content .= "* $extension\n";
				}
				$content .= "\n\n";
			}
		}

		$old = $this->getPreviousVersion();
		if ($old !== null) {
			$content .= $this->renderAllChangesLink($locale, $old, $this->getNextVersion());
			$content .= "\n\n\n";
		}

		return $content;
	}

	private function renderAllChangesLink(SubsplitLocale $locale, string $old, string $new): string {
		[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		return $locale->t('changelog.all-changes', [
			'link' => "[{$old}...{$new}](https://github.com/$userName/$repoName/compare/{$old}...{$new})",
		]);
	}

	private function renderExtensionName(string $extensionId): string {
		$extension = Yii::$app->extensionsRepository->getExtension($extensionId, false);
		if ($extension === null) {
			return "`$extensionId`";
		}

		return "[`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})";
	}

	private function renderPhrasesChanges(SubsplitLocale $locale, array $changes): string {
		$parts = [];
		foreach (['added', 'changed', 'removed'] as $type) {
			if ($changes[$type] > 0) {
				$parts[] = $locale->t("changelog.count-$type", ['count' => $changes[$type]]);
			}
		}

		return implode(', ', $parts);
	}

	private function renderCompletion(SubsplitLocale $locale, string $componentId): ?string {
		$completion = $this->getCompletion($componentId);
		if ($completion === null) {
			return null;
		}

		return $locale->t('changelog.completion', ['percent' => $completion]);
	}

	/**
	 * @param string[]|null[] $parts
	 */
	private function renderDetails(array $parts): string {
		$parts = array_filter($parts);
		return empty($parts) ? '' : ' (' . implode(', ', $parts) . ')';
	}

	private function hasPhrasesChanges(array $changes): bool {
		return $changes['added'] > 0 || $changes['changed'] > 0 || $changes['removed'] > 0;
	}

	public function setPreviousVersion(string $value): void {
		$this->previousVersion = $value;
	}

	public function getPreviousVersion(): ?string {
		if ($this->previousVersion === false) {
			$versions = $this->getVersions();
			$this->previousVersion = empty($versions) ? null : end($versions);
		}

		return $this->previousVersion;
	}

	/**
	 * Returns sorted list of releases of this subsplit. Only tags reachable from subsplit branch are taken into
	 * account, so releases from other Flarum version lines (which share the same repository) are ignored.
	 */
	public function getVersions(): array {
		if ($this->versions === null) {
			$parser = new VersionParser();
			$current = $this->tokenizeVersion($this->subsplit->getReleaseVersion());
			$tags = array_filter($this->repository->getTags($this->repository->getBranch()), function ($name) use ($parser, $current) {
				try {
					// remove non-semver tags
					$parser->normalize($name);
				} catch (UnexpectedValueException $exception) {
					return false;
				}
				// ignore releases newer than the configured version
				$version = $this->tokenizeVersion($name);
				return [$version['Major'], $version['Minor']] <= [$current['Major'], $current['Minor']];
			});

			$this->versions = Semver::sort($tags);
		}

		return $this->versions;
	}

	/**
	 * @return bool Whether there was at least one release with the currently configured major and minor version.
	 */
	public function isCurrentVersionReleased(): bool {
		$current = $this->tokenizeVersion($this->subsplit->getReleaseVersion());
		foreach ($this->getVersions() as $version) {
			$version = $this->tokenizeVersion($version);
			if ($version['Major'] === $current['Major'] && $version['Minor'] === $current['Minor']) {
				return true;
			}
		}

		return false;
	}

	public function setNextVersion(string $value): void {
		$this->nextVersion = $value;
	}

	public function getNextVersion(): string {
		if ($this->nextVersion === null) {
			$current = $this->tokenizeVersion($this->subsplit->getReleaseVersion());
			$previous = $this->getPreviousVersion();
			$patch = 0;
			if ($previous !== null) {
				$previous = $this->tokenizeVersion($previous);
				if ($previous['Major'] === $current['Major'] && $previous['Minor'] === $current['Minor']) {
					$patch = $previous['Patch'] + 1;
				}
			}

			$this->nextVersion = "{$current['Major']}.{$current['Minor']}.$patch";
		}

		return $this->nextVersion;
	}

	/**
	 * @return bool Whether this release starts a new minor (or major) version. Such releases may remove translations
	 * for older versions of Flarum and extensions.
	 */
	private function isMinorUpdate(): bool {
		$previous = $this->getPreviousVersion();
		if ($previous === null) {
			return false;
		}
		$previous = $this->tokenizeVersion($previous);
		$next = $this->tokenizeVersion($this->getNextVersion());

		return [$previous['Major'], $previous['Minor']] !== [$next['Major'], $next['Minor']];
	}

	private function tokenizeVersion(string $version): array {
		$parts = explode('.', ltrim($version, 'v'), 3);
		return [
			'Major' => (int) $parts[0],
			'Minor' => (int) ($parts[1] ?? 0),
			'Patch' => (int) ($parts[2] ?? 0),
		];
	}

	/**
	 * @return array[] Changes in extensions translations, indexed by extension ID.
	 * @see getTranslationsChanges()
	 */
	private function getExtensionsChanges(): array {
		$changes = $this->getTranslationsChanges();
		foreach (self::CORE_FILES as $file => $_) {
			unset($changes[substr($file, 0, -4)]);
		}

		return array_filter($changes, function (array $changes) {
			return $changes['existedBefore'] !== $changes['existsNow'] || $this->hasPhrasesChanges($changes);
		});
	}

	/**
	 * Compares translations from previous release with the current state of subsplit. Translations from all
	 * variants of multi-language subsplit are summed up.
	 *
	 * @return array[] Changes indexed by file name without extension (like `flarum-tags` or `core`). Each item
	 * contains number of `added`, `changed` and `removed` phrases, and flags whether translation file existed in the
	 * previous release (`existedBefore`) and exists now (`existsNow`).
	 */
	private function getTranslationsChanges(): array {
		if ($this->_translationsChanges !== null) {
			return $this->_translationsChanges;
		}

		$changes = [];
		foreach ($this->getSubsplitChangedFiles() as $file => $changeType) {
			if (substr($file, -4) !== '.yml') {
				continue;
			}
			$id = basename($file, '.yml');
			$old = $changeType === Repository::CHANGE_ADDED
				? null
				: $this->loadMessages($this->repository->getFileContent($this->getPreviousVersion(), $file), $file);
			$new = $changeType === Repository::CHANGE_DELETED
				? null
				: $this->loadMessages(file_get_contents("{$this->repository->getPath()}/$file"), $file);

			$changes[$id] = $changes[$id] ?? [
				'added' => 0,
				'changed' => 0,
				'removed' => 0,
				// file may be unchanged in other variants - it existed before and exists now
				'existedBefore' => $this->hasUnchangedTranslationFile($id),
				'existsNow' => $this->hasUnchangedTranslationFile($id),
			];
			$changes[$id]['added'] += count(array_diff_key($new ?? [], $old ?? []));
			$changes[$id]['removed'] += count(array_diff_key($old ?? [], $new ?? []));
			foreach (array_intersect_key($new ?? [], $old ?? []) as $key => $value) {
				if ($old[$key] !== $value) {
					$changes[$id]['changed']++;
				}
			}
			$changes[$id]['existedBefore'] = $changes[$id]['existedBefore'] || $old !== null;
			$changes[$id]['existsNow'] = $changes[$id]['existsNow'] || $new !== null;
		}

		ksort($changes);
		$this->_translationsChanges = $changes;
		return $this->_translationsChanges;
	}

	/**
	 * Calculates translation completion based on JSON files from translations repository - YAML files in subsplit
	 * do not contain untranslated phrases. Only phrases which still exist in English source are taken into account.
	 * Phrases from all variants of multi-language subsplit are summed up.
	 *
	 * @return int|null Percentage of translated phrases (rounded down), or `null` if component is not available in
	 * translations repository.
	 */
	private function getCompletion(string $componentId): ?int {
		if (array_key_exists($componentId, $this->_completions)) {
			return $this->_completions[$componentId];
		}
		if (!$this->translations->hasComponent($componentId)) {
			return $this->_completions[$componentId] = null;
		}

		$component = $this->translations->getComponent($componentId);
		$source = $this->loadJsonMessages($this->translations->getComponentSourcePath($componentId));
		$total = 0;
		$translated = 0;
		foreach ($this->getLanguageSubsplits() as $subsplit) {
			if (!$subsplit->isValidForComponent($component)) {
				continue;
			}
			$messages = $this->loadJsonMessages($this->translations->getComponentTranslationPath($componentId, $subsplit->getLanguage()));
			$total += count($source);
			$translated += count(array_filter(array_intersect_key($messages, $source), static function ($message) {
				return $message !== '';
			}));
		}

		return $this->_completions[$componentId] = $total === 0 ? null : (int) floor($translated * 100 / $total);
	}

	private function loadJsonMessages(string $path): array {
		if (!is_file($path)) {
			return [];
		}
		$messages = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		return is_array($messages) ? ArrayHelper::flatten($messages) : [];
	}

	private function hasUnchangedTranslationFile(string $id): bool {
		$changedFiles = $this->getSubsplitChangedFiles();
		foreach ($this->getTranslationsDirectories() as $directory) {
			$file = ltrim("$directory/$id.yml", '/');
			if (!isset($changedFiles[$file]) && is_file("{$this->repository->getPath()}/$file")) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string[] Directories (relative to repository root) with translation files, including directories of
	 * variants for multi-language subsplit.
	 */
	private function getTranslationsDirectories(): array {
		$directories = [];
		foreach ($this->getLanguageSubsplits() as $subsplit) {
			$directories[] = trim($subsplit->getPath(), '/');
		}

		return $directories;
	}

	/**
	 * @return LanguageSubsplit[] Subsplit itself, or variants for multi-language subsplit.
	 */
	private function getLanguageSubsplits(): array {
		return $this->subsplit instanceof MultiLanguageSubsplit ? $this->subsplit->getVariants() : [$this->subsplit];
	}

	private function loadMessages(string $content, string $file): array {
		$messages = YamlLoader::filter(Yaml::parse($content), $file);
		return is_array($messages) ? ArrayHelper::flatten($messages) : [];
	}

	private function getOtherFilesChanges(): array {
		$changes = [];
		foreach ($this->getSubsplitChangedFiles() as $file => $changeType) {
			if (isset(self::OTHER_FILES[basename($file)])) {
				$changes[basename($file)] = $changeType;
			}
		}

		return $changes;
	}

	public function hasChanges(): bool {
		foreach (self::CORE_FILES as $file => $_) {
			$changes = $this->getTranslationsChanges()[substr($file, 0, -4)] ?? null;
			if ($changes !== null && $this->hasPhrasesChanges($changes)) {
				return true;
			}
		}

		return !empty($this->getExtensionsChanges()) || !empty($this->getOtherFilesChanges());
	}

	/**
	 * @return string[] Files inside of subsplit path (relative to repository root) changed since previous release,
	 * with change type as value.
	 */
	private function getSubsplitChangedFiles(): array {
		if ($this->_changes === null) {
			$this->_changes = [];
			$prefix = trim($this->subsplit->getPath(), '/') . '/';
			foreach ($this->repository->getChangesFrom($this->getPreviousVersion()) as $file => $changeType) {
				if (strncmp($file, $prefix, strlen($prefix)) === 0) {
					$this->_changes[$file] = $changeType;
				}
			}
		}

		return $this->_changes;
	}

	public function release(bool $draft = false): array {
		return Yii::$app->githubApi->createRelease($this->subsplit->getRepositoryUrl(), $this->getNextVersion(), [
			'draft' => $draft,
			'name' => $this->getNextVersion(),
			'body' => trim($this->getChangelogEntryContent()),
			'target_commitish' => $this->repository->getBranch(),
			// the same repository holds releases for both Flarum lines - only the newest line should be marked as latest
			'make_latest' => FlarumVersion::version() === FlarumVersion::LATEST ? 'true' : 'false',
		]);
	}

	/**
	 * @return string Release announcement for forum, localized to subsplit language.
	 */
	public function getAnnouncement(): string {
		$locale = $this->subsplit->getLocale();
		[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		$command = 'update';
		$warning = $this->isMinorUpdate() ? "\n\n**{$locale->t('announcement.major-warning')}**\n\n" : '';
		$changes = trim($this->renderReleaseNotes($locale));

		$flarumVersion = FlarumVersion::lineName();
		return <<<MD
			## {$locale->t('announcement.version', ['version' => "[`{$this->getNextVersion()}`](https://github.com/$userName/$repoName/releases/tag/{$this->getNextVersion()})"])} (Flarum {$flarumVersion})

			{$changes}

			{$locale->t('announcement.to-update')}

			```console
			composer $command {$this->getSubsplit()->getPackageName()}
			php flarum cache:clear
			```
			$warning
			MD;
	}
}
