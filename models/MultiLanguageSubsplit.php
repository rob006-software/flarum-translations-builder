<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2023 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\models;

use app\components\readme\MultiLanguageSubsplitReadmeGenerator;
use app\components\readme\ReadmeGenerator;
use function json_encode;

/**
 * Class MultiLanguageSubsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class MultiLanguageSubsplit extends Subsplit {

	public const TYPE = 'multi-language';

	/** @var LanguageSubsplit[] */
	private $variants;
	/** @var string[] */
	private $variantsLabels;

	private $_variantsRepositoriesInitialised = false;

	public function __construct(
		string $id,
		array $variants,
		array $variantsLabels,
		string $repository,
		string $branch,
		string $path,
		?array $components,
		$releaseGenerator,
		array $localeConfig,
		array $maintainers
	) {
		$this->variants = $variants;
		$this->variantsLabels = $variantsLabels;

		parent::__construct($id, $repository, $branch, $path, $components, $releaseGenerator, $localeConfig, $maintainers);
	}

	public function getTranslationsHash(Translations $translations): string {
		$hashes = [];
		foreach ($this->variants as $id => $variant) {
			$hashes[$id] = $variant->getTranslationsHash($translations);
		}

		return md5(json_encode($hashes, JSON_THROW_ON_ERROR));
	}

	public function split(Translations $translations): void {
		$this->getRepository()->update();

		$fallback = null;
		foreach ($this->variants as $variant) {
			if ($fallback === null) {
				$fallback = $variant;
			} else {
				$variant->setFallbackLanguage($fallback);
			}
			$variant->split($translations);
		}
	}

	public function createReadmeGenerator(Translations $translations): ReadmeGenerator {
		$variants = [];
		foreach ($this->variants as $variantId => $variant) {
			$variants[$variant->getLanguage()] = $this->variantsLabels[$variantId];
		}
		return new MultiLanguageSubsplitReadmeGenerator($variants, $this->getLocale());
	}

	protected function getSourcesPaths(Translations $translations): array {
		$paths = [];
		foreach ($this->variants as $variant) {
			$paths[] = $variant->getSourcesPaths($translations);
		}
		return array_merge(...$paths);
	}

	public function getRepository(): Repository {
		if (!$this->_variantsRepositoriesInitialised) {
			$repository = parent::getRepository();
			foreach ($this->variants as $variant) {
				$variant->setRepository($repository);
			}

			return $repository;
		}

		return parent::getRepository();
	}

	public function hasTranslationForComponent(Component $component): bool {
		foreach ($this->variants as $variant) {
			if ($variant->hasTranslationForComponent($component)) {
				return true;
			}
		}

		return false;
	}

	public function isValidForComponent(Component $component): bool {
		if (!parent::isValidForComponent($component)) {
			return false;
		}

		foreach ($this->variants as $variant) {
			if ($component->isValidForLanguage($variant->getLanguage())) {
				return true;
			}
		}

		return false;
	}

	public function getMainVariant(): LanguageSubsplit {
		// inject `Repository` object to variants to avoid instantiating multiple objects for the same repository path
		$this->getRepository();
		return reset($this->variants);
	}

	/**
	 * @return LanguageSubsplit[]
	 */
	public function getVariants(): array {
		// inject `Repository` object to variants to avoid instantiating multiple objects for the same repository path
		$this->getRepository();
		return $this->variants;
	}
}
