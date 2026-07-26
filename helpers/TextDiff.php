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
use function explode;
use function max;
use function preg_split;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Diff between two strings, based on the longest common subsequence of words or lines.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class TextDiff {

	public const KEEP = 'keep';
	public const DELETE = 'delete';
	public const INSERT = 'insert';

	/**
	 * Maximum size of the LCS matrix - protection against excessive memory usage on very long strings. Above this
	 * limit strings are reported as completely replaced.
	 */
	private const MAX_MATRIX_SIZE = 250000;

	/**
	 * Compare two strings word by word. Adjacent words with the same type are merged into a single chunk.
	 *
	 * Concatenation of all returned chunks gives `$from` (for `KEEP` and `DELETE` chunks) and `$to` (for `KEEP` and
	 * `INSERT` chunks), so no part of the compared strings is lost.
	 *
	 * @return array[] List of `[$type, $string]` chunks, where `$type` is one of `KEEP`, `DELETE` or `INSERT`.
	 */
	public static function compareWords(string $from, string $to): array {
		return self::mergeChunks(self::compareTokens(self::tokenize($from), self::tokenize($to)));
	}

	/**
	 * Compare two strings line by line.
	 *
	 * @return array[] List of `[$type, $line]` chunks - a single chunk for each line of both strings, where `$type`
	 * is one of `KEEP`, `DELETE` or `INSERT`.
	 */
	public static function compareLines(string $from, string $to): array {
		return self::compareTokens(explode("\n", $from), explode("\n", $to));
	}

	/**
	 * @param string[] $fromTokens
	 * @param string[] $toTokens
	 * @return array[]
	 */
	private static function compareTokens(array $fromTokens, array $toTokens): array {
		// strip the common prefix and suffix - this makes the LCS matrix much smaller for typical translations,
		// which differ only in a few words
		$prefix = [];
		$fromIndex = 0;
		$toIndex = 0;
		while (
			$fromIndex < count($fromTokens)
			&& $toIndex < count($toTokens)
			&& $fromTokens[$fromIndex] === $toTokens[$toIndex]
		) {
			$prefix[] = [self::KEEP, $fromTokens[$fromIndex]];
			$fromIndex++;
			$toIndex++;
		}

		$suffix = [];
		$fromLastIndex = count($fromTokens) - 1;
		$toLastIndex = count($toTokens) - 1;
		while (
			$fromLastIndex >= $fromIndex
			&& $toLastIndex >= $toIndex
			&& $fromTokens[$fromLastIndex] === $toTokens[$toLastIndex]
		) {
			$suffix[] = [self::KEEP, $fromTokens[$fromLastIndex]];
			$fromLastIndex--;
			$toLastIndex--;
		}

		return array_merge(
			$prefix,
			self::findChanges(
				array_slice($fromTokens, $fromIndex, $fromLastIndex - $fromIndex + 1),
				array_slice($toTokens, $toIndex, $toLastIndex - $toIndex + 1)
			),
			array_reverse($suffix)
		);
	}

	/**
	 * Find changes between two sequences of tokens, using the longest common subsequence of them.
	 *
	 * @param string[] $fromWords
	 * @param string[] $toWords
	 * @return array[]
	 */
	private static function findChanges(array $fromWords, array $toWords): array {
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
