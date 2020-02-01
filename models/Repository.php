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

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use GitWrapper\Event\GitLoggerEventSubscriber;
use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use yii\helpers\Inflector;
use function file_exists;
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

	private $remote;
	private $branch;
	private $git;
	private $workingCopyDir;

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

	public function update(): string {
		$output = '';
		if ($this->branch !== null) {
			$output .= $this->git->checkout($this->branch);
		}
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

	public function getTags(): array {
		return $this->git->tags()->all();
	}

	public function getDiff(): string {
		return $this->git->diff();
	}

	/**
	 * @param string $reference
	 * @return string[] Change types (M, A or D) indexed by files paths.
	 */
	public function getChangesFrom(string $reference): array {
		$changes = explode("\n", $this->git->diff('--name-status', $reference));
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
}
