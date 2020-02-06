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

use app\components\extensions\ExtensionsRepository;
use app\components\extensions\IssueGenerator;
use app\models\packagist\SearchResult;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use function strncmp;
use function strpos;

/**
 * Class Extension.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Extension {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $id;
	private $repositoryUrl;
	private $composerData;
	private $packagistBasicData;

	public function __construct(string $id, string $repositoryUrl) {
		$this->id = $id;
		$this->repositoryUrl = $repositoryUrl;
	}

	public static function createFromPackagistSearchResult(SearchResult $result): Extension {
		$extension = new Extension(static::nameToId($result->getName()), $result->getRepository());
		$extension->packagistBasicData = $result;

		return $extension;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getName(): string {
		return $this->getComposerValue('extra.flarum-extension.title')
			?? Inflector::titleize(strtr(explode('/', $this->getPackageName())[1], ['-' => ' ']));
	}

	public function getVendor(): string {
		return explode('/', $this->getPackageName())[0];
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

	public function getTagsUrl(): string {
		return Yii::$app->extensionsRepository->getTagsUrl($this->repositoryUrl);
	}

	public function getTagUrl(?string $tagName = null): string {
		$tagName = $tagName ?? Yii::$app->extensionsRepository->detectLastTag($this->getTranslationsRepository());
		return Yii::$app->extensionsRepository->getTagUrl($this->repositoryUrl, $tagName);
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

	public function getProjectId(): string {
		if (strncmp('fof/', $this->getPackageName(), 4) === 0) {
			return 'fof';
		}
		if (strncmp('flarum/', $this->getPackageName(), 7) === 0) {
			return 'flarum';
		}

		return 'various';
	}

	public function getTranslationSourceUrl(?string $branchName = null): string {
		return Yii::$app->extensionsRepository->detectTranslationSourceUrl($this->getTranslationsRepository(), $branchName);
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

	public function hasTranslationSource(): bool {
		$url = $this->getStableTranslationSourceUrl();
		return $url !== null && strpos($url, ExtensionsRepository::NO_TRANSLATION_FILE) === false;
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

	public static function nameToId(string $name): string {
		return strtr(strtolower($name), [
			'/flarum-ext-' => '-',
			'/flarum-' => '-',
			'/' => '-',
		]);
	}
}
