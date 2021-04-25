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
use Composer\Semver\Semver;
use mindplay\readable;
use Yii;
use yii\helpers\ArrayHelper;
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

	public function getName(): string {
		return $this->getComposerValue('extra.flarum-extension.title') ?? parent::getName();
	}

	public function getPackageName(): string {
		return $this->getPackagistBasicData()->getName();
	}

	public function getThreadUrl(): ?string {
		return $this->getComposerValue('extra.flagrow.discuss');
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	public function getTranslationTagsUrl(): string {
		return Yii::$app->extensionsRepository->getTagsUrl($this->getTranslationsRepository());
	}

	public function getTranslationTagUrl(?string $tagName = null): string {
		$tagName = $tagName ?? Yii::$app->extensionsRepository->detectLastTag($this->getTranslationsRepository());
		return Yii::$app->extensionsRepository->getTagUrl($this->getTranslationsRepository(), $tagName);
	}

	public function isAbandoned(): bool {
		// abandoned packages without replacement have empty string in `abandoned` field
		return $this->getPackagistBasicData()->getAbandoned() !== null;
	}

	public function getReplacement(): ?string {
		return $this->getPackagistBasicData()->getAbandoned();
	}

	public function isOutdated(array $supportedReleases, array $unsupportedReleases): ?bool {
		$data = Yii::$app->extensionsRepository->getPackagistLastReleaseData($this->getComposerValue('name'));
		if ($data === null || !isset($data['require']['flarum/core'])) {
			return true;
		}

		$requiredFlarum = $data['require']['flarum/core'];
		$unclear = false;
		foreach ($unsupportedReleases as $release) {
			if (Semver::satisfies($release, $requiredFlarum)) {
				$unclear = true;
			}
		}
		foreach ($supportedReleases as $release) {
			if (Semver::satisfies($release, $requiredFlarum)) {
				return $unclear ? null : false;
			}
		}

		return true;
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
		$defaultTag = Yii::$app->extensionsRepository->detectLastTag($this->getTranslationsRepository(), $prefixes);
		if ($defaultTag === null) {
			return null;
		}

		$key = __METHOD__ . '#' . $this->getTranslationsRepository() . '#' . $defaultTag;
		return Yii::$app->cache->getOrSet($key, function () use ($defaultTag) {
			return $this->getTranslationSourceUrl($defaultTag);
		}, 31 * 24 * 3600);
	}

	private function getTranslationsRepository(): string {
		return $this->repositoryUrl;
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
