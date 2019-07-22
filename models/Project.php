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

use app\components\translations\JsonFileDumper;
use app\components\translations\YamlLoader;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use mindplay\readable;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;

/**
 * Class Project.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Project {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $components = [];
	private $id;
	private $weblateId;
	private $languages;
	private $sourcesDir;
	private $translationsDir;

	/**
	 * Project constructor.
	 *
	 * @param string $id
	 * @param string $weblateId
	 * @param array $components
	 * @param string[] $languages
	 * @param string $sourcesDir
	 * @param string $translationsDir
	 * @throws InvalidConfigException
	 */
	public function __construct(
		string $id,
		string $weblateId,
		array $components,
		array $languages,
		string $sourcesDir,
		string $translationsDir
	) {
		$this->id = $id;
		$this->weblateId = $weblateId;
		$this->languages = $languages;
		$this->sourcesDir = $sourcesDir;
		$this->translationsDir = $translationsDir;

		foreach ($components as $componentId => $componentConfig) {
			if (is_string($componentConfig)) {
				$this->components[] = new Component([$componentConfig], $componentId, $id, $languages);
			} elseif (is_array($componentConfig)) {
				$translationLanguages = ArrayHelper::remove($componentConfig, 'languages', $languages);
				$this->components[] = new Component(
					$componentConfig,
					$componentId,
					$id,
					$translationLanguages
				);
			} else {
				throw new InvalidConfigException('Invalid $config: ' . readable::value($componentConfig) . '.');
			}
		}
	}

	/**
	 * @return Component[]
	 */
	public function getComponents(): array {
		return $this->components;
	}

	/**
	 * @return string[]
	 */
	public function getLanguages(): array {
		return $this->languages;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getWeblateId(): string {
		return $this->weblateId;
	}

	public function updateSources(): MessageCatalogue {
		$translator = $this->fetchSources();
		$catalogue = $translator->getCatalogue();
		assert($catalogue instanceof MessageCatalogue);
		$this->dumpSources($catalogue);

		return $catalogue;
	}

	private function fetchSources(): Translator {
		$client = HttpClient::create();
		$translator = new Translator('en');
		$translator->addLoader('yaml', new YamlLoader());
		foreach ($this->getComponents() as $component) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($component->getSources()) as $source) {
				$response = $client->request('GET', $source);
				$translator->addResource('yaml', $response->getContent(), 'en', $component->getId());
			}
		}
		return $translator;
	}

	private function dumpSources(MessageCatalogue $catalogue): void {
		$dumper = new JsonFileDumper();
		$dumper->setRelativePathTemplate('%domain%.%extension%');
		$dumper->dump($catalogue, [
			'path' => $this->sourcesDir,
			'as_tree' => true,
			'json_encoding' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		]);
	}

	public function getComponentSourcePath(Component $component): string {
		return "$this->sourcesDir/{$component->getId()}.json";
	}

	public function getComponentTranslationPath(Component $component, string $language): string {
		return "$this->translationsDir/$language/{$component->getId()}.json";
	}

	public function getTranslationsPath(string $language): string {
		return "$this->translationsDir/$language";
	}

	public function updateComponents(string $language, MessageCatalogue $sourcesCatalogue): void {
		$translator = new Translator($language);
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());

		foreach ($this->getComponents() as $component) {
			$filePath = $this->getComponentTranslationPath($component, $language);
			if (!$component->isValidForLanguage($language)) {
				if (file_exists($filePath)) {
					unlink($filePath);
				}
			} else {
				$sources = $sourcesCatalogue->all($component->getId());
				foreach ($sources as $key => $source) {
					$sources[$key] = '';
				}
				$translator->addResource('array', $sources, $language, $component->getId());
				if (file_exists($filePath)) {
					$translator->addResource('json_file', $filePath, $language, $component->getId());
				}
			}
		}

		$dumper = new JsonFileDumper();
		$dumper->setRelativePathTemplate('%domain%.%extension%');
		$catalogue = $translator->getCatalogue();
		assert($catalogue instanceof MessageCatalogue);
		$dumper->dump($catalogue, [
			'path' => $this->getTranslationsPath($language),
			'as_tree' => true,
			'json_encoding' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		]);
	}
}
