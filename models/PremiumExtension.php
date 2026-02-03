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
use function in_array;
use function strpos;

/**
 * Premium extension handled manually.
 *
 * @see https://flarum.org/extensions
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PremiumExtension extends Extension {

	private $title;
	private $repositoryUrl;
	private $requiredFlarum;
	private $subscriptionPlansCount;

	public static function createFromExtiverseCache(array $data): self {
		$extension = new self($data['name']);
		$extension->title = $data['title'] ?? null;
		$extension->repositoryUrl = $data['url'] ?? null;
		$extension->requiredFlarum = $data['requiredFlarum'] ?? null;
		$extension->subscriptionPlansCount = $data['subscriptionPlansCount'] ?? 0;

		return $extension;
	}

	public function getTitle(): string {
		return $this->title ?? parent::getTitle();
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl ?? "https://flarum.org/extension/{$this->getPackageName()}";
	}

	public function getTranslationSourceUrl(): string {
		return "https://raw.githubusercontent.com/flarum-com/premium-translations/{$this->getCommitHash()}/{$this->getId()}.yml";
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

	public function getSubscriptionPlansCount(): int {
		return $this->subscriptionPlansCount;
	}

	public function hasTranslationSource(): bool {
		$url = Yii::$app->extensionsRepository->detectTranslationSourceUrl('https://github.com/flarum-com/premium-translations', $this->getCommitHash(), [
			"{$this->getId()}.yml",
		]);
		return $url !== null && strpos($url, ExtensionsRepository::NO_TRANSLATION_FILE) === false;
	}

	private function getCommitHash(): string {
		// @todo this it temporary hack until https://github.com/rob006-software/flarum-translations-builder/issues/45 gets fixed
		if (in_array($this->getId(), [
			'blomstra-realtime',
			'datitisev-backup',
			'justoverclock-related-discussions',
		])) {
			return 'bef89cfb5e916b4186d22033e697932f0f51f487';
		}

		return '2ad4ed84ebf5507666810a66b9195fcdc5288c32';
	}

	public function hasBetaTranslationSource(): bool {
		return false;
	}

	public function verifyName(): bool {
		return true;
	}
}
