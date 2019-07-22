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

use app\components\readme\LanguageSubsplitReadmeGenerator;
use app\components\readme\ReadmeGenerator;
use app\components\translations\YamlFileDumper;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use function array_filter;

/**
 * Class LanguageSubsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguageSubsplit extends Subsplit {

	public const TYPE = 'language';

	private $language;

	public function __construct(string $id, string $language, string $repository, string $branch, string $path, ?bool $updateReadme = false) {
		$this->language = $language;

		parent::__construct($id, $repository, $branch, $path, $updateReadme);
	}

	public function split(Translations $translations): void {
		$this->getRepository()->update();

		$translator = $this->getSourcesTranslator($translations);
		foreach ($translations->getProjects() as $project) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($project->getComponents()) as $component) {
				assert($component instanceof Component);
				$translator->addResource(
					'json_file',
					$project->getComponentTranslationPath($component, $this->language),
					$this->language,
					$component->getId()
				);
			}
		}

		$translationsCatalogue = $translator->getCatalogue($this->language);
		assert($translationsCatalogue instanceof MessageCatalogue);
		// add non-empty string from translations to sources
		foreach ($translations->getProjects() as $project) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($project->getComponents()) as $component) {
				assert($component instanceof Component);
				$messages = array_filter($translationsCatalogue->all($component->getId()), static function ($string) {
					return $string !== '';
				});

				$translator->addResource('array', $messages, 'en', $component->getId());
			}
		}

		$dumper = new YamlFileDumper();
		$dumper->setRelativePathTemplate('%domain%.%extension%');
		$sourcesCatalogue = $translator->getCatalogue('en');
		assert($sourcesCatalogue instanceof MessageCatalogue);
		$dumper->dump($sourcesCatalogue, [
			'path' => $this->getDir() . $this->getPath(),
			'as_tree' => true,
			'inline' => 10,
		]);
	}

	private function getSourcesTranslator(Translations $translations): Translator {
		$translator = new Translator('en');
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());
		foreach ($translations->getProjects() as $project) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($project->getComponents()) as $component) {
				assert($component instanceof Component);
				$translator->addResource('json_file', $project->getComponentSourcePath($component), 'en', $component->getId());
			}
		}
		return $translator;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	public function getReadmeGenerator(Translations $translations, Project $project): ReadmeGenerator {
		return new LanguageSubsplitReadmeGenerator($this->getLanguage(), $project, $translations->getVendors($project->getId()));
	}
}
