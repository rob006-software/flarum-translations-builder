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

use app\models\RegularExtension;
use app\models\Project;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Locale;
use Webmozart\Assert\Assert;
use Yii;
use yii\helpers\Html;
use yii\helpers\StringHelper;
use function mb_strlen;
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
	/** @var bool */
	private $disabledExtensions = [];
	/** @var int[][] */
	private $stats = [];

	/** @var string */
	private $language;
	/** @var Project[] */
	private $projects = [];
	private $sortingCriteria;

	/**
	 * LanguageStatsReadmeGenerator constructor.
	 *
	 * @param string $language
	 * @param Project[] $projects
	 * @param string $sortingCriteria
	 */
	public function __construct(string $language, array $projects, string $sortingCriteria = 'monthly') {
		$this->language = $language;
		$this->sortingCriteria = $sortingCriteria;
		foreach ($projects as $project) {
			Assert::isInstanceOf($project, Project::class);
			$this->projects[$project->getId()] = $project;
		}
	}

	public function addExtension(RegularExtension $extension, bool $isDisabled): void {
		$this->extensions[] = $extension;
		$this->stats[$extension->getId()] = $this->getStats($extension->getPackageName());
		$this->disabledExtensions[$extension->getId()] = $isDisabled;
	}

	public function generate(): string {
		$extensions = $this->extensions;
		usort($extensions, function (RegularExtension $a, RegularExtension $b) {
			$result = $this->stats[$b->getId()][$this->sortingCriteria] <=> $this->stats[$a->getId()][$this->sortingCriteria];
			if ($result === 0) {
				$result = $this->stats[$b->getId()]['total'] <=> $this->stats[$a->getId()]['total'];
			}

			return $result;
		});

		$languageName = Locale::getDisplayLanguage($this->language, 'en');
		$output = <<<HTML
# $languageName translation status of extensions


<!--suppress HtmlDeprecatedAttribute -->
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
			$this->saveStats($extension->getPackageName(), 'total', $this->stats[$extension->getId()]['total']);
			$this->saveStats($extension->getPackageName(), 'monthly', $this->stats[$extension->getId()]['monthly']);
			$this->saveStats($extension->getPackageName(), 'daily', $this->stats[$extension->getId()]['daily']);
			$this->saveStats($extension->getPackageName(), 'rank', $rank);

			$project = $this->projects[$extension->getProjectId()];
			if ($this->disabledExtensions[$extension->getId()]) {
				$statusIcon = $this->image('https://img.shields.io/badge/status-disabled-inactive.svg', 'Translation status');
			} else {
				$icon = $this->image("https://weblate.rob006.net/widgets/{$project->getWeblateId()}/{$this->language}/{$extension->getId()}/svg-badge.svg", 'Translation status');
				$statusIcon = $this->link($icon, "https://weblate.rob006.net/projects/{$project->getWeblateId()}/{$extension->getId()}/{$this->language}/");
			}

			$output .= <<<HTML
	<tr>
		<td>{$this->link("<code>{$this->truncate($extension->getPackageName())}</code>", $extension->getRepositoryUrl(), $extension->getPackageName())}</td>
		<td align="center">{$rank}{$this->statsChangeBadge($extension->getPackageName(), 'rank', $rank, true)}</td>
		<td align="center">{$this->stats($extension, 'total')}</td>
		<td align="center">{$this->stats($extension, 'monthly')}</td>
		<td align="center">{$this->stats($extension, 'daily')}</td>
		<td>$statusIcon</td>
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

	private function stats(RegularExtension $extension, string $statsType) : string {
		$badge = $this->statsChangeBadge($extension->getPackageName(), $statsType, $this->stats[$extension->getId()][$statsType]);
		$statsUrl = "https://packagist.org/packages/{$extension->getPackageName()}/stats";

		return $this->link($this->stats[$extension->getId()][$statsType] . $badge, $statsUrl);
	}

	private function statsChangeBadge(string $packageName, string $statsType, int $currentValue, bool $reverseColor = false): string {
		$old = $this->getPreviousStats($packageName, $statsType);
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

	private function getStats(string $name): array {
		$stats = Yii::$app->extensionsRepository->getPackagistData($name);
		$defaultStats = [
			'total' => 0,
			'monthly' => 0,
			'daily' => 0,
		];
		if ($stats === null || empty($stats['downloads'])) {
			return $defaultStats;
		}

		return $stats['downloads'] + $defaultStats;
	}

	private function getPreviousStats(string $packageName, string $statsType): ?int {
		$value = Yii::$app->cache->get($this->buildStatsKey($packageName, $statsType, strtotime('-7 days')));
		if ($value === false) {
			$value = Yii::$app->cache->get($this->buildStatsKey($packageName, $statsType, strtotime('-14 days')));
		}

		return $value === false ? null : $value;
	}

	private function saveStats(string $packageName, string $statsType, int $value): void {
		Yii::$app->cache->add($this->buildStatsKey($packageName, $statsType), $value, 31 * 24 * 3600);
	}

	private function buildStatsKey(string $packageName, string $statsType, ?int $timestamp = null): string {
		return __CLASS__ . "#stats:$packageName:$statsType:" . date('W', $timestamp ?? time());
	}
}
