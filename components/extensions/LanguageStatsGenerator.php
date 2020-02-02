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

use app\models\Extension;
use app\models\Project;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Locale;
use Webmozart\Assert\Assert;
use Yii;
use yii\helpers\Html;
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

	/** @var Extension[] */
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

	public function addExtension(Extension $extension, bool $isDisabled): void {
		$this->extensions[] = $extension;
		$this->stats[$extension->getId()] = $this->getStats($extension->getPackageName());
		$this->disabledExtensions[$extension->getId()] = $isDisabled;
	}

	public function generate(): string {
		$extensions = $this->extensions;
		usort($extensions, function (Extension $a, Extension $b) {
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
		foreach ($extensions as $extension) {
			$statsUrl = "https://packagist.org/packages/{$extension->getPackageName()}/stats";
			$project = $this->projects[$extension->getProjectId()];
			if ($this->disabledExtensions[$extension->getId()]) {
				$statusIcon = $this->image('https://img.shields.io/badge/status-disabled-inactive.svg', 'Translation status');
			} else {
				$icon = $this->image("https://weblate.rob006.net/widgets/{$project->getWeblateId()}/{$this->language}/{$extension->getId()}/svg-badge.svg", 'Translation status');
				$statusIcon = $this->link($icon, "https://weblate.rob006.net/projects/{$project->getWeblateId()}/{$extension->getId()}/{$this->language}/");
			}
			$output .= <<<HTML
	<tr>
		<td>{$this->link("<code>{$extension->getPackageName()}</code>", $extension->getRepositoryUrl())}</td>
		<td align="center">{$this->link((string) $this->stats[$extension->getId()]['total'], $statsUrl)}</td>
		<td align="center">{$this->link((string) $this->stats[$extension->getId()]['monthly'], $statsUrl)}</td>
		<td align="center">{$this->link((string) $this->stats[$extension->getId()]['daily'], $statsUrl)}</td>
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
}
