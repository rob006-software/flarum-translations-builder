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
use mindplay\readable;
use yii\base\InvalidConfigException;
use function json_encode;

/**
 * Class MultiLanguageSubsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class MultiLanguageSubsplit extends Subsplit {

	/** @var string */
	private $language;
	/** @var LanguageSubsplit[] */
	private $variants;
	/** @var string[] */
	private $variantsLabels;
	/** @var LanguageSubsplit */
	private $mainVariant;

	private $_variantsRepositoriesInitialised = false;

	/**
	 * @param string $language Main language - its variant is used as fallback for other variants.
	 * @param LanguageSubsplit[] $variants
	 */
	public function __construct(
		string $id,
		string $language,
		array $variants,
		array $variantsLabels,
		string $repository,
		string $branch,
		string $path,
		?string $releaseVersion,
		array $maintainers,
		array $weblateMaintainers,
		?int $discussThreadId = null
	) {
		$this->language = $language;
		$this->variants = $variants;
		$this->variantsLabels = $variantsLabels;
		foreach ($variants as $variant) {
			if ($variant->getLanguage() === $language) {
				$this->mainVariant = $variant;
			}
		}
		if ($this->mainVariant === null) {
			throw new InvalidConfigException('There is no variant for ' . readable::value($language) . " language in $id subsplit.");
		}

		parent::__construct($id, $repository, $branch, $path, $releaseVersion, $maintainers, $weblateMaintainers, $discussThreadId);
	}

	public static function createFromConfig(string $id, array $config, ?string $releaseVersion): Subsplit {
		$variants = [];
		$variantsLabels = [];
		foreach ($config['variants'] as $variantId => $variantConfig) {
			$variantsLabels[$variantId] = $variantConfig['name'];
			$variants[$variantId] = new LanguageSubsplit(
				$variantId,
				$variantConfig['language'],
				// all variants use the same repository - see `getRepository()`
				[$config['repository'], $config['branch'], self::generateRepositoryPath($id, $config['repository'])],
				$config['branch'],
				$variantConfig['path'],
				null,
				$config['maintainers'],
				$config['weblateMaintainers']
			);
		}

		return new self(
			$id,
			$config['language'],
			$variants,
			$variantsLabels,
			$config['repository'],
			$config['branch'],
			$config['path'],
			$releaseVersion,
			$config['maintainers'],
			$config['weblateMaintainers'],
			$config['discussThreadId'] ?? null
		);
	}

	public function getTranslationsHash(Translations $translations): string {
		$hashes = [];
		foreach ($this->variants as $id => $variant) {
			$hashes[$id] = $variant->getTranslationsHash($translations);
		}

		return md5(json_encode($hashes, JSON_THROW_ON_ERROR));
	}

	public function split(Translations $translations): void {
		foreach ($this->variants as $variant) {
			if ($variant !== $this->mainVariant) {
				$variant->setFallbackLanguage($this->mainVariant);
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

	public function getLanguages(): array {
		$languages = [];
		foreach ($this->variants as $variant) {
			$languages[] = $variant->getLanguage();
		}

		return $languages;
	}

	protected function getLocaleLanguage(): string {
		return $this->language;
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
			$this->_variantsRepositoriesInitialised = true;

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
		return $this->mainVariant;
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
