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
 * Class PackagistStats.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PackagistStats {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $daily;
	private $monthly;
	private $total;

	public function __construct(array $stats) {
		$this->daily = (int) ($stats['daily'] ?? 0);
		$this->monthly = (int) ($stats['monthly'] ?? 0);
		$this->total = (int) ($stats['total'] ?? 0);
	}

	public function getDailyDownloads(): int {
		return $this->daily;
	}

	public function getMonthlyDownloads(): int {
		return $this->monthly;
	}

	public function getTotalDownloads(): int {
		return $this->total;
	}
}
