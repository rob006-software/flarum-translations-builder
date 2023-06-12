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

use app\components\readme\LanguageSubsplitReadmeGenerator;
use app\components\readme\ReadmeGenerator;
use app\components\translations\JsonFileLoader;
use app\components\translations\YamlFileDumper;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use function assert;
use function basename;
use function json_encode;
use function ksort;
use function md5;
use const JSON_THROW_ON_ERROR;

/**
 * Class LanguageSubsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguageSubsplit extends Subsplit {

	public const TYPE = 'language';

	/** @var string */
	private $language;
	/** @var self */
	private $fallbackLanguage;

	public function __construct(
		string $id,
		string $language,
		$repository,
		string $branch,
		string $path,
		?array $components,
		?array $releaseGenerator,
		array $localeConfig,
		array $maintainers
	) {
		$this->language = $language;

		parent::__construct($id, $repository, $branch, $path, $components, $releaseGenerator, $localeConfig, $maintainers);
	}

	public function setFallbackLanguage(self $language): void {
		$this->fallbackLanguage = $language;
	}

	public function getTranslationsHash(Translations $translations): string {
		$values = [];
		foreach ($translations->getComponents() as $component) {
			if ($this->isValidForComponent($component)) {
				$file = $translations->getComponentTranslationPath($component->getId(), $this->language);
				$messages = (new JsonFileLoader(['skipEmpty' => true]))->load($file, 'en')->all();
				if (!empty($messages)) {
					$values[basename($file, '.json')] = md5(json_encode($messages, JSON_THROW_ON_ERROR));
				}
			}
		}
		ksort($values);

		return md5(json_encode($values, JSON_THROW_ON_ERROR));
	}

	public function split(Translations $translations): void {
		$components = [];
		$translator = new Translator('en');
		$translator->addLoader('json_file', new JsonFileLoader(['skipEmpty' => true]));
		$translator->addLoader('array', new ArrayLoader());

		foreach ($translations->getComponents() as $component) {
			if ($this->isValidForComponent($component)) {
				$components[$component->getId()] = $components[$component->getId()] ?? $component;

				if ($this->fallbackLanguage !== null && $this->fallbackLanguage->isValidForComponent($component)) {
					$translator->addResource(
						'json_file',
						$translations->getComponentTranslationPath($component->getId(), $this->fallbackLanguage->getLanguage()),
						$this->language,
						$component->getId()
					);
				}

				$translator->addResource(
					'json_file',
					$translations->getComponentTranslationPath($component->getId(), $this->language),
					$this->language,
					$component->getId()
				);
			}
		}

		$translationsCatalogue = $translator->getCatalogue($this->language);
		assert($translationsCatalogue instanceof MessageCatalogue);
		// define new catalogue with sorted strings and skipped components without translations
		foreach ($translations->getComponents() as $component) {
			if ($this->isValidForComponent($component)) {
				$components[$component->getId()] = $components[$component->getId()] ?? $component;
				$messages = $translationsCatalogue->all($component->getId());

				if (!empty($messages)) {
					ksort($messages);
					$translator->addResource('array', $messages, 'en', $component->getId());
				}
			}
		}

		$dumper = new YamlFileDumper(function (string $id) use ($components) {
			$component = $components[$id];
			assert($component instanceof Component);
			$url = "https://weblate.rob006.net/projects/flarum/{$component->getId()}/{$this->language}/";

			return "# This file is automatically generated - do not edit it directly.\n"
				. "# Use $url for translation.\n"
				. "# You can read more about the process at https://github.com/rob006-software/flarum-translations/wiki.\n\n";
		});
		$dumper->setRelativePathTemplate('%domain%.%extension%');
		$catalogue = $translator->getCatalogue('en');
		assert($catalogue instanceof MessageCatalogue);
		$dumper->dump($catalogue, [
			'path' => $this->getDir() . $this->getPath(),
			'as_tree' => true,
			'inline' => 10,
		]);
	}

	public function getLanguage(): string {
		return $this->language;
	}

	public function isValidForComponent(Component $component): bool {
		return parent::isValidForComponent($component) && $component->isValidForLanguage($this->language);
	}

	public function createReadmeGenerator(Translations $translations): ReadmeGenerator {
		return new LanguageSubsplitReadmeGenerator($this->getLanguage(), $this->getLocale());
	}

	protected function getSourcesPaths(Translations $translations): array {
		return [dirname($translations->getComponentTranslationPath('', $this->getLanguage()))];
	}
}
