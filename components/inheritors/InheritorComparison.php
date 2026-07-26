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

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function array_keys;
use function count;
use function sort;

/**
 * Result of comparison of translations from two languages handled by the same inheritor.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class InheritorComparison {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var array[] */
	private $differences;
	/** @var array[] */
	private $missingTranslations;

	/**
	 * @param array[] $differences Strings translated differently in both languages, grouped by component file name:
	 * `[$fileName => [$key => ['source' => $source, 'from' => $fromTranslation, 'to' => $toTranslation]]]`.
	 * @param array[] $missingTranslations Strings translated only in the inheriting language, grouped by component
	 * file name: `[$fileName => [$key => ['source' => $source, 'to' => $toTranslation]]]`.
	 */
	public function __construct(array $differences, array $missingTranslations) {
		$this->differences = $differences;
		$this->missingTranslations = $missingTranslations;
	}

	/**
	 * @return array[]
	 */
	public function getDifferences(): array {
		return $this->differences;
	}

	/**
	 * @return array[]
	 */
	public function getMissingTranslations(): array {
		return $this->missingTranslations;
	}

	public function getDifferencesCount(): int {
		return $this->countEntries($this->differences);
	}

	public function getMissingTranslationsCount(): int {
		return $this->countEntries($this->missingTranslations);
	}

	/**
	 * @return string[] Names of files of all components with any difference, sorted alphabetically.
	 */
	public function getComponents(): array {
		$fileNames = array_keys($this->differences + $this->missingTranslations);
		sort($fileNames);

		return $fileNames;
	}

	public function getComponentsCount(): int {
		return count($this->getComponents());
	}

	public function isEmpty(): bool {
		return empty($this->differences) && empty($this->missingTranslations);
	}

	private function countEntries(array $entries): int {
		$count = 0;
		foreach ($entries as $componentEntries) {
			$count += count($componentEntries);
		}

		return $count;
	}
}
