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

use app\models\Extension;
use app\models\PremiumExtension;
use Yii;
use yii\base\Component;
use function date;
use function json_encode;
use function strtotime;
use function time;

/**
 * Class StatsRepository.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class StatsRepository extends Component {

	/**
	 * @return ExtiverseStats|PackagistStats
	 */
	public function getStats(Extension $extension) {
		if ($extension instanceof PremiumExtension) {
			return $this->getStatsFromExtiverse($extension->getPackageName());
		}

		return $this->getStatsFromPackagist($extension->getPackageName());
	}

	private function getStatsFromPackagist(string $name): PackagistStats {
		return Yii::$app->cache->getOrSet($this->buildStatsKey($name), static function () use ($name) {
			// @todo this is temporary logging to debug some weird stats behavior
			Yii::info("Fetch packagist stats for $name: " . json_encode($stats['downloads'] ?? [], JSON_THROW_ON_ERROR), 'packagist.stats');
			$stats = Yii::$app->extensionsRepository->getPackagistData($name);
			if ($stats === null || empty($stats['downloads'])) {
				return new PackagistStats([]);
			}

			return new PackagistStats($stats['downloads']);
		}, 31 * 24 * 3600);
	}

	private function getStatsFromExtiverse(string $name): ExtiverseStats {
		return Yii::$app->cache->getOrSet($this->buildStatsKey($name), static function () use ($name) {
			$stats = Yii::$app->extiverseApi->searchExtensions()[$name] ?? null;
			if ($stats === null) {
				return new ExtiverseStats([]);
			}

			return new ExtiverseStats([
				'total' => $stats->getDownloads(),
				'subscribers' => $stats->getSubscribers(),
			]);
		}, 31 * 24 * 3600);
	}

	private function buildStatsKey(string $packageName, ?int $timestamp = null): string {
		$week = self::getWeek($timestamp);
		return __CLASS__ . "::stats($packageName, $week)";
	}

	public static function getWeek(?int $timestamp = null): string {
		// We're delaying beginning of the week to Tuesday. In Monday stats from last 24h will include Sunday, so
		// they're not measurable.
		return date('W', strtotime('-1 day', $timestamp ?? time()));
	}
}
