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

namespace app\models;

use app\components\extensions\exceptions\UnableLoadPackagistReleaseDataException;
use app\components\extensions\ExtensionsRepository;
use app\components\extensions\IssueGenerator;
use app\models\packagist\ListResult;
use Composer\Semver\VersionParser;
use mindplay\readable;
use Yii;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
use function in_array;
use function strlen;
use function strncmp;
use function strpos;

/**
 * Basic extension from Packagist.
 *
 * @todo Reconsider naming.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class RegularExtension extends Extension {

	private $repositoryUrl;
	private $abandoned = false;

	private $_lastReleaseData;

	public static function createFromPackagistListResult(ListResult $result): self {
		$extension = new self($result->getName());
		$extension->repositoryUrl = $result->getRepository();
		$extension->abandoned = $result->getAbandoned();

		return $extension;
	}

	public function getTitle(): string {
		return $this->getLastReleaseValue('extra.flarum-extension.title') ?? parent::getTitle();
	}

	public function getThreadUrl(): ?string {
		return $this->getLastReleaseValue('extra.extiverse.discuss') ?? $this->getLastReleaseValue('extra.flagrow.discuss');
	}

	public function getRepositoryUrl(): string {
		if ($this->repositoryUrl === null) {
			$this->repositoryUrl = Yii::$app->extensionsRepository->getPackagistData($this->getPackageName())['repository'];
		}

		return $this->repositoryUrl;
	}

	public function isAbandoned(): bool {
		// abandoned packages without replacement have empty string in `abandoned` field
		return $this->getReplacement() !== null;
	}

	public function getReplacement(): ?string {
		if ($this->abandoned === false) {
			// this endpoint returns `true` instead empty string for abandoned packages without replacement
			$abandoned = Yii::$app->extensionsRepository->getPackagistData($this->getPackageName())['abandoned'] ?? null;
			if ($abandoned === true) {
				$abandoned = '';
			}
			$this->abandoned = $abandoned;
		}

		return $this->abandoned;
	}

	public function getRequiredFlarumVersion(): ?string {
		$data = Yii::$app->extensionsRepository->getPackagistReleasesData($this->getPackageName());
		foreach ($data as $release) {
			$constraint = $release['require']['flarum/core'] ?? null;
			if ($constraint === null) {
				continue;
			}
			$result = Translations::$instance->isConstraintSupported($constraint);
			if ($result !== false) {
				return $constraint;
			}
		}

		return null;
	}

	public function isLanguagePack(): bool {
		return $this->getLastReleaseValue('extra.flarum-locale') !== null;
	}

	public function hasTranslationSource(): bool {
		$url = $this->getStableTranslationSourceUrl();
		return $url !== null && strpos($url, ExtensionsRepository::NO_TRANSLATION_FILE) === false;
	}

	public function hasBetaTranslationSource(): bool {
		$betaUrl = $this->getBetaTranslationSourceUrl();
		if ($betaUrl === null || strpos($betaUrl, ExtensionsRepository::NO_TRANSLATION_FILE) !== false) {
			return false;
		}
		$stableUrl = $this->getStableTranslationSourceUrl();
		return $stableUrl !== $betaUrl;
	}

	public function getTranslationSourceUrl(?string $branchName = null): string {
		return Yii::$app->extensionsRepository->detectTranslationSourceUrl($this->repositoryUrl, $branchName);
	}

	public function getStableTranslationSourceUrl(?array $prefixes = null): ?string {
		return $this->getTranslationSourceUrlForStability($prefixes, ['stable']);
	}

	public function getBetaTranslationSourceUrl(?array $prefixes = null): ?string {
		return $this->getTranslationSourceUrlForStability($prefixes, ['stable', 'beta']);
	}

	private function getTranslationSourceUrlForStability(?array $prefixes, array $stabilities): ?string {
		$releases = Yii::$app->extensionsRepository->getPackagistReleasesData($this->getPackageName());
		$lastRelease = null;
		foreach ($releases as $release) {
			$constraint = $release['require']['flarum/core'] ?? null;
			if ($constraint === null) {
				continue;
			}
			if ($prefixes !== null) {
				foreach ($prefixes as $prefix) {
					if (
						strncmp($prefix, $release['version_normalized'], strlen($prefix)) === 0
						&& Translations::$instance->isConstraintSupported($constraint) !== false
						&& in_array(VersionParser::parseStability($release['version_normalized']), $stabilities, true)
					) {
						$lastRelease = $release;
						break 2;
					}
				}
			} elseif (
				in_array(VersionParser::parseStability($release['version_normalized']), $stabilities, true)
				&& Translations::$instance->isConstraintSupported($constraint) !== false
			) {
				$lastRelease = $release;
				break;
			}
		}
		if ($lastRelease === null) {
			return null;
		}

		$key = __METHOD__ . '#' . $this->getRepositoryUrl() . '#' . $lastRelease['version_normalized'];
		return Yii::$app->cache->getOrSet($key, function () use ($lastRelease) {
			$lastRelease = Yii::$app->extensionsRepository->findTagForCommit(
				$this->getRepositoryUrl(),
				$lastRelease['source']['reference']
			);
			if ($lastRelease === null) {
				$lastRelease = $lastRelease['version'];
			}
			return $this->getTranslationSourceUrl($lastRelease);
		}, 31 * 24 * 3600, new TagDependency(['tags' => $this->getRepositoryUrl()]));
	}

	private function getLastReleaseValue(string $key, $default = null) {
		return ArrayHelper::getValue($this->getLastReleaseData(), $key, $default);
	}

	private function getLastReleaseData(bool $refresh = false): array {
		if ($this->_lastReleaseData === null || $refresh) {
			$this->_lastReleaseData = Yii::$app->extensionsRepository->getPackagistLastReleaseData($this->getPackageName());
			if ($this->_lastReleaseData === null) {
				throw new UnableLoadPackagistReleaseDataException(
					'No releases found for ' . readable::value($this->getPackageName()) . '.',
				);
			}
		}

		return $this->_lastReleaseData;
	}

	public function verifyName(): bool {
		// @todo This no longer works, since `getLastReleaseData()` no longer fetches data directly from repository and
		//       uses data from Packagist instead.
		$githubName = self::nameToId($this->getLastReleaseData(true)['name']);
		$packagistName = self::nameToId($this->getPackageName());
		if ($packagistName === $githubName) {
			return true;
		}

		(new IssueGenerator())->generateForMigration($packagistName, $githubName);

		return false;
	}
}
