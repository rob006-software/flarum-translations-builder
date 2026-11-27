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

namespace app\components\readme;

use app\helpers\FlarumVersion;
use app\models\MultiLanguageSubsplit;
use app\models\Subsplit;
use app\models\Translations;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use yii\helpers\ArrayHelper;
use function explode;
use function json_encode;
use function preg_match;
use function preg_replace_callback;
use function str_repeat;
use function strlen;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Class ComposerJsonGenerator.
 *
 * Generates `composer.json` for language pack repository. Almost everything is generated from builder config - only
 * `flarum/core` constraint and icon are taken from current `composer.json` of language pack.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ComposerJsonGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	public const FILE = 'composer.json';

	private const DEFAULT_ICON = [
		'image' => 'icon.svg',
		'backgroundSize' => 'cover',
		'backgroundPosition' => 'center',
	];

	private $subsplit;
	private $translations;
	private $currentComposerJson;

	/**
	 * @param array $currentComposerJson Current content of `composer.json` in language pack (empty array if there is no such file).
	 */
	public function __construct(Subsplit $subsplit, Translations $translations, array $currentComposerJson) {
		$this->subsplit = $subsplit;
		$this->translations = $translations;
		$this->currentComposerJson = $currentComposerJson;
	}

	public function generate(): string {
		$json = json_encode($this->generateArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		// use tabs for indentation, like most of language packs did
		$json = preg_replace_callback('/^(?: {4})+/m', static function (array $matches) {
			return str_repeat("\t", strlen($matches[0]) / 4);
		}, $json);

		return $json . "\n";
	}

	private function generateArray(): array {
		$language = $this->subsplit->getLanguage();
		[$vendor, $repositoryName] = Yii::$app->githubApi->explodeRepoUrl($this->subsplit->getRepositoryUrl());
		$repositoryUrl = "https://github.com/$vendor/$repositoryName";
		$languageName = $this->translations->getLanguageName($language);

		$composer = [
			'name' => "$vendor/$repositoryName",
			'description' => "$languageName Language Pack for Flarum",
			'type' => 'flarum-extension',
			'keywords' => ['flarum-locale'],
			'homepage' => $repositoryUrl,
			'license' => 'MIT',
		];

		$authors = [];
		foreach ($this->subsplit->getMaintainers() as $maintainer) {
			$authors[] = [
				'name' => $maintainer,
				'homepage' => "https://github.com/$maintainer",
				'role' => 'Maintainer',
			];
		}
		if (!empty($authors)) {
			$composer['authors'] = $authors;
		}

		$composer['support']['issues'] = "$repositoryUrl/issues";
		if ($this->subsplit->getThreadUrl() !== null) {
			// Flarum displays it as "Discuss" link on extension page in admin panel
			$composer['support']['forum'] = $this->subsplit->getThreadUrl();
		}
		$composer['support']['source'] = $repositoryUrl;

		$composer['require']['flarum/core'] = ArrayHelper::getValue($this->currentComposerJson, ['require', 'flarum/core'])
			?? FlarumVersion::composerConstraint();
		if ($this->subsplit instanceof MultiLanguageSubsplit) {
			// variants switcher is provided by utils extension
			$composer['require']['flarum-lang/utils'] = FlarumVersion::composerConstraint();
		}

		$branchAlias = $this->generateBranchAlias();
		if ($branchAlias !== null) {
			$composer['extra']['branch-alias'] = $branchAlias;
		}
		$composer['extra']['flarum-extension'] = [
			'title' => $languageName,
			'category' => 'language',
			'icon' => ArrayHelper::getValue($this->currentComposerJson, ['extra', 'flarum-extension', 'icon']) ?? self::DEFAULT_ICON,
		];
		// @todo deprecated since Flarum 2.1 - move it to `LanguagePack` extender in `extend.php` when language packs require Flarum 2.1
		$composer['extra']['flarum-locale'] = [
			'code' => $this->subsplit->getLocaleCode(),
			'title' => $this->translations->getLanguageNativeName($language),
		];
		if ($this->translations->getLanguageDirection($language) === 'rtl') {
			$composer['extra']['flarum-locale']['direction'] = 'rtl';
		}

		return $composer;
	}

	/**
	 * Composer recognizes version-like branches (like `2.x`) by itself - alias is needed only for branches like `master`.
	 */
	private function generateBranchAlias(): ?array {
		$branch = $this->subsplit->getBranch();
		if (preg_match('/^\d+\.x$/', $branch) || $this->subsplit->getReleaseVersion() === null) {
			return null;
		}

		$major = explode('.', $this->subsplit->getReleaseVersion())[0];
		return ["dev-$branch" => "$major.x-dev"];
	}
}
