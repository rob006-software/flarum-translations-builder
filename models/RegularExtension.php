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

use app\components\extensions\IssueGenerator;
use app\models\packagist\SearchResult;
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
		$extension = new static(static::nameToId($result->getName()), $result->getRepository());
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

	public function isOutdated(array $supportedReleases): bool {
		$lastTag = Yii::$app->extensionsRepository->detectLastTag($this->repositoryUrl);
		if ($lastTag === null) {
			return true;
		}
		$data = Yii::$app->extensionsRepository->getPackagistReleaseData($this->getComposerValue('name'), $lastTag);
		if ($data === null || !isset($data['require']['flarum/core'])) {
			return true;
		}
		$requiredFlarum = $data['require']['flarum/core'];
		foreach ($supportedReleases as $release) {
			// @todo this check is quite naive - we may need to replace it by regular constraint resolving
			if (strpos($requiredFlarum, $release) !== false) {
				return false;
			}
		}

		return true;
	}

	public function isLanguagePack(): bool {
		return $this->getComposerValue('extra.flarum-locale') !== null;
	}

	public function getTranslationSourceUrl(?string $branchName = null): string {
		if ($this->getProjectId() === 'flarum') {
			return Yii::$app->extensionsRepository
				->detectTranslationSourceUrl('https://github.com/flarum/lang-english', $branchName, [
					"locale/{$this->getId()}.yml",
				]);
		}

		return Yii::$app->extensionsRepository->detectTranslationSourceUrl($this->repositoryUrl, $branchName);
	}

	public function getStableTranslationSourceUrl(?array $prefixes = null): ?string {
		$defaultTag = Yii::$app->extensionsRepository->detectLastTag($this->getTranslationsRepository(), $prefixes);
		if ($defaultTag === null) {
			return null;
		}
		return $this->getTranslationSourceUrl($defaultTag);
	}

	private function getTranslationsRepository(): string {
		if ($this->getProjectId() === 'flarum') {
			return 'https://github.com/flarum/lang-english';
		}

		return $this->repositoryUrl;
	}

	private function getComposerValue(string $key, $default = null) {
		return ArrayHelper::getValue($this->getComposerData(), $key, $default);
	}

	private function getComposerData(bool $refresh = false): array {
		if ($this->composerData === null || $refresh) {
			$this->composerData = Yii::$app->extensionsRepository->getComposerJsonData($this->repositoryUrl, $refresh);
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
		$githubName = static::nameToId($this->getComposerData(true)['name']);
		$packagistName = static::nameToId($this->getPackageName());
		if ($packagistName === $githubName) {
			return true;
		}

		(new IssueGenerator())->generateForMigration($packagistName, $githubName);

		return false;
	}
}
