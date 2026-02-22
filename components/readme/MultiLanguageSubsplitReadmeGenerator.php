<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2023 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\readme;

use app\helpers\FlarumVersion;
use app\models\Extension;
use app\models\SubsplitLocale;
use function usort;

/**
 * Class MultiLanguageSubsplitReadmeGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class MultiLanguageSubsplitReadmeGenerator extends ReadmeGenerator {

	private $variants;
	private $locale;

	public function __construct(array $variants, SubsplitLocale $locale) {
		$this->variants = $variants;
		$this->locale = $locale;
	}

	public function generate(): string {
		$extensions = $this->getExtensions();
		usort($extensions, static function (Extension $a, Extension $b) {
			return $a->getPackageName() <=> $b->getPackageName();
		});
		$weblateProject = FlarumVersion::weblateProject();

		$header1 = '';
		$header2 = '';
		foreach ($this->variants as $label) {
			$header1 .= " {$this->locale->t('readme.status-table.header-status')}<br />($label) |";
			$header2 .= ' --- |';
		}
		$output = "\n\n| {$this->locale->t('readme.status-table.header-extension')} |$header1\n| --- |$header2\n";
		foreach ($extensions as $extension) {
			$output .= "| [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()}) ";
			foreach ($this->variants as $language => $_) {
				$icon = "![{$this->locale->t('readme.status-table.label-translation-status')}](https://weblate.rob006.net/widgets/{$weblateProject}/{$language}/{$extension->getId()}/svg-badge.svg)";
				$output .= "| [$icon](https://weblate.rob006.net/projects/{$weblateProject}/{$extension->getId()}/{$language}/) ";
			}
			$output .= "|\n";
		}

		return $output . "\n";
	}
}
