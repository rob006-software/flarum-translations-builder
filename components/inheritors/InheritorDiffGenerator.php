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

use app\helpers\FlarumVersion;
use app\helpers\Language;
use app\helpers\TextDiff;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function basename;
use function count;
use function explode;
use function htmlspecialchars;
use function implode;
use function max;
use function preg_match_all;
use function preg_replace;
use function rawurlencode;
use function str_repeat;
use function strlen;
use function strtr;
use function trim;
use const ENT_NOQUOTES;
use const ENT_SUBSTITUTE;

/**
 * Generates summary of differences between translations inherited from one language into another one.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class InheritorDiffGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private const WEBLATE_URL = 'https://weblate.rob006.net';

	/** @var TranslationsInheritor */
	private $inheritor;
	/** @var InheritorComparison */
	private $comparison;

	public function __construct(TranslationsInheritor $inheritor, InheritorComparison $comparison) {
		$this->inheritor = $inheritor;
		$this->comparison = $comparison;
	}

	public function generate(): string {
		$output = $this->generateHeader();
		if ($this->comparison->isEmpty()) {
			return $output . "\nThere are no differences between these translations. 🎉\n";
		}

		// translations may contain ICU placeholders like `{{count}}`, which are interpreted as Liquid syntax by Jekyll
		// on GitHub Pages - `{% raw %}` prevents that, and since it is wrapped in a HTML comment, it stays invisible
		// in Markdown rendered by GitHub
		$output .= "\n<!-- {% raw %} -->\n";
		$output .= $this->generateContents();
		$output .= $this->generateDifferences();
		$output .= $this->generateMissingTranslations();

		return $output . "\n<!-- {% endraw %} -->\n";
	}

	private function generateHeader(): string {
		$language = $this->inheritor->getLanguage();

		return strtr(<<<MD
			# {name} inherited translations differences

			Translations for {name} (`{language}`) are inherited from {fromLabel}, but they can be adjusted
			independently after inheritance. This page lists all strings which have the same source string on both
			sides, but do not match between them: **{count}** are translated differently and **{missingCount}** are
			translated only in `{language}`. Altogether they cover **{componentsCount}** components.

			MD, [
			'{name}' => Language::name($language),
			'{language}' => $language,
			'{fromLabel}' => $this->inheritor->getInheritFromLabel(),
			'{count}' => $this->comparison->getDifferencesCount(),
			'{missingCount}' => $this->comparison->getMissingTranslationsCount(),
			'{componentsCount}' => $this->comparison->getComponentsCount(),
		]);
	}

	private function generateContents(): string {
		$hasDifferences = $this->comparison->getDifferencesCount() > 0;
		$hasMissingTranslations = $this->comparison->getMissingTranslationsCount() > 0;

		$header = '| Component |';
		$separator = '| --- |';
		if ($hasDifferences) {
			$header .= ' Different translations |';
			$separator .= ' --- |';
		}
		if ($hasMissingTranslations) {
			$header .= ' Missing translations |';
			$separator .= ' --- |';
		}

		$output = "\n\n## Contents\n\n$header\n$separator\n";
		foreach ($this->comparison->getComponents() as $fileName) {
			$component = $this->getComponentId($fileName);
			$output .= "| `$component` |";
			if ($hasDifferences) {
				$output .= ' ' . $this->contentsCell($this->comparison->getDifferences()[$fileName] ?? [], "#$component") . ' |';
			}
			if ($hasMissingTranslations) {
				$output .= ' ' . $this->contentsCell($this->comparison->getMissingTranslations()[$fileName] ?? [], "#$component-missing") . ' |';
			}
			$output .= "\n";
		}

		return $output;
	}

	private function contentsCell(array $entries, string $anchor): string {
		$count = count($entries);

		return $count === 0 ? '0' : "[$count]($anchor)";
	}

	private function generateDifferences(): string {
		if ($this->comparison->getDifferencesCount() === 0) {
			return '';
		}

		$language = $this->inheritor->getLanguage();
		$fromLabel = $this->inheritor->getInheritFromLabel();
		$output = "\n\n## Different translations\n\n"
			. "Each entry contains the English source string, followed by a diff between the translation from "
			. "$fromLabel (`-` line) and the translation from `$language` (`+` line). Changed words are "
			. "additionally marked as <del>removed</del> and <ins>added</ins> below the diff.\n";
		foreach ($this->comparison->getDifferences() as $fileName => $differences) {
			$component = $this->getComponentId($fileName);
			$output .= "\n\n### `$component`\n";
			foreach ($differences as $key => $difference) {
				$output .= $this->generateDifference($component, $key, $difference);
			}
		}

		return $output;
	}

	private function generateDifference(string $component, string $key, array $difference): string {
		$output = "\n#### [`$key`]({$this->weblateStringUrl($component, $key)})\n\n";
		$output .= $this->blockquote($difference['source']) . "\n\n";
		$output .= $this->diffBlock($difference['from'], $difference['to']) . "\n";

		$highlight = $this->highlight($difference['from'], $difference['to']);
		if ($highlight !== null) {
			$output .= "\n$highlight\n";
		}

		return $output;
	}

	private function generateMissingTranslations(): string {
		if ($this->comparison->getMissingTranslationsCount() === 0) {
			return '';
		}

		$language = $this->inheritor->getLanguage();
		$fromLabel = $this->inheritor->getInheritFromLabel();
		$output = "\n\n## Missing translations\n\n"
			. "These strings are translated only in `$language`, so there is nothing to inherit from $fromLabel - "
			. "they could be used to fill the gaps there. Each entry contains the English source string, followed by "
			. "the translation available only in `$language`.\n";
		foreach ($this->comparison->getMissingTranslations() as $fileName => $missingTranslations) {
			$component = $this->getComponentId($fileName);
			$output .= "\n\n### `$component` (missing)\n";
			foreach ($missingTranslations as $key => $missingTranslation) {
				$output .= $this->generateMissingTranslation($component, $key, $missingTranslation);
			}
		}

		return $output;
	}

	private function generateMissingTranslation(string $component, string $key, array $missingTranslation): string {
		$output = "\n#### [`$key`]({$this->weblateStringUrl($component, $key)})\n\n";
		$output .= $this->blockquote($missingTranslation['source']) . "\n\n";
		$output .= $this->diffBlock(null, $missingTranslation['to']) . "\n";

		return $output;
	}

	private function blockquote(string $string): string {
		$lines = [];
		foreach (explode("\n", $this->escapeMarkdown($string)) as $line) {
			$line = $this->escapeLineStart($line);
			$lines[] = $line === '' ? '>' : "> $line";
		}

		return implode("\n", $lines);
	}

	/**
	 * Generate a code block with a line by line diff of both translations. Lines which are the same in both
	 * translations are kept as a context for changed lines.
	 *
	 * @param string|null $from Removed translation or `null` if there is nothing to remove.
	 */
	private function diffBlock(?string $from, string $to): string {
		if ($from === null) {
			$chunks = [];
			foreach (explode("\n", $to) as $line) {
				$chunks[] = [TextDiff::INSERT, $line];
			}
		} else {
			$chunks = TextDiff::compareLines($from, $to);
		}

		$lines = [];
		foreach ($chunks as [$type, $line]) {
			switch ($type) {
				case TextDiff::DELETE:
					$lines[] = "-$line";
					break;
				case TextDiff::INSERT:
					$lines[] = "+$line";
					break;
				default:
					// context lines are prefixed with a space, just like in a regular unified diff
					$lines[] = $line === '' ? '' : " $line";
			}
		}

		$content = implode("\n", $lines);
		// make sure that backticks inside translations will not break the code block
		$fence = str_repeat('`', max(3, $this->getLongestBacktickSequence($content) + 1));

		return "{$fence}diff\n$content\n$fence";
	}

	/**
	 * Generate inline, word-level highlight of the differences. Returns `null` if both strings have nothing in common,
	 * since in such case highlight does not provide any additional information.
	 */
	private function highlight(string $from, string $to): ?string {
		$output = '';
		$hasCommonPart = false;
		foreach (TextDiff::compareWords($from, $to) as [$type, $string]) {
			$string = $this->escapeMarkdown($string);
			switch ($type) {
				case TextDiff::DELETE:
					$output .= "<del>$string</del>";
					break;
				case TextDiff::INSERT:
					$output .= "<ins>$string</ins>";
					break;
				default:
					if (trim($string) !== '') {
						$hasCommonPart = true;
					}
					$output .= $string;
			}
		}

		if (!$hasCommonPart) {
			return null;
		}

		return $this->escapeLineStart(strtr($output, ["\n" => '<br />']));
	}

	/**
	 * Escape characters which could be interpreted as HTML or inline Markdown syntax.
	 */
	private function escapeMarkdown(string $string): string {
		$replacements = [];
		foreach (['\\', '`', '*', '_', '[', ']', '~', '|'] as $character) {
			$replacements[$character] = '\\' . $character;
		}

		// escaping must be done before encoding, otherwise `#` from HTML entities would be escaped too
		return htmlspecialchars(strtr($string, $replacements), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Escape characters which could be interpreted as a heading, list item or blockquote at the beginning of the line.
	 */
	private function escapeLineStart(string $line): string {
		$line = preg_replace('/^(\s*\d+)([.)])/', '$1\\\\$2', $line);

		return preg_replace('/^(\s*)([-+>#])/', '$1\\\\$2', $line);
	}

	private function getLongestBacktickSequence(string $string): int {
		$longest = 0;
		if (preg_match_all('/`+/', $string, $matches)) {
			foreach ($matches[0] as $match) {
				$longest = max($longest, strlen($match));
			}
		}

		return $longest;
	}

	private function getComponentId(string $fileName): string {
		return basename($fileName, '.json');
	}

	private function weblateStringUrl(string $component, string $key): string {
		$project = FlarumVersion::weblateProject();
		$language = $this->inheritor->getLanguage();
		$query = rawurlencode("context:=\"$key\"");

		return self::WEBLATE_URL . "/translate/$project/$component/$language/?q=$query";
	}
}
