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

namespace app\commands;

use app\components\ConsoleController;
use app\components\languages\LanguagePacksSummaryGenerator;
use app\components\readme\LicenseSummaryGenerator;
use app\components\readme\MainReadmeGenerator;
use app\components\readme\SummaryGenerator;
use app\models\Extension;
use app\models\LanguageSubsplit;
use app\models\PremiumExtension;
use app\models\Subsplit;
use mindplay\readable;
use Yii;
use yii\base\InvalidArgumentException;
use function array_filter;
use function file_get_contents;
use function in_array;
use function iterator_to_array;
use function strpos;
use function substr;

/**
 * Class ReadmeController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ReadmeController extends ConsoleController {

	private const GROUPS = [
		'all',
		'flarum',
		'fof',
		'various',
		'premium',
	];

	public $defaultAction = 'update';

	public function options($actionID) {
		return array_merge(parent::options($actionID), [
			'commit',
			'push',
			'verbose',
			'frequency',
			'update',
		]);
	}

	public function actionUpdate(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}
		$readme = file_get_contents($translations->getDir() . '/README.md');
		$summary = file_get_contents($translations->getDir() . '/status/summary.md');
		$licenses = file_get_contents($translations->getDir() . '/status/licenses.md');

		if (
			strpos($readme, "<!-- languages-list-start -->") !== false
			&& strpos($readme, "<!-- languages-list-stop -->") !== false
		) {
			$readmeGenerator = new MainReadmeGenerator();
			/* @noinspection PhpParamsInspection */
			$languageSubsplits = array_filter(iterator_to_array($translations->getSubsplits()), static function (Subsplit $subsplit) {
				/* @noinspection PhpConditionAlreadyCheckedInspection */
				return $subsplit instanceof LanguageSubsplit;
			});
			$readme = $this->replaceBetween(
				"<!-- languages-list-start -->",
				"<!-- languages-list-stop -->",
				$readme,
				$readmeGenerator->generateLanguagesList($languageSubsplits)
			);
		}

		foreach (self::GROUPS as $group) {
			if (
				strpos($readme, "<!-- {$group}-extensions-list-start -->") !== false
				&& strpos($readme, "<!-- {$group}-extensions-list-stop -->") !== false
			) {
				$readmeGenerator = new MainReadmeGenerator();
				$summaryGenerator = new SummaryGenerator();
				$licensesGenerator = new LicenseSummaryGenerator();

				foreach ($translations->getExtensionsComponents() as $component) {
					$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
					if ($extension !== null && $this->isValidForGroup($extension, $group)) {
						$readmeGenerator->addExtension($extension);
						$summaryGenerator->addExtension($extension);
						$licensesGenerator->addExtension($extension);
					}
				}

				$readme = $this->replaceBetween(
					"<!-- {$group}-extensions-list-start -->",
					"<!-- {$group}-extensions-list-stop -->",
					$readme,
					$readmeGenerator->generate()
				);
				if (
					strpos($summary, "<!-- {$group}-extensions-list-start -->") !== false
					&& strpos($summary, "<!-- {$group}-extensions-list-stop -->") !== false
				) {
					$summary = $this->replaceBetween(
						"<!-- {$group}-extensions-list-start -->",
						"<!-- {$group}-extensions-list-stop -->",
						$summary,
						$summaryGenerator->generate()
					);
				}
				if (
					strpos($licenses, "<!-- {$group}-extensions-list-start -->") !== false
					&& strpos($licenses, "<!-- {$group}-extensions-list-stop -->") !== false
				) {
					$licenses = $this->replaceBetween(
						"<!-- {$group}-extensions-list-start -->",
						"<!-- {$group}-extensions-list-stop -->",
						$licenses,
						$licensesGenerator->generate()
					);
				}
			}
		}

		file_put_contents($translations->getDir() . '/README.md', $readme);
		file_put_contents($translations->getDir() . '/status/summary.md', $summary);
		file_put_contents($translations->getDir() . '/status/licenses.md', $licenses);
		$this->postProcessRepository($translations->getRepository(), 'Update list of supported extensions.');

		/* @noinspection PhpParamsInspection */
		$languageSubsplits = array_filter(iterator_to_array($translations->getSubsplits()), static function (Subsplit $subsplit) {
			/* @noinspection PhpConditionAlreadyCheckedInspection */
			return $subsplit instanceof LanguageSubsplit;
		});
		$languagePacksGenerator = new LanguagePacksSummaryGenerator($languageSubsplits);
		file_put_contents($translations->getDir() . '/status/language-packs.md', $languagePacksGenerator->generate());
		$this->postProcessRepository($translations->getRepository(), 'Update list of language packs.');

		$this->updateLimit($token);
	}

	public function actionUpdateSubsplits(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getTranslationsHash() . $translations->getHash();
		if ($this->isLimited($token)) {
			return;
		}

		if (empty($subsplits)) {
			$subsplits = $translations->getSubsplits();
		} else {
			foreach ($subsplits as $key => $subsplitId) {
				$subsplits[$key] = $translations->getSubsplit($subsplitId);
			}
		}

		foreach ($subsplits as $subsplit) {
			$subsplitToken = __METHOD__ . '#' . $subsplit->getId() . '#' . $subsplit->getTranslationsHash($translations);
			if ($this->isLimited($subsplitToken)) {
				continue;
			}
			$subsplit->getRepository()->update();
			$readme = file_get_contents($subsplit->getDir() . '/README.md');
			$changed = false;
			foreach (self::GROUPS as $group) {
				if (
					strpos($readme, "<!-- {$group}-extensions-list-start -->") !== false
					&& strpos($readme, "<!-- {$group}-extensions-list-stop -->") !== false
				) {
					$generator = $subsplit->createReadmeGenerator($translations);
					foreach ($translations->getExtensionsComponents() as $component) {
						if (
							(!($subsplit instanceof LanguageSubsplit) || $component->isValidForLanguage($subsplit->getLanguage()))
							&& $subsplit->isValidForComponent($component->getId())
							&& $subsplit->hasTranslationForComponent($component->getId())
						) {
							$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
							if ($extension !== null && $this->isValidForGroup($extension, $group)) {
								$generator->addExtension($extension);
							}
						}
					}

					$changed = true;
					$readme = $this->replaceBetween(
						"<!-- {$group}-extensions-list-start -->",
						"<!-- {$group}-extensions-list-stop -->",
						$readme,
						$generator->generate()
					);
				}
			}

			if ($changed) {
				file_put_contents($subsplit->getDir() . '/README.md', $readme);
				$this->postProcessRepository($subsplit->getRepository(), 'Update translations status in README.');
			}
			$this->updateLimit($subsplitToken);
		}
		$this->updateLimit($token);
	}

	private function replaceBetween(string $begin, string $end, string $string, string $replacement): string {
		$positionBegin = strpos($string, $begin);
		if ($positionBegin === false) {
			throw new InvalidArgumentException('$string does not contain ' . readable::value($begin) . '.');
		}
		$positionEnd = strpos($string, $end, $positionBegin);
		if ($positionEnd === false) {
			throw new InvalidArgumentException('$string does not contain ' . readable::value($end) . '.');
		}

		return substr($string, 0, $positionBegin) . $begin . $replacement . substr($string, $positionEnd);
	}

	private function isValidForGroup(Extension $extension, string $group): bool {
		if ($group === 'all') {
			return true;
		}

		if ($group === 'premium') {
			return $extension instanceof PremiumExtension;
		}
		if ($extension instanceof PremiumExtension) {
			return false;
		}

		if (in_array($group, ['flarum', 'fof'], true)) {
			return $group === $extension->getVendor();
		}
		return !in_array($extension->getVendor(), ['flarum', 'fof'], true);
	}
}
