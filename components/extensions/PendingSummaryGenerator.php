<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2021 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\extensions;

use app\models\Extension;
use app\models\PremiumExtension;
use app\models\Translations;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use function urlencode;
use function usort;

/**
 * Class PendingSummaryGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PendingSummaryGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var Extension[] */
	private $extensions = [];
	/** @var string[] */
	private $missing = [];
	/** @var Translations */
	private $translations;

	public function __construct(Translations $translations) {
		$this->translations = $translations;
	}

	public function addExtension(string $extensionId): void {
		$extension = Yii::$app->extensionsRepository->getExtension($extensionId);
		if ($extension !== null) {
			$this->extensions[$extensionId] = $extension;
		} else {
			$this->missing[] = $extensionId;
		}
	}

	public function generatePendingExtensions(): string {
		$extensions = $this->extensions;
		usort($extensions, static function (Extension $a, Extension $b) {
			return $a->getPackageName() <=> $b->getPackageName();
		});

		$output = "| Extension | Pull request | Compatibility | Downloads | License |\n";
		$output .= "| --- | --- | --- | --- | --- |\n";
		foreach ($extensions as $extension) {
			$name = $extension->getPackageName();
			$output .= "| [`{$name}`]({$extension->getRepositoryUrl()}) ";
			$output .= "| {$this->renderBranchBadge($extension->getId())} ";
			$output .= "| {$this->renderCompatibilityBadge($extension)} ";
			$output .= "| {$this->renderDownloadsBadge($extension)} ";
			$output .= "| {$this->renderLicenseBadge($extension)} ";
			$output .= "|\n";
		}

		return $output;
	}

	public function generateDeadBranches(): string {
		if (empty($this->missing)) {
			return "There are no dead branches.\n";
		}

		$output = "| Extension | Pull request |\n";
		$output .= "| --- | --- |\n";
		foreach ($this->missing as $extensionId) {
			$output .= "| `{$extensionId}` ";
			$output .= "| {$this->renderBranchBadge($extensionId)} ";
			$output .= "|\n";
		}

		return $output;
	}

	private function renderBranchBadge(string $extensionId): string {
		$output = '';
		if ($this->translations->hasComponent($extensionId)) {
			$output .= "![Branch was merged](https://img.shields.io/badge/-!-red)";
		}

		$pullRequest = Yii::$app->githubApi->getPullRequestForBranch(
			Yii::$app->params['translationsRepository'],
			Yii::$app->params['translationsForkRepository'],
			"new/{$extensionId}"
		);
		if ($pullRequest === null) {
			return '';
		}
		$color = $pullRequest['state'] === 'open' ? 'brightgreen' : 'red';

		$imgUrl = "https://img.shields.io/badge/PR-%23{$pullRequest['number']}-{$color}";
		$prUrl = Yii::$app->githubApi->getPullRequestUrl(Yii::$app->params['translationsRepository'], $pullRequest['number']);
		return "{$output}[![#{$pullRequest['number']} ({$pullRequest['state']})]($imgUrl)]($prUrl)";
	}

	private function renderDownloadsBadge(Extension $extension): string {
		if ($extension instanceof PremiumExtension) {
			$stats = $this->getPremiumExtensionsStats($extension);
			return "![Total Downloads](https://img.shields.io/badge/downloads-{$stats['downloads']}-yellow) <br /> "
				. "![Total Subscribers](https://img.shields.io/badge/subscribers-{$stats['subscribers']}-yellow) <br /> "
				. "![Number of subscription plans](https://img.shields.io/badge/plans-{$stats['plans']}-blue)";
		}

		$name = $extension->getPackageName();
		$badge = "![Total Downloads](https://img.shields.io/packagist/dt/{$name}) <br /> "
			. "![Monthly Downloads](https://img.shields.io/packagist/dm/{$name})";

		return "[{$badge}](https://packagist.org/packages/{$name}/stats)";
	}

	private function renderLicenseBadge(Extension $extension): string {
		if ($extension instanceof PremiumExtension) {
			return '';
		}

		$output = '';
		if (Yii::$app->extensionsRepository->isGithubRepo($extension->getRepositoryUrl())) {
			[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($extension->getRepositoryUrl());
			$githubBadge = "![GitHub license](https://img.shields.io/github/license/$userName/$repoName)";
			$output .= "[$githubBadge]({$extension->getRepositoryUrl()}) <br /> ";
		}

		$packagistBadge = "![Packagist license](https://img.shields.io/packagist/l/{$extension->getPackageName()})";
		$output .= "[$packagistBadge](https://packagist.org/packages/{$extension->getPackageName()})";

		return $output;
	}

	private function renderCompatibilityBadge(Extension $extension): string {
		$outdated = $extension->isOutdated(
			$this->translations->getSupportedVersions(),
			$this->translations->getUnsupportedVersions()
		);

		// @todo extract helper for Shields.io?
		$requiredFlarum = urlencode(strtr($extension->getRequiredFlarumVersion(), [
			'-' => '--',
			'_' => '__',
			' ' => '_',
		]));
		if ($outdated === false) {
			$badge = "![Compatible with recent Flarum](https://img.shields.io/badge/flarum-{$requiredFlarum}-brightgreen)";
		} elseif ($outdated === true) {
			$badge = "![Incompatible with recent Flarum](https://img.shields.io/badge/flarum-{$requiredFlarum}-red)";
		} else {
			$badge = "![Compatibility status with recent Flarum is unknown](https://img.shields.io/badge/flarum-{$requiredFlarum}-yellow)";
		}

		if ($extension->isAbandoned()) {
			$badge .= " <br /> ![Extension is abandoned](https://img.shields.io/badge/status-abandoned-red)";
		}

		if (!$extension->hasTranslationSource()) {
			$badge .= " <br /> ![Extension does not have translations](https://img.shields.io/badge/status-no_translation-orange)";
		}

		return $badge;
	}

	private function getPremiumExtensionsStats(PremiumExtension $extension): array {
		$stats = Yii::$app->cache->getOrSet(__METHOD__ . '#' . $extension->getPackageName(), static function () use ($extension) {
			$stats = Yii::$app->extiverseApi->searchExtensions()[$extension->getPackageName()] ?? null;
			if ($stats === null) {
				return [];
			}

			return [
				'downloads' => $stats->getDownloads(),
				'subscribers' => $stats->getSubscribers(),
			];
		}, 24 * 3600);

		$defaultStats = [
			'downloads' => 0,
			'subscribers' => 0,
			'plans' => count($extension->getSubscriptionPlans()),
		];
		return $stats + $defaultStats;
	}
}
