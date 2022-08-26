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

use app\models\Extension;
use app\models\Repository;
use app\models\Subsplit;
use Composer\Semver\Semver;
use RuntimeException;
use Yii;
use yii\base\BaseObject;
use yii\helpers\ArrayHelper;
use function date;
use function end;
use function explode;
use function file_exists;
use function file_get_contents;
use function in_array;
use function json_decode;
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
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ReleaseGenerator extends BaseObject {

	public $localePath;
	public $fallbackLocalePath;

	public $maintainers;

	public $versionTemplate = 'Major.Minor.Patch';

	private $subsplit;
	private $repository;

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
		$oldVersion = ltrim($this->getPreviousVersion() ?? $this->getZeroVersion(), 'v');
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
			/* @var $extension Extension */
			foreach ($added as $extension) {
				$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
			}
			$content .= "\n\n";
		}
		if (!empty($changed)) {
			$content .= $this->isMajorUpdate()
				? "**{$this->t('changelog.extensions-cleaned')}**:\n\n"
				: "**{$this->t('changelog.extensions-updated')}**:\n\n";
			/* @var $extension Extension */
			foreach ($changed as $extension) {
				$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
			}
			$content .= "\n\n";
		}
		if (!empty($removed)) {
			$content .= "**{$this->t('changelog.extensions-removed')}**:\n\n";
			/* @var $extension Extension */
			foreach ($removed as $id => $extension) {
				if ($extension === null) {
					$content .= "* `$id`\n";
				} else {
					$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
				}
			}
			$content .= "\n\n\n";
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
			'core.yml' => $this->isMajorUpdate()
				? $this->t('changelog.cleaned-core', ['{version}' => $this->getSupportedFlarumVersion()])
				: $this->t('changelog.updated-core'),
			'validation.yml' => $this->isMajorUpdate()
				? $this->t('changelog.cleaned-validation', ['{version}' => $this->getSupportedFlarumVersion()])
				: $this->t('changelog.updated-validation'),
			'config.js' => $this->t('changelog.updated-config-js'),
			'config.css' => $this->t('changelog.updated-config-css'),
		];
	}

	private function getSupportedFlarumVersion(): string {
		return ltrim($this->getComposerJsonContent()['require']['flarum/core'], '~^');
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
			$tags = Semver::sort($this->repository->getTags());
			$this->previousVersion = empty($tags) ? null : end($tags);
		}

		return $this->previousVersion;
	}

	public function setNextVersion(string $value): void {
		$this->nextVersion = $value;
	}

	public function getNextVersion(): string {
		if ($this->nextVersion === null) {
			$previous = $this->getPreviousVersion();
			$parts = $this->tokenizeVersion($previous ?? $this->getZeroVersion());
			if ($this->isMajorUpdate()) {
				$parts['Major']++;
				$parts['Minor'] = 0;
				$parts['Patch'] = 0;
			} elseif ($this->isMinorUpdate()) {
				$parts['Minor']++;
				$parts['Patch'] = 0;
			} else {
				$parts['Patch']++;
			}

			$this->nextVersion = strtr($this->versionTemplate, $parts);
		}

		return $this->nextVersion;
	}

	protected function isMinorUpdate(): bool {
		foreach ($this->getChangedExtensions() as $changeType) {
			if ($changeType === Repository::CHANGE_ADDED) {
				return true;
			}
		}

		// If template does not have patch fragment treat all changes as minor releases. This is useful for pre-1.0.0 versions.
		return strpos($this->versionTemplate, '.Patch') === false;
	}

	protected function isMajorUpdate(): bool {
		foreach ($this->getChangedExtensions() as $changeType) {
			if ($changeType === Repository::CHANGE_DELETED) {
				return true;
			}
		}

		return false;
	}

	protected function tokenizeVersion(string $version): array {
		$prefix = explode('Major', $this->versionTemplate, 2)[0];
		if (strncmp($prefix, $version, strlen($prefix)) !== 0) {
			throw new RuntimeException("'$version' does not match '$this->versionTemplate' template.");
		}
		$parts = explode('.', substr($version, strlen($prefix)), 3);
		return [
			'Major' => (int) ($parts[0] ?? 0),
			'Minor' => (int) ($parts[1] ?? 0),
			'Patch' => (int) ($parts[2] ?? 0),
		];
	}

	protected function getChangedExtensions(): array {
		$changedExtensions = [];
		$prefix = trim($this->subsplit->getPath(), '/') . '/';
		foreach ($this->getChangedFiles() as $file => $changeType) {
			if (
				strncmp($file, $prefix, strlen($prefix)) === 0
				&& substr($file, -4) === '.yml'
				&& !in_array($file, ["{$prefix}core.yml", "{$prefix}validation.yml"], true)
			) {
				$changedExtensions[substr($file, strlen($prefix), -4)] = $changeType;
			}
		}

		return $changedExtensions;
	}

	protected function getCoreChanges(): array {
		$coreChanges = [];
		$prefix = trim($this->subsplit->getPath(), '/') . '/';
		foreach ($this->getChangedFiles() as $file => $changeType) {
			if (in_array($file, [
				"{$prefix}core.yml",
				"{$prefix}validation.yml",
				"{$prefix}config.js",
				"{$prefix}config.css",
			], true)) {
				$coreChanges[substr($file, strlen($prefix))] = $changeType;
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
		$command = $this->isMajorUpdate() ? 'require' : 'update';
		$warning = $this->isMajorUpdate() ? "**{$this->t('announcement.major-warning')}**\n\n" : '';
		$changes = trim($this->getChangelogEntryContent());

		return <<<MD
			## {$this->t('announcement.version', ['{version}' => "[`{$this->getNextVersion()}`](https://github.com/$userName/$repoName/releases/tag/{$this->getNextVersion()})"])}
			
			{$changes}
			
			{$this->t('announcement.to-update')}
			
			```console
			composer $command {$this->getPackageName()}
			php flarum cache:clear
			```
			$warning
			MD;
	}

	protected function getZeroVersion(): string {
		return strtr($this->versionTemplate, [
			'Major' => 0,
			'Minor' => 0,
			'Patch' => 0,
		]);
	}

	public function getPackageName(): string {
		return $this->getComposerJsonContent()['name'];
	}

	public function getThreadUrl(): ?string {
		$composerJson = $this->getComposerJsonContent();
		$url = ArrayHelper::getValue($composerJson, 'extra.extiverse.discuss') ?? ArrayHelper::getValue($composerJson, 'extra.flagrow.discuss');
		return !empty($url) ? $url : null;
	}

	protected function getComposerJsonContent(): array {
		return json_decode(file_get_contents($this->getRepository()->getPath() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
	}
}
