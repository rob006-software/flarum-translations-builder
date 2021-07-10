<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2021 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\readme;

use app\models\Extension;
use Yii;
use function usort;

/**
 * Class LicenseSummaryGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LicenseSummaryGenerator extends ReadmeGenerator {

	public function generate(): string {
		$extensions = $this->getExtensions();
		usort($extensions, static function (Extension $a, Extension $b) {
			return $a->getPackageName() <=> $b->getPackageName();
		});

		$output = "\n\n| Package | GitHub license | Packagist license |\n";
		$output .= "| --- | --- | --- |\n";
		foreach ($extensions as $extension) {
			$output .= "| [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()}) ";
			$githubBadge = '';
			if (Yii::$app->extensionsRepository->isGithubRepo($extension->getRepositoryUrl())) {
				[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($extension->getRepositoryUrl());
				$githubBadge = "![GitHub license](https://img.shields.io/github/license/$userName/$repoName)";
				$githubBadge = "[$githubBadge]({$extension->getRepositoryUrl()})";
			}
			$output .= "| $githubBadge ";
			$packagistBadge = "![Packagist license](https://img.shields.io/packagist/l/{$extension->getPackageName()})";
			$output .= "| [$packagistBadge](https://packagist.org/packages/{$extension->getPackageName()}) ";
			$output .= "|\n";
		}

		return $output . "\n";
	}
}
