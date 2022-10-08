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

namespace app\components\languages;

use app\models\LanguageSubsplit;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Locale;
use Yii;
use function uasort;

/**
 * Class LanguagePacksSummaryGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguagePacksSummaryGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $subsplits;

	/**
	 * @param LanguageSubsplit[] $subsplits
	 */
	public function __construct(array $subsplits) {
		$this->subsplits = $subsplits;
	}

	public function generate(): string {
		$names = [];
		foreach ($this->subsplits as $subsplit) {
			/* @noinspection AmbiguousMethodsCallsInArrayMappingInspection */
			$names[$subsplit->getLanguage()] = Locale::getDisplayName($subsplit->getLanguage(), 'en');
		}
		uasort($names, static function (string $a, string $b) {
			return $a <=> $b;
		});


		$output = <<<HTML
			# Language packs summary
			
			
			<table>
			<thead>
				<tr>
					<th>Language pack</th>
					<th>Last release</th>
					<th>Activity</th>
					<th>Pull requests</th>
					<th>Downloads</th>
					<th>Translation status</th>
				</tr>
			</thead>
			<tbody>
			
			HTML;
		foreach ($names as $language => $name) {
			$subsplit = $this->subsplits[$language];
			$name = Locale::getDisplayName($subsplit->getLanguage(), 'en');
			$packageName = $subsplit->getPackageName();
			[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($subsplit->getRepositoryUrl());
			$prefix = '';
			if (empty($subsplit->getMaintainers())) {
				$prefix .= '⚠️ ';
			}
			/* @noinspection HtmlDeprecatedAttribute */
			$output .= <<<HTML
					<tr>
						<td>$prefix<a href="https://github.com/$userName/$repoName">$name</a></td>
						<td align="right">
							<a href="https://github.com/$userName/$repoName/tags">
								<img src="https://img.shields.io/github/release-date/$packageName" alt="last release" style="max-width: 160px;" />
							</a>
						</td>
						<td>
							<a href="https://github.com/$userName/$repoName/commits">
								<img src="https://img.shields.io/github/commits-since/$packageName/latest" alt="commits since last release" style="max-width: 150px;" />
							</a>
						</td>
						<td>
							<a href="https://github.com/$userName/$repoName/pulls">
								<img src="https://img.shields.io/github/issues-pr/$packageName" alt="open pull requests" />
							</a>
						</td>
						<td>
							<a href="https://packagist.org/packages/$packageName/stats">
								<img src="https://img.shields.io/packagist/dm/$packageName" alt="downloads (monthly)" />
							</a>
						</td>
						<td align="right">
							<a href="https://rob006-software.github.io/flarum-translations/status/{$subsplit->getLanguage()}.html" title="Click to see detailed translation status for each extension">
								<img src="https://weblate.rob006.net/widgets/flarum/{$subsplit->getLanguage()}/svg-badge.svg" alt="detailed translation status" />
							</a>
						</td>
					</tr>
				
				HTML;
		}

		$output .= <<<HTML
			</tbody>
			</table>
			
			HTML;

		return $output;
	}
}
