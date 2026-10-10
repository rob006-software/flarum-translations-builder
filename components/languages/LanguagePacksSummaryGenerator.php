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

use app\helpers\FlarumVersion;
use app\models\LanguageSubsplit;
use app\models\Translations;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
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
			$names[$subsplit->getLanguage()] = Translations::$instance->getLanguageName($subsplit->getLanguage());
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
					<th>Downloads</th>
					<th>Translation status</th>
				</tr>
			</thead>
			<tbody>
			
			HTML;
		foreach ($names as $language => $name) {
			$weblateProject = FlarumVersion::weblateProject();
			$branchName = FlarumVersion::branch();
			$subsplit = $this->subsplits[$language];
			$name = Translations::$instance->getLanguageName($subsplit->getLanguage());
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
								<img src="https://img.shields.io/github/release-date/$userName/$repoName" alt="last release" />
							</a>
						</td>
						<td align="right">
							<a href="https://packagist.org/packages/$packageName/stats">
								<img src="https://img.shields.io/packagist/dm/$packageName" alt="downloads (monthly)" />
							</a>
						</td>
						<td align="right">
							<a href="https://rob006-software.github.io/flarum-translations/{$branchName}/status/{$subsplit->getLanguage()}.html" title="Click to see detailed translation status for each extension">
								<img src="https://weblate.rob006.net/widgets/{$weblateProject}/{$subsplit->getLanguage()}/svg-badge.svg" alt="detailed translation status" />
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
