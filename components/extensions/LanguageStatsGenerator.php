<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/* @noinspection UnknownInspectionInspection */
/* @noinspection HtmlDeprecatedAttribute */

declare(strict_types=1);

namespace app\components\extensions;

use app\helpers\StringHelper;
use app\models\Extension;
use app\models\PremiumExtension;
use app\models\RegularExtension;
use app\models\SubsplitLocale;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Locale;
use Yii;
use yii\base\InvalidArgumentException;
use yii\helpers\Html;
use function mb_strlen;
use function strtotime;
use function urlencode;
use function usort;

/**
 * Class LanguageStatsGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguageStatsGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var RegularExtension[] */
	private $extensions = [];
	/** @var PremiumExtension[] */
	private $premiumExtensions = [];
	/** @var bool[] */
	private $disabledExtensions = [];
	/** @var bool[]|null[] */
	private $outdatedExtensions = [];

	/** @var string */
	private $language;
	/** @var SubsplitLocale  */
	private $locale;
	private $sortingCriteria;

	public function __construct(string $language, SubsplitLocale $locale, string $sortingCriteria = 'monthly') {
		$this->language = $language;
		$this->locale = $locale;
		$this->sortingCriteria = $sortingCriteria;
	}

	public function addExtension(Extension $extension, bool $isDisabled, ?bool $isOutdated): void {
		if ($extension instanceof RegularExtension) {
			$this->extensions[] = $extension;
		} elseif ($extension instanceof PremiumExtension) {
			$this->premiumExtensions[] = $extension;
		}
		$this->disabledExtensions[$extension->getId()] = $isDisabled;
		$this->outdatedExtensions[$extension->getId()] = $isOutdated;
	}

	public function generate(): string {
		$languageName = Locale::getDisplayName($this->language, 'en');
		return "# $languageName translation status \n\n\n"
			. $this->generateCore() . "\n\n"
			. $this->generateRegularExtensions() . "\n\n"
			. $this->generatePremiumExtensions();
	}

	public function generateCore(): string {
		return <<<HTML
			## Flarum core
			
			| Component | Status |
			| --- | --- |
			| [Core](https://github.com/flarum/core) | [![Translation status](https://weblate.rob006.net/widgets/flarum/{$this->language}/core/svg-badge.svg)](https://weblate.rob006.net/projects/flarum/core/{$this->language}/) |
			| Validation | [![Translation status](https://weblate.rob006.net/widgets/flarum/{$this->language}/validation/svg-badge.svg)](https://weblate.rob006.net/projects/flarum/validation/{$this->language}/) |
			
			HTML;
	}

	public function generateRegularExtensions(): string {
		$extensions = $this->extensions;
		usort($extensions, function (RegularExtension $a, RegularExtension $b) {
			$result = $this->getCurrentStats($b, $this->sortingCriteria) <=> $this->getCurrentStats($a, $this->sortingCriteria);
			if ($result === 0) {
				$result = $this->getCurrentStats($b, 'total') <=> $this->getCurrentStats($a, 'total');
			}
			if ($result === 0) {
				$result = $a->getPackageName() <=> $b->getPackageName();
			}

			return $result;
		});

		$output = <<<HTML
			## Free extensions
			
			<table>
			<thead>
				<tr>
					<th rowspan="2">Extension</th>
					<th rowspan="2">Rank</th>
					<th align="center" colspan="3">Downloads</th>
					<th rowspan="2">Status</th>
				</tr>
				<tr>
					<th align="center">total</th>
					<th align="center">monthly</th>
					<th align="center">daily</th>
				</tr>
			</thead>
			<tbody>
			
			HTML;

		$rank = 0;
		foreach ($extensions as $extension) {
			$rank++;
			$this->saveStats($extension->getPackageName(), 'rank', $rank);

			$output .= <<<HTML
					<tr>
						<td>
							{$this->compatibilityIcon($extension)}{$this->abandonedIcon($extension)}
							{$this->link("<code>{$this->truncate($extension->getPackageName())}</code>", $extension->getRepositoryUrl(), $extension->getPackageName())}
						</td>
						<td align="center">{$rank}{$this->statsChangeBadge($extension, 'rank', $rank, true)}</td>
						<td align="center">{$this->stats($extension, 'total')}</td>
						<td align="center">{$this->stats($extension, 'monthly')}</td>
						<td align="center">{$this->stats($extension, 'daily')}</td>
						<td>{$this->statusBadge($extension)}</td>
					</tr>
				
				HTML;
		}

		$output .= <<<HTML
			</tbody>
			</table>
			
			HTML;

		return $output;
	}

	public function generatePremiumExtensions(): string {
		$extensions = $this->premiumExtensions;
		usort($extensions, function (PremiumExtension $a, PremiumExtension $b) {
			$result = $this->getCurrentStats($b, 'subscribers') <=> $this->getCurrentStats($a, 'subscribers');
			if ($result === 0) {
				$result = $this->getCurrentStats($b, 'downloads') <=> $this->getCurrentStats($a, 'downloads');
			}
			if ($result === 0) {
				$result = $a->getPackageName() <=> $b->getPackageName();
			}

			return $result;
		});

		$output = <<<HTML
			## Premium extensions
			
			<table>
			<thead>
				<tr>
					<th>Extension</th>
					<th>Rank</th>
					<th>Subscribers</th>
					<th>Downloads</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
			
			HTML;

		$rank = 0;
		foreach ($extensions as $extension) {
			$rank++;
			$this->saveStats($extension->getPackageName(), 'rank', $rank);

			$output .= <<<HTML
				<tr>
					<td>
						{$this->compatibilityIcon($extension)}
						{$this->link("<code>{$this->truncate($extension->getPackageName())}</code>", $extension->getRepositoryUrl(), $extension->getPackageName())}
					</td>
					<td align="center">{$rank}{$this->statsChangeBadge($extension, 'rank', $rank, true)}</td>
					<td align="center">{$this->premiumStats($extension, 'subscribers')}</td>
					<td align="center">{$this->premiumStats($extension, 'downloads')}</td>
					<td>{$this->statusBadge($extension)}</td>
				</tr>
			
			HTML;
		}

		$output .= <<<HTML
			</tbody>
			</table>
			
			HTML;

		return $output;
	}

	private function link(string $text, string $url, ?string $title = null): string {
		return Html::a($text, $url, [
			'title' => $title,
		]);
	}

	private function image(string $src, ?string $alt = null): string {
		return Html::img($src, [
			'alt' => $alt,
		]);
	}

	private function stats(RegularExtension $extension, string $statsType): string {
		$badge = $this->statsChangeBadge($extension, $statsType, $this->getCurrentStats($extension, $statsType));
		$statsUrl = "https://packagist.org/packages/{$extension->getPackageName()}/stats";

		return $this->link($this->getCurrentStats($extension, $statsType) . $badge, $statsUrl);
	}

	private function premiumStats(PremiumExtension $extension, string $statsType): string {
		return $this->getCurrentStats($extension, $statsType)
			. $this->statsChangeBadge($extension, $statsType, $this->getCurrentStats($extension, $statsType));
	}

	private function statsChangeBadge(Extension $extension, string $statsType, int $currentValue, bool $reverseColor = false): string {
		$old = $this->getPreviousStats($extension, $statsType);
		if ($old === null) {
			return '';
		}
		$change = $currentValue - $old;
		if ($change > 0) {
			$label = "+$change";
			$color = $reverseColor ? 'red' : 'brightgreen';
		} elseif ($change < 0) {
			$label = (string) $change;
			$color = $reverseColor ? 'brightgreen' : 'red';
		} else {
			$label = '~';
			$color = 'lightgrey';
		}

		return '<br />' . Html::img('https://img.shields.io/badge/-' . urlencode($label) . '-' . $color, [
				'alt' => $label,
				'title' => 'Change from last week',
			]);
	}

	private function compatibilityIcon(Extension $extension): string {
		if ($this->outdatedExtensions[$extension->getId()] === null) {
			// @see https://emojipedia.org/large-yellow-circle/
			return '<span title="Compatibility status with recent Flarum is unknown">🟡</span>';
		}

		if ($this->outdatedExtensions[$extension->getId()]) {
			// @see https://emojipedia.org/large-red-circle/
			return '<span title="Incompatible with recent Flarum">🔴</span>';
		}

		// @see https://emojipedia.org/large-green-circle/
		return '<span title="Compatible with recent Flarum">🟢</span>';
	}

	private function abandonedIcon(Extension $extension): string {
		if ($extension->isAbandoned()) {
			// @see https://emojipedia.org/exclamation-mark/
			return '<span title="Extension is abandoned">❗</span>';
		}

		return '';
	}

	private function statusBadge(Extension $extension): string {
		if ($this->disabledExtensions[$extension->getId()]) {
			return $this->image('https://img.shields.io/badge/status-disabled-inactive.svg', 'Translation status');
		}

		$icon = $this->image("https://weblate.rob006.net/widgets/flarum/{$this->language}/{$extension->getId()}/svg-badge.svg", 'Translation status');
		return $this->link($icon, "https://weblate.rob006.net/projects/flarum/{$extension->getId()}/{$this->language}/");
	}

	private function truncate(string $string, int $limit = 40): string {
		if (mb_strlen($string) <= $limit) {
			return $string;
		}

		$string = strtr($string, [
			'/flarum-ext-' => '/…',
			'/flarum-' => '/…',
		]);

		if (mb_strlen($string) <= $limit) {
			return $string;
		}

		return StringHelper::truncate($string, $limit, '…');
	}

	private function getCurrentStats(Extension $extension, string $statsType): int {
		return Yii::$app->cache->getOrSet($this->buildStatsKey($extension->getPackageName(), $statsType), static function () use ($extension, $statsType) {
			switch ($statsType) {
				case 'subscribers':
					return Yii::$app->stats->getStats($extension)->getSubscribersCount();
				case 'downloads':
				case 'total':
					return Yii::$app->stats->getStats($extension)->getTotalDownloads();
				case 'monthly':
					return Yii::$app->stats->getStats($extension)->getMonthlyDownloads();
				case 'daily':
					return Yii::$app->stats->getStats($extension)->getDailyDownloads();
				default:
					throw new InvalidArgumentException("Invalid stats type: '$statsType'.");
			}
		});
	}

	private function getPreviousStats(Extension $extension, string $statsType): ?int {
		$value = Yii::$app->cache->get($this->buildStatsKey($extension->getPackageName(), $statsType, strtotime('-7 days')));
		if ($value === false) {
			$value = Yii::$app->cache->get($this->buildStatsKey($extension->getPackageName(), $statsType, strtotime('-14 days')));
		}

		return $value === false ? null : $value;
	}

	private function saveStats(string $packageName, string $statsType, int $value): void {
		Yii::$app->cache->add($this->buildStatsKey($packageName, $statsType), $value, 31 * 24 * 3600);
	}

	private function buildStatsKey(string $packageName, string $statsType, ?int $timestamp = null): string {
		return __CLASS__ . "#stats:$packageName:$statsType:" . StatsRepository::getWeek($timestamp);
	}
}
