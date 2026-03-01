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
use function file_exists;
use function in_array;
use function strncmp;
use function strpos;
use function substr;
use function trim;
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

	public function __construct(string $remote, string $branch, string $workingCopyDir) {
		$this->remote = $remote;
		$this->branch = $branch;
		$this->workingCopyDir = $workingCopyDir;

		$gitWrapper = new GitWrapper();

		$log = new Logger('git');
		$date = date('Y-m-d');
		$log->pushHandler(new StreamHandler(APP_ROOT . "/runtime/git-logs/{$this->getLogsFileName()}-$date.log", Logger::INFO));
		$gitWrapper->addLoggerEventSubscriber(new GitLoggerEventSubscriber($log));

		if (file_exists($this->workingCopyDir)) {
			$this->git = $gitWrapper->workingCopy($this->workingCopyDir);
		} else {
			$this->git = $gitWrapper->cloneRepository($remote, $this->workingCopyDir);
		}
	}

	private function getLogsFileName(): string {
		$path = explode(APP_ROOT, $this->getPath(), 2);
		$slug = strtr($path[1] ?? $path[0], [
			'/' => '-',
		]);
		$slug = trim($slug, '-');
		if (strlen($slug) > 100) {
			$slug = substr($slug, 0, 20) . '...' . substr($slug, -80);
		}

		return $slug;
	}

	public function fetch(...$args): string {
		return $this->git->fetch(...$args);
	}

	public function update(bool $switchBranch = true, bool $reset = false): string {
		$output = '';

		// abort merge if in progress
		$mergeHeadPath = trim($this->git->run('rev-parse', ['--git-path', 'MERGE_HEAD']));
		if (strncmp($mergeHeadPath, '/', 1) !== 0) {
			$mergeHeadPath = $this->git->getDirectory() . '/' . $mergeHeadPath;
		}
		if (file_exists($mergeHeadPath)) {
			$output .= $this->git->merge('--abort');
		}

		// abort rebase if in progress
		$rebaseMergePath = trim($this->git->run('rev-parse', ['--git-path', 'rebase-merge']));
		$rebaseApplyPath = trim($this->git->run('rev-parse', ['--git-path', 'rebase-apply']));
		if (strncmp($rebaseMergePath, '/', 1) !== 0) {
			$rebaseMergePath = $this->git->getDirectory() . '/' . $rebaseMergePath;
		}
		if (strncmp($rebaseApplyPath, '/', 1) !== 0) {
			$rebaseApplyPath = $this->git->getDirectory() . '/' . $rebaseApplyPath;
		}
		if (is_dir($rebaseMergePath) || is_dir($rebaseApplyPath)) {
			$output .= $this->git->rebase('--abort');
		}

		// discard ALL tracked changes
		$output .= $this->git->reset('--hard');
		// remove ALL untracked files/dirs
		$output .= $this->git->clean('-fd');

		if ($switchBranch && $this->getCurrentBranch() !== $this->branch) {
			$output .= $this->git->checkout($this->branch);
		}

		$output .= $this->git->fetchAll(['prune' => true, 'prune-tags' => true]);

		if (!$this->git->isUpToDate()) {
			if ($reset) {
				// reset to upstream
				$upstream = trim($this->git->run('rev-parse', ['--abbrev-ref', '--symbolic-full-name', '@{u}']));
				$output .= $this->git->reset('--hard', $upstream);
			} else {
				$output .= $this->git->pull();
			}
		}

		return $output;
	}

	public function merge(...$args): string {
		return $this->git->merge(...$args);
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

	public function addRemote(string $name, string $uri): string {
		if (!$this->git->hasRemote($name)) {
			return $this->git->addRemote($name, $uri);
		}

		return '';
	}

	public function getRemote(): string {
		return $this->remote;
	}

	public function getBranch(): string {
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

	public function getCurrentBranch(): string {
		return trim($this->git->run('rev-parse', ['--abbrev-ref', 'HEAD']));
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
		$changes = explode("\n", $this->git->diff('--name-status', '--no-renames', $reference ?? self::ZERO_COMMIT_HASH));
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
		$output .= $this->git->checkoutNewBranch($name);
		$output .= $this->git->push('--set-upstream', 'origin', $name);

		return $output;
	}

	public function deleteBranch(string $name): string {
		$output = '';
		$output .= $this->git->branch('--delete', '--force', $name);
		$output .= $this->git->push('origin', '--delete', $name);

		return $output;
	}

	public function checkoutBranch(string $name): string {
		return $this->git->checkout($name)
			. $this->git->pull();
	}

	public function getBranches(bool $useCache = true): array {
		if ($this->_branches === null || !$useCache) {
			$this->_branches = $this->git->getBranches()->all();
		}

		return $this->_branches;
	}

	public function syncBranchesWithRemote(): string {
		$output = '';
		$output .= $this->git->fetchAll(['prune' => true, 'prune-tags' => true, 'force' => true]);
		$branches = $this->git->getBranches()->all();
		foreach ($branches as $branch) {
			if (strncmp($branch, 'remotes/origin/', 15) !== 0 && !in_array("remotes/origin/$branch", $branches, true)) {
				// ignore all other remotes except origin
				if (strncmp($branch, 'remotes/', 8) !== 0) {
					$output .= $this->git->branch('-D', $branch);
				}
			} elseif (
				strncmp($branch, 'remotes/origin/', 15) === 0 && strpos($branch, ' -> ') === false
				&& !in_array(substr($branch, 15), $branches, true)
			) {
				$output .= $this->git->checkout('-b', substr($branch, 15), '--track', 'origin/' . substr($branch, 15));
				$output .= $this->git->checkout($this->getBranch());
			}
		}

		return $output;
	}
}
