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
use app\components\translations\YamlFileDumper;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use function array_filter;
use function assert;
use function file_exists;
use function ksort;

/**
 * Class LanguageSubsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguageSubsplit extends Subsplit {

	public const TYPE = 'language';

	private $language;

	public function __construct(
		string $id,
		string $language,
		string $repository,
		string $branch,
		string $path,
		?array $components,
		$releaseGenerator = null
	) {
		$this->language = $language;

		parent::__construct($id, $repository, $branch, $path, $components, $releaseGenerator);
	}

	public function split(Translations $translations): void {
		$this->getRepository()->update();

		$components = [];
		$translator = new Translator('en');
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());

		foreach ($translations->getComponents() as $component) {
			if ($component->isValidForLanguage($this->language) && $this->isValidForComponent($component->getId())) {
				$components[$component->getId()] = $components[$component->getId()] ?? $component;
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
		// add non-empty string from translations to sources
		foreach ($translations->getComponents() as $component) {
			if ($component->isValidForLanguage($this->language) && $this->isValidForComponent($component->getId())) {
				$components[$component->getId()] = $components[$component->getId()] ?? $component;
				$messages = array_filter($translationsCatalogue->all($component->getId()), static function ($string) {
					return $string !== '';
				});

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
		$sourcesCatalogue = $translator->getCatalogue('en');
		assert($sourcesCatalogue instanceof MessageCatalogue);
		$dumper->dump($sourcesCatalogue, [
			'path' => $this->getDir() . $this->getPath(),
			'as_tree' => true,
			'inline' => 10,
		]);
	}

	public function hasTranslationForComponent(string $componentId): bool {
		return file_exists($this->getDir() . $this->getPath() . "/$componentId.yml");
	}

	public function getLanguage(): string {
		return $this->language;
	}

	public function getReadmeGenerator(Translations $translations): ReadmeGenerator {
		return new LanguageSubsplitReadmeGenerator($this->getLanguage());
	}

	protected function getSourcesPaths(Translations $translations): array {
		return [dirname($translations->getComponentTranslationPath('', $this->getLanguage()))];
	}
}
