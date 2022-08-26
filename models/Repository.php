<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/* @noinspection PhpConcatenationWithEmptyStringCanBeInlinedInspection */

declare(strict_types=1);

namespace app\models;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use GitWrapper\EventSubscriber\GitLoggerEventSubscriber;
use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use yii\helpers\Inflector;
use function file_exists;
use function in_array;
use function strncmp;
use function strpos;
use function substr;
use const APP_ROOT;

/**
 * Class Repository.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class Repository {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	public const CHANGE_ADDED = 'A';
	public const CHANGE_MODIFIED = 'M';
	public const CHANGE_DELETED = 'D';

	// @see https://stackoverflow.com/a/40884093/5812455
	public const ZERO_COMMIT_HASH = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

	private $remote;
	private $branch;
	private $git;
	private $workingCopyDir;

	private $_branches;

	public function __construct(string $remote, ?string $branch, string $workingCopyDir) {
		$this->remote = $remote;
		$this->branch = $branch;
		$this->workingCopyDir = $workingCopyDir;

		$gitWrapper = new GitWrapper();

		$log = new Logger('git');
		$date = date('Y-m-d');
		$log->pushHandler(new StreamHandler(APP_ROOT . "/runtime/git-logs/{$this->getName()}-$date.log", Logger::INFO));
		$gitWrapper->addLoggerEventSubscriber(new GitLoggerEventSubscriber($log));

		if (file_exists($this->workingCopyDir)) {
			$this->git = $gitWrapper->workingCopy($this->workingCopyDir);
		} else {
			$this->git = $gitWrapper->cloneRepository($remote, $this->workingCopyDir);
		}
	}

	private function getName(): string {
		return Inflector::slug($this->remote . '--' . $this->branch);
	}

	public function update(bool $switchBranch = true): string {
		$output = '';
		if ($switchBranch && $this->branch !== null) {
			$output .= $this->git->checkout($this->branch);
		}

		$output .= $this->git->clean('-fd'); // make sure to clean all uncommitted changes
		$output .= $this->git->pull();

		return $output;
	}

	public function commit(string $message, ?bool &$committed = null): string {
		$output = $this->git->add('-A');
		$committed = $this->git->hasChanges();
		if ($committed) {
			$output .= $this->git->commit($message);
		}

		return $output;
	}

	public function push(): string {
		return $this->git->push();
	}

	public function getPath(): string {
		return $this->workingCopyDir;
	}

	public function getBranch(): ?string {
		return $this->branch;
	}

	public function getTags(): array {
		$tags = $this->git->tags()->all();
		return array_filter($tags, static function (string $tag) {
			return $tag !== '';
		});
	}

	public function getDiff(): string {
		return $this->git->diff();
	}

	public function getShortlog(...$args): string {
		return $this->git->run('shortlog', $args);
	}

	public function getCurrentRevisionHash(): string {
		return trim($this->git->run('rev-parse', ['--verify', 'HEAD']));
	}

	public function getCurrentAuthor(): string {
		$name = trim($this->git->run('config', ['user.name']));
		$email = trim($this->git->run('config', ['user.email']));

		return "$name <$email>";
	}

	public function getFirstCommitHash(): string {
		return trim($this->git->run('rev-list', ['--max-parents=0', 'HEAD']));
	}

	public function getLastCommitHash(): string {
		return trim($this->git->run('rev-parse', ['HEAD']));
	}

	/**
	 * @return string[] Change types (M, A or D) indexed by files paths.
	 */
	public function getChangesFrom(?string $reference): array {
		$changes = explode("\n", $this->git->diff('--name-status', $reference ?? self::ZERO_COMMIT_HASH));
		$changedFiles = [];
		foreach ($changes as $change) {
			$change = trim($change);
			if (!isset($change[0])) {
				continue;
			}

			$changedFiles[trim(substr($change, 1))] = $change[0];
		}

		return $changedFiles;
	}

	protected function getWorkingCopy(): GitWorkingCopy {
		return $this->git;
	}

	public function hasBranch(string $name, bool $useCache = true): bool {
		return in_array($name, $this->getBranches($useCache), true);
	}

	public function createBranch(string $name): string {
		$output = '';
		$output .= $this->getWorkingCopy()->checkoutNewBranch($name);
		$output .= $this->getWorkingCopy()->push('--set-upstream', 'origin', $name);

		return $output;
	}

	public function deleteBranch(string $name): string {
		$output = '';
		$output .= $this->getWorkingCopy()->branch('--delete', '--force', $name);
		$output .= $this->getWorkingCopy()->push('origin', '--delete', $name);

		return $output;
	}

	public function checkoutBranch(string $name): string {
		return $this->getWorkingCopy()->checkout($name)
			. $this->getWorkingCopy()->pull();
	}

	public function getBranches(bool $useCache = true): array {
		if ($this->_branches === null || !$useCache) {
			$this->_branches = $this->getWorkingCopy()->getBranches()->all();
		}

		return $this->_branches;
	}

	public function syncBranchesWithRemote(): string {
		$output = '';
		$output .= $this->getWorkingCopy()->fetchAll(['prune' => true, 'prune-tags' => true, 'force' => true]);
		$branches = $this->getWorkingCopy()->getBranches()->all();
		foreach ($branches as $branch) {
			if (strncmp($branch, 'remotes/origin/', 15) !== 0 && !in_array("remotes/origin/$branch", $branches, true)) {
				// ignore all other remotes except origin
				if (strncmp($branch, 'remotes/', 8) !== 0) {
					$output .= $this->getWorkingCopy()->branch('-D', $branch);
				}
			} elseif (
				strncmp($branch, 'remotes/origin/', 15) === 0 && strpos($branch, ' -> ') === false
				&& !in_array(substr($branch, 15), $branches, true)
			) {
				$output .= $this->getWorkingCopy()->checkout('-b', substr($branch, 15), '--track', 'origin/' . substr($branch, 15));
				$output .= $this->getWorkingCopy()->checkout($this->getBranch() ?? 'master');
			}
		}

		return $output;
	}
}
