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
use app\models\Project;

/**
 * Class LanguageSubsplitReadmeGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguageSubsplitReadmeGenerator extends ReadmeGenerator {

	private $language;

	public function __construct(string $language, Project $project, ?array $vendors) {
		$this->language = $language;

		parent::__construct($project, $vendors);
	}

	public function generate(): string {
		$extensions = $this->getExtensions();
		usort($extensions, function (Extension $a, Extension $b) {
			return "{$a->getName()}{$this->generateVendorName($a)}" <=> "{$b->getName()}{$this->generateVendorName($b)}";
		});

		$output = "\n\n| Extension | Status |\n| --- | --- |\n";
		foreach ($extensions as $extension) {
			$output .= "| [{$extension->getName()}{$this->generateVendorName($extension)}]({$extension->getRepositoryUrl()}) ";
			$icon = "![Translation status](https://weblate.rob006.net/widgets/{$this->getProject()->getWeblateId()}/{$this->language}/{$extension->getId()}/svg-badge.svg)";
			$output .= "| [$icon](https://weblate.rob006.net/projects/{$this->getProject()->getWeblateId()}/{$extension->getId()}/{$this->language}/) ";
			$output .= "|\n";
		}

		return $output . "\n";
	}
}
