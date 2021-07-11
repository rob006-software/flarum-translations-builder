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
use app\models\packagist\SearchResult;
use mindplay\readable;
use Yii;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
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
	private $composerData;
	private $packagistBasicData;

	public function __construct(string $id, string $repositoryUrl) {
		$this->repositoryUrl = $repositoryUrl;

		parent::__construct($id);
	}

	public static function createFromPackagistSearchResult(SearchResult $result): self {
		$extension = new self(self::nameToId($result->getName()), $result->getRepository());
		$extension->packagistBasicData = $result;

		return $extension;
	}

	public function getTitle(): string {
		return $this->getComposerValue('extra.flarum-extension.title') ?? parent::getTitle();
	}

	public function getPackageName(): string {
		return $this->getPackagistBasicData()->getName();
	}

	public function getThreadUrl(): ?string {
		return $this->getComposerValue('extra.extiverse.discuss') ?? $this->getComposerValue('extra.flagrow.discuss');
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	public function isAbandoned(): bool {
		// abandoned packages without replacement have empty string in `abandoned` field
		return $this->getPackagistBasicData()->getAbandoned() !== null;
	}

	public function getReplacement(): ?string {
		return $this->getPackagistBasicData()->getAbandoned();
	}

	public function getRequiredFlarumVersion(): ?string {
		$data = Yii::$app->extensionsRepository->getPackagistLastReleaseData($this->getComposerValue('name'));
		return $data['require']['flarum/core'] ?? null;
	}

	public function isLanguagePack(): bool {
		return $this->getComposerValue('extra.flarum-locale') !== null;
	}

	public function hasTranslationSource(): bool {
		$url = $this->getStableTranslationSourceUrl();
		return $url !== null && strpos($url, ExtensionsRepository::NO_TRANSLATION_FILE) === false;
	}

	public function getTranslationSourceUrl(?string $branchName = null): string {
		return Yii::$app->extensionsRepository->detectTranslationSourceUrl($this->repositoryUrl, $branchName);
	}

	public function getStableTranslationSourceUrl(?array $prefixes = null): ?string {
		$releases = Yii::$app->extensionsRepository->getPackagistReleasesData($this->getPackageName());
		$lastRelease = null;
		foreach ($releases as $release) {
			if ($prefixes !== null) {
				foreach ($prefixes as $prefix) {
					if (strncmp($prefix, $release['version_normalized'], strlen($prefix)) === 0) {
						$lastRelease = $release;
						break 2;
					}
				}
			} else {
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

	private function getComposerValue(string $key, $default = null) {
		return ArrayHelper::getValue($this->getComposerData(), $key, $default);
	}

	private function getComposerData(bool $refresh = false): array {
		if ($this->composerData === null || $refresh) {
			$this->composerData = Yii::$app->extensionsRepository->getPackagistLastReleaseData($this->getPackageName());
			if ($this->composerData === null) {
				throw new UnableLoadPackagistReleaseDataException(
					'No releases found for ' . readable::value($this->getPackageName()) . '.',
				);
			}
		}

		return $this->composerData;
	}

	private function getPackagistBasicData(): SearchResult {
		if ($this->packagistBasicData === null) {
			$this->packagistBasicData = Yii::$app->extensionsRepository->getPackagistBasicData($this->getComposerValue('name'));
		}

		return $this->packagistBasicData;
	}

	public function verifyName(): bool {
		$githubName = self::nameToId($this->getComposerData(true)['name']);
		$packagistName = self::nameToId($this->getPackageName());
		if ($packagistName === $githubName) {
			return true;
		}

		(new IssueGenerator())->generateForMigration($packagistName, $githubName);

		return false;
	}
}
