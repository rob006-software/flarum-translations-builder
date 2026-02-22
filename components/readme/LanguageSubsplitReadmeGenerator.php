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

use app\helpers\FlarumVersion;
use app\models\Extension;
use app\models\SubsplitLocale;

/**
 * Class LanguageSubsplitReadmeGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguageSubsplitReadmeGenerator extends ReadmeGenerator {

	private $language;
	private $locale;

	public function __construct(string $language, SubsplitLocale $locale) {
		$this->language = $language;
		$this->locale = $locale;
	}

	public function generate(): string {
		$extensions = $this->getExtensions();
		usort($extensions, static function (Extension $a, Extension $b) {
			return $a->getPackageName() <=> $b->getPackageName();
		});

		$weblateProject = FlarumVersion::weblateProject();
		$output = "\n\n| {$this->locale->t('readme.status-table.header-extension')} | {$this->locale->t('readme.status-table.header-status')} |\n| --- | --- |\n";
		foreach ($extensions as $extension) {
			$output .= "| [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()}) ";
			$icon = "![{$this->locale->t('readme.status-table.label-translation-status')}](https://weblate.rob006.net/widgets/{$weblateProject}/{$this->language}/{$extension->getId()}/svg-badge.svg)";
			$output .= "| [$icon](https://weblate.rob006.net/projects/{$weblateProject}/{$extension->getId()}/{$this->language}/) ";
			$output .= "|\n";
		}

		return $output . "\n";
	}
}
