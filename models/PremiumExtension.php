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
	private $vendor;
	private $packageName;
	private $repositoryUrl;

	public function __construct(
		string $id,
		string $packageName,
		?string $name,
		?string $vendor,
		?string $repositoryUrl
	) {
		$this->name = $name;
		$this->vendor = $vendor;
		$this->packageName = $packageName;
		$this->repositoryUrl = $repositoryUrl;

		parent::__construct($id);
	}

	public function getPackageName(): string {
		return $this->packageName;
	}

	public function getName(): string {
		return $this->name ?? parent::getName();
	}

	public function getVendor(): string {
		return $this->vendor ?? parent::getVendor();
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl ?? "https://extiverse.com/extension/{$this->getPackageName()}";
	}

	public function getTranslationSourceUrl(): string {
		return "https://raw.githubusercontent.com/extiverse/premium-translations/master/{$this->getId()}.yml";
	}

	public function hasTranslationSource(): bool {
		return true;
	}

	public function verifyName(): bool {
		return true;
	}
}
