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

use app\helpers\FlarumVersion;
use app\models\Extension;
use function usort;

/**
 * Class SummaryGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class SummaryGenerator extends ReadmeGenerator {

	public function generate(): string {
		$extensions = $this->getExtensions();
		usort($extensions, static function (Extension $a, Extension $b) {
			return $a->getPackageName() <=> $b->getPackageName();
		});
		$weblateProject = FlarumVersion::weblateProject();

		$output = "\n\n| Extension | Status |\n| --- | --- |\n";
		foreach ($extensions as $extension) {
			$output .= "| [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()}) ";
			$icon = "![Translation status](https://weblate.rob006.net/widgets/{$weblateProject}/-/{$extension->getId()}/multi-auto.svg)";
			$output .= "| [$icon](https://weblate.rob006.net/projects/{$weblateProject}/{$extension->getId()}/) ";
			$output .= "|\n";
		}

		return $output . "\n";
	}
}
