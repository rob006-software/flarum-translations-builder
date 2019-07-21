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

use app\components\translations\YamlFileDumper;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use function array_filter;

/**
 * Class Subsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Subsplit {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $name;
	private $repository;
	private $language;
	private $path;

	public function __construct(string $name, string $language, string $repository, string $branch, string $path) {
		$this->name = $name;
		$this->language = $language;
		$this->path = $path;
		$this->repository = new Repository($repository, $branch, APP_ROOT . "/runtime/subsplits/$name");
	}

	/**
	 * @param Project[] $projects
	 */
	public function splitProjects(array $projects): void {
		$this->repository->update();

		$translator = $this->getSourcesTranslator($projects);
		foreach ($projects as $project) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($project->getComponents()) as $component) {
				assert($component instanceof Component);
				$translator->addResource(
					'json_file',
					$project->getComponentTranslationPath($component, $this->language),
					$this->language,
					$component->getName()
				);
			}
		}

		$translationsCatalogue = $translator->getCatalogue($this->language);
		assert($translationsCatalogue instanceof MessageCatalogue);
		// add non-empty string from translations to sources
		foreach ($projects as $project) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($project->getComponents()) as $component) {
				assert($component instanceof Component);
				$messages = array_filter($translationsCatalogue->all($component->getName()), static function ($string) {
					return $string !== '';
				});

				$translator->addResource('array', $messages, 'en', $component->getName());
			}
		}

		$dumper = new YamlFileDumper();
		$dumper->setRelativePathTemplate('%domain%.%extension%');
		$sourcesCatalogue = $translator->getCatalogue('en');
		assert($sourcesCatalogue instanceof MessageCatalogue);
		$dumper->dump($sourcesCatalogue, [
			'path' => $this->getRepository()->getPath() . $this->path,
			'as_tree' => true,
			'inline' => 10,
		]);
	}

	public function getRepository(): Repository {
		return $this->repository;
	}

	/**
	 * @param Project[] $projects
	 * @return Translator
	 */
	private function getSourcesTranslator(array $projects): Translator {
		$translator = new Translator('en');
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());
		foreach ($projects as $project) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($project->getComponents()) as $component) {
				assert($component instanceof Component);
				$translator->addResource('json_file', $project->getComponentSourcePath($component), 'en', $component->getName());
			}
		}
		return $translator;
	}

	public function getLanguage(): string {
		return $this->language;
	}
}
