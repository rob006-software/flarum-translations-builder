<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\inheritors;

use app\helpers\Language;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function count;
use function strtr;
use function uasort;

/**
 * Generates navigation for summaries of an inheritor which handles multiple languages at once.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class InheritorLanguagesSummaryGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var InheritorInterface */
	private $inheritor;
	/** @var int */
	private $languagesCount;
	/** @var array[] */
	private $languages = [];

	/**
	 * @param int $languagesCount Number of all compared languages, including languages without any difference.
	 */
	public function __construct(InheritorInterface $inheritor, int $languagesCount) {
		$this->inheritor = $inheritor;
		$this->languagesCount = $languagesCount;
	}

	/**
	 * @param string $fileName Path of the report relative to this summary.
	 */
	public function addLanguage(TranslationsInheritor $inheritor, InheritorComparison $comparison, string $fileName): void {
		$this->languages[$inheritor->getLanguage()] = [
			'name' => Language::name($inheritor->getLanguage()),
			'language' => $inheritor->getLanguage(),
			'fileName' => $fileName,
			'differencesCount' => $comparison->getDifferencesCount(),
			'missingTranslationsCount' => $comparison->getMissingTranslationsCount(),
			'componentsCount' => $comparison->getComponentsCount(),
		];
	}

	public function generate(): string {
		$languages = $this->languages;
		uasort($languages, static function (array $a, array $b) {
			return $a['name'] <=> $b['name'];
		});

		$output = strtr(<<<MD
			# Translations inherited from {fromLabel}

			Translations for **{languagesCount}** languages are inherited from {fromLabel}, but they can be adjusted
			independently after inheritance. Reports below list all strings which have the same source string on both
			sides, but do not match between them - **{reportsCount}** languages have such strings, the remaining ones
			are fully in sync and are not listed here.

			| Language | Different translations | Missing translations | Components |
			| --- | --- | --- | --- |

			MD, [
			'{fromLabel}' => $this->inheritor->getInheritFromLabel(),
			'{languagesCount}' => $this->languagesCount,
			'{reportsCount}' => count($languages),
		]);

		foreach ($languages as $language) {
			$output .= "| [{$language['name']}]({$language['fileName']}) (`{$language['language']}`) "
				. "| {$language['differencesCount']} "
				. "| {$language['missingTranslationsCount']} "
				. "| {$language['componentsCount']} |\n";
		}

		return $output;
	}
}
