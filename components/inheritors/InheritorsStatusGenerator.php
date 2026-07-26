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
use yii\helpers\FileHelper;
use function count;
use function file_put_contents;
use function in_array;
use function strlen;
use function substr;
use function unlink;

/**
 * Generates all summaries of differences between inherited translations, with navigation between them.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class InheritorsStatusGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var string */
	private $directory;
	/** @var InheritorsSummaryGenerator */
	private $summaryGenerator;
	/** @var string[] Paths of generated files, relative to the output directory. */
	private $fileNames = [];

	/**
	 * @param string $directory Output directory for all generated summaries.
	 */
	public function __construct(string $directory) {
		$this->directory = $directory;
		$this->summaryGenerator = new InheritorsSummaryGenerator();
	}

	/**
	 * @param InheritorInterface[] $inheritors
	 */
	public function generate(iterable $inheritors): void {
		FileHelper::createDirectory($this->directory);

		foreach ($inheritors as $inheritor) {
			$comparableInheritors = $inheritor->getComparableInheritors();
			if ($comparableInheritors === [$inheritor]) {
				// inheritor handles a single language on its own, so a single report is enough for it
				$this->generateInheritorStatus($comparableInheritors[0]);
			} else {
				$this->generateGroupedInheritorStatus($inheritor, $comparableInheritors);
			}
		}

		$this->writeReport('README.md', $this->summaryGenerator->generate());
		$this->removeObsoleteFiles();
	}

	private function generateInheritorStatus(TranslationsInheritor $inheritor): void {
		$comparison = $inheritor->compare();
		$fileName = "{$inheritor->getId()}.md";
		$this->writeReport($fileName, (new InheritorDiffGenerator($inheritor, $comparison))->generate());
		$this->summaryGenerator->addReport(
			$inheritor,
			Language::name($inheritor->getLanguage()) . " (`{$inheritor->getId()}`)",
			$fileName,
			1,
			$comparison->getDifferencesCount(),
			$comparison->getMissingTranslationsCount()
		);
	}

	/**
	 * @param TranslationsInheritor[] $comparableInheritors
	 */
	private function generateGroupedInheritorStatus(InheritorInterface $inheritor, array $comparableInheritors): void {
		$subDirectory = $inheritor->getId();
		FileHelper::createDirectory("{$this->directory}/$subDirectory");

		$languagesGenerator = new InheritorLanguagesSummaryGenerator($inheritor, count($comparableInheritors));
		$reportsCount = 0;
		$differencesCount = 0;
		$missingTranslationsCount = 0;
		foreach ($comparableInheritors as $comparableInheritor) {
			$comparison = $comparableInheritor->compare();
			$differencesCount += $comparison->getDifferencesCount();
			$missingTranslationsCount += $comparison->getMissingTranslationsCount();
			if ($comparison->isEmpty()) {
				// do not generate empty reports - there is a lot of languages here and most of them are in sync
				continue;
			}

			$reportsCount++;
			$fileName = "{$comparableInheritor->getLanguage()}.md";
			$this->writeReport(
				"$subDirectory/$fileName",
				(new InheritorDiffGenerator($comparableInheritor, $comparison))->generate()
			);
			$languagesGenerator->addLanguage($comparableInheritor, $comparison, $fileName);
		}

		$this->writeReport("$subDirectory/README.md", $languagesGenerator->generate());
		$this->summaryGenerator->addReport(
			$inheritor,
			"`{$inheritor->getId()}`",
			"$subDirectory/",
			$reportsCount,
			$differencesCount,
			$missingTranslationsCount
		);
	}

	/**
	 * @param string $fileName Path of the file, relative to the output directory.
	 */
	private function writeReport(string $fileName, string $content): void {
		file_put_contents("{$this->directory}/$fileName", $content);
		$this->fileNames[] = $fileName;
	}

	private function removeObsoleteFiles(): void {
		// remove reports for languages and inheritors which are no longer relevant
		foreach (FileHelper::findFiles($this->directory, ['only' => ['*.md']]) as $file) {
			if (!in_array(substr($file, strlen($this->directory) + 1), $this->fileNames, true)) {
				unlink($file);
			}
		}
		foreach (FileHelper::findDirectories($this->directory) as $subDirectory) {
			if (FileHelper::findFiles($subDirectory) === []) {
				FileHelper::removeDirectory($subDirectory);
			}
		}
	}
}
