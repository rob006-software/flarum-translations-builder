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

use app\commands\ConfigController;
use app\components\extensions\ExtensionsRepository;
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
use Symfony\Component\Translation\Util\ArrayConverter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Yii;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use function file_exists;
use function file_get_contents;
use function getenv;
use function in_array;
use function json_decode;
use function sleep;

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
	 * @param array $languagesConfig
	 * @param string $sourcesDir
	 * @param string $translationsDir
	 * @throws InvalidConfigException
	 */
	public function __construct(
		string $id,
		string $weblateId,
		array $components,
		array $languagesConfig,
		string $sourcesDir,
		string $translationsDir
	) {
		$this->id = $id;
		$this->weblateId = $weblateId;
		$this->languages = array_keys($languagesConfig);
		$this->sourcesDir = $sourcesDir;
		$this->translationsDir = $translationsDir;

		foreach ($components as $componentId => $componentConfig) {
			$languages = [];
			foreach ($languagesConfig as $language => $languageComponents) {
				if (in_array($componentId, $languageComponents)) {
					$languages[] = $language;
				}
			}

			if (is_string($componentConfig)) {
				$this->components[$componentId] = new Component([$componentConfig], $componentId, $id, $languages);
			} elseif (is_array($componentConfig)) {
				$this->components[$componentId] = new Component(
					$componentConfig,
					$componentId,
					$id,
					$languages
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
	 * @return Component[]
	 */
	public function getExtensionsComponents(): array {
		return array_filter($this->components, static function (Component $component) {
			return $component->isExtension();
		});
	}

	public function getComponent(string $id): Component {
		if (!isset($this->components[$id])) {
			throw new InvalidArgumentException('There is no component with ' . readable::value($id) . ' ID.');
		}

		return $this->components[$id];
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
		$this->validateSourcesChanges($catalogue);
		$this->saveTranslations($catalogue, $this->sourcesDir);

		return $catalogue;
	}

	private function fetchSources(): Translator {
		$client = HttpClient::create();
		$translator = new Translator('en');
		$translator->addLoader('yaml', new YamlLoader());
		foreach ($this->getComponents() as $component) {
			// reverse array to process top record at the end - it will overwrite any previous translation
			foreach (array_reverse($component->getSources()) as $source) {
				// don't try to download URLs with placeholder for missing translation
				if (strpos($source, ExtensionsRepository::NO_TRANSLATION_FILE) === false) {
					$translator->addResource('yaml', $this->fetchUrl($client, $source), 'en', $component->getId());
				} else {
					Yii::warning("Skipped downloading $source.", __METHOD__ . '.skip');
				}
			}
		}
		return $translator;
	}

	private function fetchUrl(HttpClientInterface $client, string $url): string {
		$tries = 3;
		while ($tries-- > 0) {
			$response = $client->request('GET', $url);
			if ($response->getStatusCode() < 300) {
				return $response->getContent();
			}
			if (in_array($response->getStatusCode(), [404, 403])) {
				// it should be done by queue, but there is no queue support at the moment, so this must be enough for now
				ConfigController::resetFrequencyLimit();
				return $response->getContent();
			}
			Yii::warning(
				"Cannot load $url: " . readable::values($response->getInfo()),
				__METHOD__ . ':' . $response->getStatusCode()
			);
			sleep(1);
		}

		/* @noinspection PhpUndefinedVariableInspection */
		return $response->getContent();
	}

	public function getComponentSourcePath(string $componentId): string {
		return "$this->sourcesDir/{$componentId}.json";
	}

	public function getComponentTranslationPath(string $componentId, string $language): string {
		return "$this->translationsDir/$language/{$componentId}.json";
	}

	public function getTranslationsPath(string $language): string {
		return "$this->translationsDir/$language";
	}

	public function updateComponents(string $language, MessageCatalogue $sourcesCatalogue): void {
		$translator = new Translator($language);
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());

		foreach ($this->getComponents() as $component) {
			$filePath = $this->getComponentTranslationPath($component->getId(), $language);
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

		$catalogue = $translator->getCatalogue();
		assert($catalogue instanceof MessageCatalogue);
		$this->saveTranslations($catalogue, $this->getTranslationsPath($language));
	}

	public function saveTranslations(MessageCatalogue $catalogue, string $path): void {
		$dumper = new JsonFileDumper();
		$dumper->setRelativePathTemplate('%domain%.%extension%');
		$dumper->dump($catalogue, [
			'path' => $path,
			'as_tree' => true,
			'json_encoding' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		]);
	}

	private function validateSourcesChanges(MessageCatalogue $catalogue): void {
		if (getenv('TRAVIS')) {
			return;
		}
		foreach ($this->getExtensionsComponents() as $component) {
			if (!file_exists($this->getComponentSourcePath($component->getId()))) {
				continue;
			}
			$new = ArrayConverter::expandToTree($catalogue->all($component->getId()));
			$old = json_decode(file_get_contents($this->getComponentSourcePath($component->getId())), true);
			if ($old !== $new) {
				$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
				if ($extension === null) {
					throw new Exception("Unable to find extension for '{$component->getId()}' component.");
				}
				if (!$extension->verifyName()) {
					// If name was changed, ignore new source. Such cases should be handled manually - verifyName()
					// will open issue about it on issue tracker.
					// @see https://github.com/rob006-software/flarum-translations-builder/issues/6
					$catalogue->replace(
						(new ArrayLoader())->load($old, 'en', $component->getId())->all($component->getId()),
						$component->getId()
					);
				}
			}
		}
	}
}
