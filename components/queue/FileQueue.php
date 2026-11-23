<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\queue;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\queue\file\Queue;
use function array_values;
use function call_user_func;
use function chmod;
use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_int;
use function rewind;
use function stream_get_contents;
use function time;
use function touch;
use function usort;

/**
 * File queue with support for job priorities.
 *
 * Priority works like in DB driver - job with lower value is executed first, jobs pushed without priority get
 * `DEFAULT_PRIORITY`. Priority is stored as additional (4th) element of `waiting` and `delayed` entries in index,
 * so index stays compatible with `yii\queue\file\Queue` (entries without priority use default priority).
 *
 * Jobs with the same priority are executed in the same order as in `yii\queue\file\Queue`: delayed jobs which are
 * ready (ordered by execution time) first, then waiting jobs (FIFO). Reserved jobs with expired TTR (retries)
 * are always picked first, regardless of priority.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class FileQueue extends Queue {

	public const DEFAULT_PRIORITY = 1024;
	/** Priority for jobs which should not wait for bulk jobs, like automatic merges of release pull requests. */
	public const HIGH_PRIORITY = 100;

	protected function pushMessage($message, $ttr, $delay, $priority) {
		if ($priority === null) {
			$priority = self::DEFAULT_PRIORITY;
		} elseif (!is_int($priority)) {
			throw new InvalidArgumentException('Job priority must be an integer.');
		}

		$id = null;
		$this->touchIndex(function (&$data) use ($message, $ttr, $delay, $priority, &$id) {
			if (!isset($data['lastId'])) {
				$data['lastId'] = 0;
			}
			$id = ++$data['lastId'];
			$fileName = "$this->path/job$id.data";
			file_put_contents($fileName, $message);
			if ($this->fileMode !== null) {
				chmod($fileName, $this->fileMode);
			}
			if (!$delay) {
				$data['waiting'][] = [$id, $ttr, 0, $priority];
			} else {
				$data['delayed'][] = [$id, $ttr, time() + $delay, $priority];
				usort($data['delayed'], static function ($a, $b) {
					return [$a[2], $a[0]] <=> [$b[2], $b[0]];
				});
			}
		});

		return $id;
	}

	protected function reserve() {
		$id = null;
		$ttr = null;
		$attempt = null;
		$this->touchIndex(function (&$data) use (&$id, &$ttr, &$attempt) {
			if (!empty($data['reserved'])) {
				foreach ($data['reserved'] as $key => $payload) {
					if ($payload[1] + $payload[3] < time()) {
						[$id, $ttr, $attempt] = $payload;
						$data['reserved'][$key][2] = ++$attempt;
						$data['reserved'][$key][3] = time();
						return;
					}
				}
			}

			$selectedList = null;
			$selectedKey = null;
			$selectedPriority = null;
			foreach ($data['delayed'] ?? [] as $key => $payload) {
				if ($payload[2] > time()) {
					// delayed jobs are sorted by execution time, so remaining jobs are not ready yet
					break;
				}
				$priority = $payload[3] ?? self::DEFAULT_PRIORITY;
				if ($selectedPriority === null || $priority < $selectedPriority) {
					$selectedList = 'delayed';
					$selectedKey = $key;
					$selectedPriority = $priority;
				}
			}
			foreach ($data['waiting'] ?? [] as $key => $payload) {
				$priority = $payload[3] ?? self::DEFAULT_PRIORITY;
				if ($selectedPriority === null || $priority < $selectedPriority) {
					$selectedList = 'waiting';
					$selectedKey = $key;
					$selectedPriority = $priority;
				}
			}
			if ($selectedList === null) {
				return;
			}

			[$id, $ttr] = $data[$selectedList][$selectedKey];
			unset($data[$selectedList][$selectedKey]);
			$data[$selectedList] = array_values($data[$selectedList]);
			$attempt = 1;
			$data['reserved']["job$id"] = [$id, $ttr, $attempt, time()];
		});

		if ($id) {
			return [$id, file_get_contents("$this->path/job$id.data"), $ttr, $attempt];
		}

		return null;
	}

	/**
	 * Copy of `yii\queue\file\Queue::touchIndex()` - it is private there.
	 *
	 * @param callable $callback
	 * @throws InvalidConfigException
	 */
	private function touchIndex($callback): void {
		$fileName = "$this->path/index.data";
		$isNew = !file_exists($fileName);
		touch($fileName);
		if ($isNew && $this->fileMode !== null) {
			chmod($fileName, $this->fileMode);
		}
		if (($file = fopen($fileName, 'rb+')) === false) {
			throw new InvalidConfigException("Unable to open index file: $fileName");
		}
		if (!flock($file, LOCK_EX)) {
			fclose($file);
			throw new InvalidConfigException("Unable to flock index file: $fileName");
		}
		$data = [];
		$content = stream_get_contents($file);
		if ($content !== '') {
			$data = call_user_func($this->indexDeserializer, $content);
		}
		try {
			$callback($data);
			$newContent = call_user_func($this->indexSerializer, $data);
			if ($newContent !== $content) {
				ftruncate($file, 0);
				rewind($file);
				fwrite($file, $newContent);
				fflush($file);
			}
		} finally {
			flock($file, LOCK_UN);
			fclose($file);
		}
	}
}
