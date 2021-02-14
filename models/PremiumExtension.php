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

	private $name;
	private $packageName;
	private $repositoryUrl;
	private $compatible;

	public function __construct(
		string $id,
		string $packageName,
		?string $name,
		?string $repositoryUrl,
		bool $compatible
	) {
		$this->name = $name;
		$this->packageName = $packageName;
		$this->repositoryUrl = $repositoryUrl;
		$this->compatible = $compatible;

		parent::__construct($id);
	}

	public static function createFromExtiverseCache(array $data): self {
		return new self(
			self::nameToId($data['name']),
			$data['name'],
			$data['title'] ?? null,
			$data['url'] ?? null,
			$data['compatible'] ?? false
		);
	}

	public function getPackageName(): string {
		return $this->packageName;
	}

	public function getName(): string {
		return $this->name ?? parent::getName();
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

	public function isOutdated(array $supportedReleases, array $unsupportedReleases): ?bool {
		return !$this->compatible;
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
