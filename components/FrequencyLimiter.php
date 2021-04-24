<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2021 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components;

use Closure;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\di\Instance;
use yii\mutex\Mutex;

/**
 * Class FrequencyLimiter.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class FrequencyLimiter extends Component {

	/** @var string|array|CacheInterface */
	public $cache = 'cache';
	/** @var string|array|Mutex */
	public $mutex = 'mutex';

	public function init() {
		parent::init();

		$this->cache = Instance::ensure($this->cache, CacheInterface::class);
		$this->mutex = Instance::ensure($this->mutex, Mutex::class);
	}

	/**
	 * @param int $count Max number of executions for given frequency.
	 * @return bool `true` if callback was run, `false` if it was limited.
	 */
	public function run(string $key, int $frequency, ?Closure $callback, int $count = 1): bool {
		for ($i = 1; $i <= $count; $i++) {
			if ($this->runInternal("$key::$i", $frequency, $callback)) {
				return true;
			}
		}

		return false;
	}

	private function runInternal(string $key, int $frequency, ?Closure $callback): bool {
		// early return if callback is limited
		$limited = $this->cache->get($key);
		if ($limited) {
			return false;
		}

		$callback = function () use ($key, $frequency, $callback) {
			// check again in case of status changed between previous check
			$limited = $this->cache->get($key);
			if ($limited) {
				return false;
			}

			if ($callback !== null) {
				$callback();
			}
			$this->cache->set($key, true, $frequency);
			return true;
		};

		if ($this->mutex->acquire(__METHOD__ . "#$key")) {
			try {
				$result = $callback();
			} finally {
				$this->mutex->release(__METHOD__ . "#$key");
			}
			return $result;
		}

		return false;
	}
}
