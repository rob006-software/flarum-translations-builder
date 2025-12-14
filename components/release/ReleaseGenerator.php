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

use app\models\Repository;
use app\models\Subsplit;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use RuntimeException;
use UnexpectedValueException;
use Yii;
use yii\base\BaseObject;
use function array_filter;
use function array_key_last;
use function array_pop;
use function basename;
use function date;
use function end;
use function explode;
use function file_exists;
use function file_get_contents;
use function in_array;
use function ltrim;
use function str_repeat;
use function strlen;
use function strncmp;
use function strpos;
use function strtr;
use function substr;
use function trim;

/**
 * Class ReleaseGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ReleaseGenerator extends BaseObject {

	public $localePath;
	public $fallbackLocalePath;

	public $versionTemplate = '1.Minor.Patch';

	private $subsplit;
	private $repository;

	private $versions;
	private $previousVersion = false;
	private $nextVersion;
	private $changelogEntryContent;

	private $_changes;

	public function __construct(Subsplit $subsplit, array $config = []) {
		$this->subsplit = $subsplit;
		$this->repository = $subsplit->getRepository();
		$this->repository->update();

		parent::__construct($config);
	}

	protected function t(string $key, array $params = []): string {
		return $this->getSubsplit()->getLocale()->t($key, $params);
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

	protected function fillOldVersionsInChangelog(string $changelog): string {
		$versions = $this->getVersions();
		$newContent = '';
		do {
			$new = array_pop($versions);
			$version = ltrim($new, 'v');
			$position = strpos($changelog, "$version (");
			if ($position === false) {
				$versionHeader = "$version (XXXX-XX-XX)";
				$versionUnderline = str_repeat('-', strlen($versionHeader));
				$newContent .=  "$versionHeader\n$versionUnderline\n\n";

				$old = $versions[array_key_last($versions)];
				[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
				$newContent .= $this->t('changelog.all-changes', [
					'{link}' => "[{$old}...{$new}](https://github.com/$userName/$repoName/compare/{$old}...{$new})",
				]);
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

	public function getChangelogEntryContent(): string {
		if ($this->changelogEntryContent !== null) {
			return $this->changelogEntryContent;
		}
		$added = [];
		$changed = [];
		$removed = [];
		foreach ($this->getChangedExtensions() as $extensionId => $changeType) {
			if ($changeType === Repository::CHANGE_ADDED) {
				$added[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId, false);
			} elseif ($changeType === Repository::CHANGE_MODIFIED) {
				$changed[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId, false);
			} elseif ($changeType === Repository::CHANGE_DELETED) {
				$removed[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId, false);
			}
		}

		$content = '';

		if (!empty($this->getCoreChanges())) {
			$content .= "**{$this->t('changelog.general-changes')}**:\n\n";
			foreach ($this->getCoreChanges() as $file => $changeType) {
				$label = $this->getCoreChangesLabels()[$file] ?? $this->t('changelog.updated-file', ['{file}' => $file]);
				$content .= "* $label.\n";
			}
			$content .= "\n\n";
		}

		if (!empty($added)) {
			$content .= "**{$this->t('changelog.extensions-added')}**:\n\n";
			foreach ($added as $id => $extension) {
				if ($extension === null) {
					$content .= "* `$id`\n";
				} else {
					$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
				}
			}
			$content .= "\n\n";
		}
		if (!empty($changed)) {
			$content .= $this->isMinorUpdate()
				? "**{$this->t('changelog.extensions-cleaned')}**:\n\n"
				: "**{$this->t('changelog.extensions-updated')}**:\n\n";
			foreach ($changed as $id => $extension) {
				if ($extension === null) {
					$content .= "* `$id`\n";
				} else {
					$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
				}
			}
			$content .= "\n\n";
		}
		if (!empty($removed)) {
			$content .= "**{$this->t('changelog.extensions-removed')}**:\n\n";
			foreach ($removed as $id => $extension) {
				if ($extension === null) {
					$content .= "* `$id`\n";
				} else {
					$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
				}
			}
			$content .= "\n\n";
		}

		$old = $this->getPreviousVersion();
		$new = $this->getNextVersion();
		[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		/* @noinspection PhpStatementHasEmptyBodyInspection */
		/* @noinspection MissingOrEmptyGroupStatementInspection */
		if ($old === null) {
			// this does not work - GitHub does not handle such compares
			// $oldReference = Repository::ZERO_COMMIT_HASH;
			//$content .= $this->t('changelog.all-changes', [
			//	'{link}' => "[{$new}](https://github.com/rob006-software/flarum-lang-polish/compare/{$oldReference}...{$new})"
			//]);
			//$content .= "\n\n\n";
		} else {
			$content .= $this->t('changelog.all-changes', [
				'{link}' => "[{$old}...{$new}](https://github.com/$userName/$repoName/compare/{$old}...{$new})",
			]);
			$content .= "\n\n\n";
		}

		$this->changelogEntryContent = $content;
		return $this->changelogEntryContent;
	}

	private function getCoreChangesLabels(): array {
		return [
			'core.yml' => $this->isMinorUpdate()
				? $this->t('changelog.cleaned-core', ['{version}' => $this->getSupportedFlarumVersion()])
				: $this->t('changelog.updated-core'),
			'validation.yml' => $this->isMinorUpdate()
				? $this->t('changelog.cleaned-validation', ['{version}' => $this->getSupportedFlarumVersion()])
				: $this->t('changelog.updated-validation'),
			'config.js' => $this->t('changelog.updated-config-js'),
			'config.css' => $this->t('changelog.updated-config-css'),
		];
	}

	private function getSupportedFlarumVersion(): string {
		return ltrim($this->getSubsplit()->getComposerJsonContent()['require']['flarum/core'], '~^');
	}

	public function commit(): string {
		$output = $this->repository->commit("Update CHANGELOG.md for {$this->getNextVersion()} release.");
		$output .= $this->repository->push();

		return $output;
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

	public function getVersions(): array {
		if ($this->versions === null) {
			$parser = new VersionParser();
			$requiredMajor = explode('.', ltrim($this->versionTemplate, 'v'), 2)[0];
			$tags = array_filter($this->repository->getTags(), static function ($name) use ($parser, $requiredMajor) {
				try {
					// remove non-semver tags
					$version = $parser->normalize($name);
					// ignore releases from the newer major line
					return $requiredMajor >= explode('.', $version, 2)[0];
				} catch (UnexpectedValueException $exception) {
					return false;
				}
			});

			$this->versions = Semver::sort($tags);
		}

		return $this->versions;
	}

	public function setNextVersion(string $value): void {
		$this->nextVersion = $value;
	}

	public function getNextVersion(): string {
		if ($this->nextVersion === null) {
			$previous = $this->getPreviousVersion();
			if ($previous === null) {
				$parts = $this->tokenizeVersion(strtr($this->versionTemplate, [
					'Minor' => 0,
					'Patch' => 0,
				]));
			} else {
				$parts = $this->tokenizeVersion($previous);
				if ($this->isMinorUpdate()) {
					$parts['Minor']++;
					$parts['Patch'] = 0;
				} else {
					$parts['Patch']++;
				}
			}

			$this->nextVersion = strtr($this->versionTemplate, $parts);
		}

		return $this->nextVersion;
	}

	protected function isMinorUpdate(): bool {
		// @todo improve this detection - this should also return true if we remove some old translations lines without
		//       removing the whole files. There are more things that we should consider and what may help:
		//       1. we should update the flarum version in `composer.json` - we could detect this change.
		//       2. Flarum version may need to be updated in README.md
		foreach ($this->getChangedExtensions() as $changeType) {
			if ($changeType === Repository::CHANGE_DELETED) {
				return true;
			}
		}

		return false;
	}

	protected function tokenizeVersion(string $version): array {
		$parts = explode('.', ltrim($version, 'v'), 3);
		return [
			'Major' => (int) $parts[0],
			'Minor' => (int) ($parts[1] ?? 0),
			'Patch' => (int) ($parts[2] ?? 0),
		];
	}

	protected function getChangedExtensions(): array {
		$changedExtensions = [];
		$prefix = trim($this->subsplit->getPath(), '/') . '/';
		foreach ($this->getChangedFiles() as $file => $changeType) {
			if (strncmp($file, $prefix, strlen($prefix)) !== 0) {
				continue;
			}
			$file = basename($file);
			if (substr($file, -4) === '.yml' && !in_array($file, ['core.yml', 'validation.yml'], true)) {
				$changedExtensions[substr($file, 0, -4)] = $changeType;
			}
		}

		return $changedExtensions;
	}

	protected function getCoreChanges(): array {
		$coreChanges = [];
		$prefix = trim($this->subsplit->getPath(), '/') . '/';
		foreach ($this->getChangedFiles() as $file => $changeType) {
			if (strncmp($file, $prefix, strlen($prefix)) !== 0) {
				continue;
			}
			$file = basename($file);
			if (in_array($file, ['core.yml', 'validation.yml', 'config.js', 'config.css'], true)) {
				$coreChanges[$file] = $changeType;
			}
		}

		return $coreChanges;
	}

	public function hasChanges(): bool {
		return !empty($this->getChangedExtensions()) || !empty($this->getCoreChanges());
	}

	protected function getChangedFiles(): array {
		if ($this->_changes === null) {
			$this->_changes = $this->repository->getChangesFrom($this->getPreviousVersion());
		}

		return $this->_changes;
	}

	public function release(bool $draft = false): array {
		return Yii::$app->githubApi->createRelease($this->subsplit->getRepositoryUrl(), $this->getNextVersion(), [
			'draft' => $draft,
			'name' => $this->getNextVersion(),
			'body' => trim($this->getChangelogEntryContent()),
			'target_commitish' => $this->repository->getBranch(),
		]);
	}

	public function getAnnouncement(): string {
		[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		$command = 'update';
		$warning = $this->isMinorUpdate() ? "\n\n**{$this->t('announcement.major-warning')}**\n\n" : '';
		$changes = trim($this->getChangelogEntryContent());

		return <<<MD
			## {$this->t('announcement.version', ['{version}' => "[`{$this->getNextVersion()}`](https://github.com/$userName/$repoName/releases/tag/{$this->getNextVersion()})"])}
			
			{$changes}
			
			{$this->t('announcement.to-update')}
			
			```console
			composer $command {$this->getSubsplit()->getPackageName()}
			php flarum cache:clear
			```
			$warning
			MD;
	}
}
