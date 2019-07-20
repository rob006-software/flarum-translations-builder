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
use GitWrapper\Event\GitLoggerEventSubscriber;
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
final class Repository {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $remote;
	private $branch;
	private $git;
	private $workingCopyDir;

	public function __construct(string $remote, string $branch, ?string $workingCopyDir = null) {
		$this->remote = $remote;
		$this->branch = $branch;
		$this->workingCopyDir = $workingCopyDir ?? (APP_ROOT . '/runtime/subsplits/' . $this->getName());

		$gitWrapper = new GitWrapper();

		$log = new Logger('git');
		$log->pushHandler(new StreamHandler(APP_ROOT . '/runtime/git-logs/' . $this->getName() . '.log', Logger::DEBUG));
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
		// @todo rebase or merge to avoid conflicts?
		$output = $this->git->checkout($this->branch);
		$output .= $this->git->pull();

		return $output;
	}

	public function commit(string $message): string {
		$output = $this->git->add('-A');
		if ($this->git->hasChanges()) {
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
}
