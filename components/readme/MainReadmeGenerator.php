<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\readme;

use app\models\Extension;
use app\models\LanguageSubsplit;
use Locale;
use Yii;
use yii\helpers\Html;
use function usort;

/**
 * Class MainReadmeGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class MainReadmeGenerator extends ReadmeGenerator {

	public function generate(): string {
		$extensions = $this->getExtensions();
		usort($extensions, static function (Extension $a, Extension $b) {
			return $a->getPackageName() <=> $b->getPackageName();
		});

		$output = "\n\n| Extension ID | Package name |\n| --- | --- |\n";
		foreach ($extensions as $extension) {
			$output .= "| [`{$extension->getId()}`](https://weblate.rob006.net/projects/flarum/{$extension->getId()}) ";
			$output .= "| [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()}) ";
			$output .= "|\n";
		}

		return $output . "\n";
	}

	/**
	 * @param LanguageSubsplit[] $subsplits
	 */
	public function generateLanguagesList(array $subsplits): string {
		$names = [];
		foreach ($subsplits as $subsplit) {
			/* @noinspection AmbiguousMethodsCallsInArrayMappingInspection */
			$names[$subsplit->getLanguage()] = Locale::getDisplayName($subsplit->getLanguage(), 'en');
		}
		uasort($names, static function (string $a, string $b) {
			return $a <=> $b;
		});

		$output = <<<HTML
			<table>
			<thead>
				<tr>
					<th>Language</th>
					<th>Maintainers</th>
					<th>Translation status</th>
				</tr>
			</thead>
			<tbody>
			
			HTML;
		foreach ($names as $language => $name) {
			$subsplit = $subsplits[$language];
			[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($subsplit->getRepositoryUrl());
			/* @noinspection HtmlDeprecatedAttribute */
			$output .= <<<HTML
					<tr>
						<td><a href="https://github.com/$userName/$repoName">$name</a></td>
						<td>{$this->generateMaintainersUrls($subsplit->getMaintainers())}</td>
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

		return "\n\n$output\n";
	}

	private function generateMaintainersUrls(array $maintainers): string {
		if (empty($maintainers)) {
			return '-';
		}

		$links = [];
		foreach ($maintainers as $maintainer) {
			$links[] = Html::a(Html::encode($maintainer), "https://github.com/$maintainer/");
		}

		return implode(', ', $links);
	}
}
