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

namespace app\helpers;

use function array_fill;
use function array_merge;
use function array_reverse;
use function array_slice;
use function count;
use function max;
use function preg_split;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Word-level diff between two strings, based on the longest common subsequence of words.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class WordDiff {

	public const KEEP = 'keep';
	public const DELETE = 'delete';
	public const INSERT = 'insert';

	/**
	 * Maximum size of the LCS matrix - protection against excessive memory usage on very long strings. Above this
	 * limit strings are reported as completely replaced.
	 */
	private const MAX_MATRIX_SIZE = 250000;

	/**
	 * Compare two strings word by word.
	 *
	 * Concatenation of all returned chunks gives `$from` (for `KEEP` and `DELETE` chunks) and `$to` (for `KEEP` and
	 * `INSERT` chunks), so no part of the compared strings is lost.
	 *
	 * @return array[] List of `[$type, $string]` chunks, where `$type` is one of `KEEP`, `DELETE` or `INSERT`.
	 */
	public static function compare(string $from, string $to): array {
		$fromWords = self::tokenize($from);
		$toWords = self::tokenize($to);

		// strip the common prefix and suffix - this makes the LCS matrix much smaller for typical translations,
		// which differ only in a few words
		$prefix = [];
		$fromIndex = 0;
		$toIndex = 0;
		while (
			$fromIndex < count($fromWords)
			&& $toIndex < count($toWords)
			&& $fromWords[$fromIndex] === $toWords[$toIndex]
		) {
			$prefix[] = [self::KEEP, $fromWords[$fromIndex]];
			$fromIndex++;
			$toIndex++;
		}

		$suffix = [];
		$fromLastIndex = count($fromWords) - 1;
		$toLastIndex = count($toWords) - 1;
		while (
			$fromLastIndex >= $fromIndex
			&& $toLastIndex >= $toIndex
			&& $fromWords[$fromLastIndex] === $toWords[$toLastIndex]
		) {
			$suffix[] = [self::KEEP, $fromWords[$fromLastIndex]];
			$fromLastIndex--;
			$toLastIndex--;
		}

		$chunks = array_merge(
			$prefix,
			self::compareWords(
				array_slice($fromWords, $fromIndex, $fromLastIndex - $fromIndex + 1),
				array_slice($toWords, $toIndex, $toLastIndex - $toIndex + 1)
			),
			array_reverse($suffix)
		);

		return self::mergeChunks($chunks);
	}

	/**
	 * @param string[] $fromWords
	 * @param string[] $toWords
	 * @return array[]
	 */
	private static function compareWords(array $fromWords, array $toWords): array {
		$fromCount = count($fromWords);
		$toCount = count($toWords);
		if ($fromCount === 0 || $toCount === 0 || $fromCount * $toCount > self::MAX_MATRIX_SIZE) {
			$chunks = [];
			foreach ($fromWords as $word) {
				$chunks[] = [self::DELETE, $word];
			}
			foreach ($toWords as $word) {
				$chunks[] = [self::INSERT, $word];
			}

			return $chunks;
		}

		// lengths of the longest common subsequence for each pair of prefixes of both strings
		$matrix = [array_fill(0, $toCount + 1, 0)];
		for ($i = 1; $i <= $fromCount; $i++) {
			$matrix[$i] = [0];
			for ($j = 1; $j <= $toCount; $j++) {
				if ($fromWords[$i - 1] === $toWords[$j - 1]) {
					$matrix[$i][$j] = $matrix[$i - 1][$j - 1] + 1;
				} else {
					$matrix[$i][$j] = max($matrix[$i - 1][$j], $matrix[$i][$j - 1]);
				}
			}
		}

		// walk the matrix backwards to collect the actual changes
		$chunks = [];
		$i = $fromCount;
		$j = $toCount;
		while ($i > 0 || $j > 0) {
			if ($i > 0 && $j > 0 && $fromWords[$i - 1] === $toWords[$j - 1]) {
				$chunks[] = [self::KEEP, $fromWords[$i - 1]];
				$i--;
				$j--;
			} elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
				// insertions are collected first, so after reversing they are placed after related deletions
				$chunks[] = [self::INSERT, $toWords[$j - 1]];
				$j--;
			} else {
				$chunks[] = [self::DELETE, $fromWords[$i - 1]];
				$i--;
			}
		}

		return array_reverse($chunks);
	}

	/**
	 * @param array[] $chunks
	 * @return array[]
	 */
	private static function mergeChunks(array $chunks): array {
		$merged = [];
		foreach ($chunks as [$type, $string]) {
			$last = count($merged) - 1;
			if ($last >= 0 && $merged[$last][0] === $type) {
				$merged[$last][1] .= $string;
			} else {
				$merged[] = [$type, $string];
			}
		}

		return $merged;
	}

	/**
	 * Split string into words and whitespaces, so concatenation of all tokens gives the original string.
	 *
	 * @return string[]
	 */
	private static function tokenize(string $string): array {
		$tokens = preg_split('/(\s+)/u', $string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		if ($tokens === false) {
			// invalid UTF-8 - treat the whole string as a single word
			return [$string];
		}

		return $tokens;
	}
}
