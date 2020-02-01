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
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use RuntimeException;
use Yii;
use function date;
use function end;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function ltrim;
use function str_repeat;
use function strlen;
use function strncmp;
use function strpos;
use function substr;
use function trim;

/**
 * Class ReleaseGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
abstract class ReleaseGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	protected $versionTemplate = 'v0.Major.Minor.Patch';
	protected $skipPatch = true;

	private $subsplit;
	private $repository;

	private $previousVersion;
	private $nextVersion;

	public function __construct(Subsplit $subsplit) {
		$this->subsplit = $subsplit;
		$this->repository = $subsplit->getRepository();
		$this->repository->update();
	}

	protected function getRepository(): Repository {
		return $this->repository;
	}

	protected function getSubsplit(): Subsplit {
		return $this->subsplit;
	}

	public function generateChangelog(): string {
		$oldVersion = ltrim($this->getPreviousVersion(), 'v');
		$newVersion = ltrim($this->getNextVersion(), 'v');
		$changelogPath = $this->repository->getPath() . '/CHANGELOG.md';
		$oldChangelog = file_get_contents($changelogPath);

		$position = strpos($oldChangelog, "$oldVersion (");
		if ($position === false) {
			throw new RuntimeException("Unable to locate '$oldVersion' in $changelogPath.");
		}

		$date = date('Y-m-d');
		$versionHeader = "$newVersion ($date)";
		$versionUnderline = str_repeat('-', strlen($versionHeader));
		$newChangelog = substr($oldChangelog, 0, $position)
			. "$versionHeader\n$versionUnderline\n\n"
			. $this->generateChangelogEntryContent()
			. substr($oldChangelog, $position);

		file_put_contents($changelogPath, $newChangelog);

		return $this->repository->getDiff();
	}

	abstract protected function generateChangelogEntryContent(): string;

	public function commit(): string {
		$output = $this->repository->commit("Update CHANGELOG.md for {$this->getNextVersion()} release.");
		$output .= $this->repository->push();

		return $output;
	}

	public function setPreviousVersion(string $value): void {
		$this->previousVersion = $value;
	}

	protected function getPreviousVersion(): string {
		if ($this->previousVersion === null) {
			$tags = Semver::sort($this->repository->getTags());
			$this->previousVersion = end($tags);
		}

		return $this->previousVersion;
	}

	public function setNextVersion(string $value): void {
		$this->nextVersion = $value;
	}

	protected function getNextVersion(): string {
		if ($this->nextVersion === null) {
			$previous = $this->getPreviousVersion();
			$parts = $this->tokenizeVersion($previous);
			if ($this->isMinorUpdate()) {
				$parts['Minor']++;
				$parts['Patch'] = 0;
			} else {
				$parts['Patch']++;
			}

			$version = strtr($this->versionTemplate, $parts);
			if ($this->skipPatch && $parts['Patch'] === 0) {
				$version = substr($version, 0, -2);
			}
			$this->nextVersion = $version;
		}

		return $this->nextVersion;
	}

	protected function isMinorUpdate(): bool {
		foreach ($this->getChangedExtensions() as $changeType) {
			if ($changeType === Repository::CHANGE_ADDED) {
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
		$changes = $this->repository->getChangesFrom($this->getPreviousVersion());
		$changedExtensions = [];
		$prefix = trim($this->subsplit->getPath(), '/') . '/';
		foreach ($changes as $file => $changeType) {
			if (
				strncmp($file, $prefix, strlen($prefix)) === 0
				&& substr($file, -4) === '.yml'
				&& !in_array($file, ["{$prefix}core.yml", "{$prefix}validation.yml"])
			) {
				$changedExtensions[substr($file, strlen($prefix), -4)] = $changeType;
			}
		}

		return $changedExtensions;
	}

	protected function getCoreChanges(): array {
		$changes = $this->repository->getChangesFrom($this->getPreviousVersion());
		$coreChanges = [];
		$prefix = trim($this->subsplit->getPath(), '/') . '/';
		foreach ($changes as $file => $changeType) {
			if (in_array($file, [
				"{$prefix}core.yml",
				"{$prefix}validation.yml",
				"{$prefix}config.js",
				"{$prefix}config.css",
			])) {
				$coreChanges[substr($file, strlen($prefix))] = $changeType;
			}
		}

		return $coreChanges;
	}

	public function release(bool $draft = false): array {
		return Yii::$app->githubApi->createRelease($this->subsplit->getRepositoryUrl(), $this->getNextVersion(), [
			'draft' => $draft,
			'body' => trim($this->generateChangelogEntryContent()),
		]);
	}

	abstract public function getAnnouncement(): string;
}
