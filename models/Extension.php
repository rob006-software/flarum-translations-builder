<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\models;

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
	private $packagistSearchData;

	public function __construct(string $id, string $repositoryUrl) {
		$this->id = $id;
		$this->repositoryUrl = $repositoryUrl;
	}

	public static function createFromPackagistSearchResult(SearchResult $result): Extension {
		$extension = new Extension(static::nameToId($result->getName()), $result->getRepository());
		$extension->packagistSearchData = $result;

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
		return $this->getPackagistData()->getName();
	}

	public function getThreadUrl(): ?string {
		return $this->getComposerValue('extra.flagrow.discuss');
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	public function isAbandoned(): bool {
		// abandoned packages without replacement have empty string in `abandoned` field
		return $this->getPackagistData()->getAbandoned() !== null;
	}

	public function getReplacement(): ?string {
		return $this->getPackagistData()->getAbandoned();
	}

	public function isOutdated(array $supportedReleases): bool {
		$required = $this->getComposerValue('require.flarum/core');
		foreach ($supportedReleases as $release) {
			// @todo this check is quite naive - we may need to replace it by regular constraint resolving
			if (strpos($required, $release) !== false) {
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

	public function getTranslationSourceUrl(): string {
		return Yii::$app->extensionsRepository->detectTranslationSourceUrl($this->repositoryUrl);
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

	private function getPackagistData(): SearchResult {
		if ($this->packagistSearchData === null) {
			$this->packagistSearchData = Yii::$app->extensionsRepository->getPackagistData($this->getComposerValue('name'));
		}

		return $this->packagistSearchData;
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
