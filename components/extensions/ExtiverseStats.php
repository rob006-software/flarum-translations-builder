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

namespace app\components\extensions;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;

/**
 * Class ExtiverseStats.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ExtiverseStats {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $total;
	private $subscribers;

	public function __construct(array $stats) {
		$this->subscribers = (int) ($stats['subscribers'] ?? 0);
		$this->total = (int) ($stats['total'] ?? 0);
	}

	public function getSubscribersCount(): int {
		return $this->subscribers;
	}

	public function getTotalDownloads(): int {
		return $this->total;
	}
}
