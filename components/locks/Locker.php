<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\locks;

use yii\base\Component;
use yii\di\Instance;
use yii\mutex\Mutex;
use const APP_ROOT;

/**
 * Class Locker.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class Locker extends Component {

	private const LOCK_TIMEOUT = 900;

	/** @var Mutex|string|array */
	public $mutex = 'mutex';

	public function init(): void {
		parent::init();

		$this->mutex = Instance::ensure($this->mutex, Mutex::class);
	}

	public function acquireRepoLock(string $path = APP_ROOT . '/translations'): void {
		if (!$this->mutex->acquire(__CLASS__ . "::repo($path)", self::LOCK_TIMEOUT)) {
			throw new CannotAcquireLockException("Cannot acquire lock for repository '$path'.");
		}
	}

	public function releaseRepoLock(string $path = APP_ROOT . '/translations'): void {
		$this->mutex->release(__CLASS__ . "::repo($path)");
	}
}
