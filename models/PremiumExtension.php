<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\models;

use app\components\extensions\ExtensionsRepository;
use Yii;
use function strpos;

/**
 * Premium extension handled manually.
 *
 * @see https://extiverse.com/
 *
 * @todo Reconsider naming.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PremiumExtension extends Extension {

	private $title;
	private $repositoryUrl;
	private $requiredFlarum;

	public static function createFromExtiverseCache(array $data): self {
		$extension = new self($data['name']);
		$extension->title = $data['title'] ?? null;
		$extension->repositoryUrl = $data['url'] ?? null;
		$extension->requiredFlarum = $data['requiredFlarum'] ?? null;

		return $extension;
	}

	public function getTitle(): string {
		return $this->title ?? parent::getTitle();
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl ?? "https://extiverse.com/extension/{$this->getPackageName()}";
	}

	public function getTranslationSourceUrl(): string {
		return "https://raw.githubusercontent.com/extiverse/premium-translations/master/{$this->getId()}.yml";
	}

	public function isAbandoned(): bool {
		return false;
	}

	public function isLanguagePack(): bool {
		return false;
	}

	public function getRequiredFlarumVersion(): ?string {
		return $this->requiredFlarum;
	}

	public function hasTranslationSource(): bool {
		$url = Yii::$app->extensionsRepository->detectTranslationSourceUrl('https://github.com/extiverse/premium-translations', 'master', [
			"{$this->getId()}.yml",
		]);
		return $url !== null && strpos($url, ExtensionsRepository::NO_TRANSLATION_FILE) === false;
	}

	public function verifyName(): bool {
		return true;
	}
}
