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
use app\components\translations\JsonFileLoader;
use app\components\translations\YamlLoader;
use app\helpers\HttpClient;
use Composer\Semver\Semver;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use mindplay\readable;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Util\ArrayConverter;
use Yii;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\caching\TagDependency;
use function array_diff_key;
use function array_filter;
use function array_reverse;
use function assert;
use function count;
use function dir;
use function file_exists;
use function file_get_contents;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function md5_file;
use function pathinfo;
use function sleep;
use function strpos;
use function unlink;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Class Translations.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Translations {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	public static $instance;

	private $hash;
	private $dir;
	private $sourcesDir;
	private $translationsDir;
	private $metadataDir;
	private $components = [];
	private $subsplits;
	private $ignoredExtensions;
	private $languages;
	private $supportedVersions;
	private $unsupportedVersions;

	private $repository;

	private $_sourcesContents = [];

	public function __construct(string $repository, ?string $branch, array $config) {
		$this->hash = md5(json_encode($config, JSON_THROW_ON_ERROR));
		$this->repository = [$repository, $branch, $config['dir']];
		$this->dir = $config['dir'];
		$this->sourcesDir = $config['sourcesDir'];
		$this->translationsDir = $config['translationsDir'];
		$this->metadataDir = $config['metadataDir'] ?? ($config['sourcesDir'] . '/metadata');
		$this->languages = array_keys($config['languages']);
		$this->ignoredExtensions = $config['ignoredExtensions'] ?? [];
		$this->subsplits = $config['subsplits'];
		$this->supportedVersions = $config['supportedVersions'];
		$this->unsupportedVersions = $config['unsupportedVersions'] ?? [];
		foreach ($config['components'] as $componentId => $componentConfig) {
			$languages = [];
			foreach ($config['languages'] as $language => $languageComponents) {
				if (in_array($componentId, $languageComponents, true)) {
					$languages[] = $language;
				}
			}

			if (is_string($componentConfig)) {
				$this->components[$componentId] = new Component([$componentConfig], $componentId, $languages);
			} elseif (is_array($componentConfig)) {
				$this->components[$componentId] = new Component($componentConfig, $componentId, $languages);
			} else {
				throw new InvalidConfigException('Invalid $config: ' . readable::value($componentConfig) . '.');
			}
		}

		self::$instance = $this;
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

	public function hasComponent(string $id): bool {
		return isset($this->components[$id]);
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

	/**
	 * @return Subsplit[]
	 */
	public function getSubsplits(): iterable {
		foreach ($this->subsplits as $id => $config) {
			yield $id => $this->getSubsplit($id);
		}
	}

	public function hasSubsplit(string $id): bool {
		return isset($this->subsplits[$id]);
	}

	public function getSubsplit(string $id): Subsplit {
		if (!isset($this->subsplits[$id])) {
			throw new InvalidArgumentException('There is no subsplit with ' . readable::value($id) . ' ID.');
		}

		if (!$this->subsplits[$id] instanceof Subsplit) {
			$config = $this->subsplits[$id];
			switch ($config['type']) {
				case LanguageSubsplit::TYPE:
					$defaultLocaleConfig = [
						'path' => $this->getDir() . "/config/subsplitsLocale/{$id}.json",
						'fallbackPath' => $this->getDir() . '/config/subsplitsLocale/en.json',
					];
					$this->subsplits[$id] = new LanguageSubsplit(
						$id,
						$config['language'],
						$config['repository'],
						$config['branch'],
						$config['path'],
						$config['components'] ?? null,
						$config['releaseGenerator'] ?? null,
						($config['locale'] ?? []) + $defaultLocaleConfig,
						$config['maintainers'] ?? []
					);
					break;
				case MultiLanguageSubsplit::TYPE:
					$defaultLocaleConfig = [
						'path' => $this->getDir() . "/config/subsplitsLocale/{$id}.json",
						'fallbackPath' => $this->getDir() . '/config/subsplitsLocale/en.json',
					];

					$variants = [];
					$variantsLabels = [];
					foreach ($config['variants'] as $variantId => $variantConfig) {
						$variantsLabels[$variantId] = $variantConfig['name'] ?? $variantId;
						$variants[$variantId] = new LanguageSubsplit(
							$variantId,
							$variantConfig['language'],
							[$config['repository'], $config['branch'], LanguageSubsplit::generateRepositoryPath($id, $config['repository'])],
							$config['branch'],
							$variantConfig['path'],
							null,
							null,
							($config['locale'] ?? []) + $defaultLocaleConfig,
							$config['maintainers'] ?? []
						);
					}

					$this->subsplits[$id] = new MultiLanguageSubsplit(
						$id,
						$variants,
						$variantsLabels,
						$config['repository'],
						$config['branch'],
						$config['path'],
						$config['components'] ?? null,
						$config['releaseGenerator'] ?? null,
						($config['locale'] ?? []) + $defaultLocaleConfig,
						$config['maintainers'] ?? []
					);
					break;
				default:
					throw new InvalidConfigException('Invalid subsplit type: ' . readable::value($id) . '.');
			}
		}

		return $this->subsplits[$id];
	}

	public function findSubsplitIdForRepository(string $gitUrl, string $branch): ?string {
		foreach ($this->subsplits as $id => $subsplit) {
			if ($subsplit instanceof Subsplit) {
				if ($subsplit->getRepositoryUrl() === $gitUrl && $subsplit->getRepository()->getBranch() === $branch) {
					return $id;
				}
			} elseif ($subsplit['repository'] === $gitUrl && $subsplit['branch'] === $branch) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	public function getLanguages(): array {
		return $this->languages;
	}

	public function getDir(): string {
		return $this->dir;
	}

	public function getSourcesDir(): string {
		return $this->sourcesDir;
	}

	public function getTranslationsDir(): string {
		return $this->translationsDir;
	}

	public function getRepository(): Repository {
		if (is_array($this->repository)) {
			Yii::$app->locks->acquireRepoLock($this->repository[2]);
			$this->repository = new Repository(...$this->repository);
		}
		return $this->repository;
	}

	public function getHash(): string {
		return $this->hash;
	}

	public function getSourcesHash(): string {
		return $this->getDirectoryHash($this->sourcesDir, 'json');
	}

	public function getTranslationsHash(): string {
		return $this->getDirectoryHash($this->translationsDir, 'json');
	}

	private function getDirectoryHash(string $directory, string $extension): string {
		if (!is_dir($directory)) {
			throw new InvalidArgumentException(readable::value($directory) . ' is not a valid directory.');
		}

		$dir = dir($directory);
		$hashes = [];
		while (($file = $dir->read()) !== false) {
			if (!in_array($file, ['.', '..'], true)) {
				if (is_dir($directory . '/' . $file)) {
					$hashes[] = $this->getDirectoryHash("$directory/$file", $extension);
				} elseif (pathinfo($file, PATHINFO_EXTENSION) === $extension) {
					$hashes[] = md5_file("$directory/$file");
				}
			}
		}

		$dir->close();

		return md5(implode(':', $hashes));
	}

	public function getIgnoredExtensions(): array {
		return $this->ignoredExtensions;
	}

	public function getSupportedVersions(): array {
		return $this->supportedVersions;
	}

	public function getUnsupportedVersions(): array {
		return $this->unsupportedVersions;
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
		$translator = new Translator('en');
		$translator->addLoader('yaml', new YamlLoader());
		foreach ($this->getComponents() as $component) {
			// @todo there should be more efficient way of ensuring order and precedence than loading everything twice,
			//       but it probably does not matter that much, so we can keep it in that way for now

			// initial load to ensure correct order of elements (first source is a base and additional phrases are
			// added at the end)
			foreach ($component->getSources() as $source) {
				// don't try to download URLs with placeholder for missing translation
				if (strpos($source, ExtensionsRepository::NO_TRANSLATION_FILE) === false) {
					$content = $this->getSourceContent($source, $component->getId());
					$translator->addResource('yaml', $content, 'en', $component->getId());
				} else {
					Yii::warning("Skipped downloading $source.", __METHOD__ . '.skip');
				}
			}
			// load everything again in reverse order to make sure that phrases from more important sources (from the top)
			// overwrite the less important sources (from the bottom)
			// we can skip this if component has only one source
			if (count($component->getSources()) > 1) {
				foreach (array_reverse($component->getSources()) as $source) {
					// don't try to download URLs with placeholder for missing translation
					if (strpos($source, ExtensionsRepository::NO_TRANSLATION_FILE) === false) {
						$content = $this->getSourceContent($source, $component->getId());
						$translator->addResource('yaml', $content, 'en', $component->getId());
					}
				}
			}

			// free memory
			$this->_sourcesContents = [];
		}

		return $translator;
	}

	private function getSourceContent(string $url, string $componentId): string {
		if (!isset($this->_sourcesContents[$url])) {
			$this->_sourcesContents[$url] = $this->fetchUrl($url, $componentId);
		}

		return $this->_sourcesContents[$url];
	}

	private function fetchUrl(string $url, string $componentId): string {
		$tries = 3;
		while ($tries-- > 0) {
			$response = HttpClient::get($url);
			if ($response->getStatusCode() < 300) {
				return $response->getContent();
			}
			if (in_array($response->getStatusCode(), [404, 403], true)) {
				// it should be done by queue, but there is no queue support at the moment, so this must be enough for now
				ConfigController::resetFrequencyLimit();
				$extension = Yii::$app->extensionsRepository->getExtension($componentId);
				if ($extension !== null) {
					TagDependency::invalidate(Yii::$app->cache, $extension->getRepositoryUrl());
				}
				Yii::warning("Unable to load URL $url ({$response->getStatusCode()} HTTP status code).");
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

	public function updateComponents(string $language, MessageCatalogue $sourcesCatalogue): void {
		$translator = new Translator($language);
		$translator->addLoader('json_file', new JsonFileLoader(['skipEmpty' => true]));
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

	public function updateOutdatedTranslationsMetadata(string $language): void {
		$metadataPath = "{$this->metadataDir}/outdated-translations/{$language}.json";
		if (file_exists($metadataPath)) {
			$oldDates = json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
		} else {
			$oldDates = [];
		}

		$newDates = [];

		$translator = new Translator($language);
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());

		foreach ($this->getComponents() as $component) {
			$translationFilePath = $this->getComponentTranslationPath($component->getId(), $language);
			if (file_exists($translationFilePath)) {
				$translator->addResource('json_file', $translationFilePath, $language, $component->getId());
			}
			$sourceFilePath = $this->getComponentSourcePath($component->getId());
			$translator->addResource('json_file', $sourceFilePath, 'en', $component->getId());
		}
		foreach ($this->getComponents() as $component) {
			$translations = $translator->getCatalogue($language)->all($component->getId());
			if (!empty($translations)) {
				$sources = $translator->getCatalogue('en')->all($component->getId());
				foreach (array_diff_key($translations, $sources) as $key => $_) {
					$newDates[$component->getId()][$key] = $oldDates[$key] ?? date('Y-m-d');
				}
			}
		}

		file_put_contents(
			$metadataPath,
			json_encode($newDates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
		);
	}

	public function cleanupOutdatedTranslations(string $language, string $range): void {
		$metadataPath = "{$this->metadataDir}/outdated-translations/{$language}.json";
		if (file_exists($metadataPath)) {
			$oldDates = json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
		} else {
			$oldDates = [];
		}
		$timestamp = strtotime($range);
		$toRemove = [];
		foreach ($oldDates as $componentId => $dates) {
			$toRemove[$componentId] = array_filter($dates, static function ($date) use ($timestamp) {
				return strtotime($date) <= $timestamp;
			});
		}
		if (empty($toRemove)) {
			return;
		}

		$translator = new Translator($language);
		$translator->addLoader('json_file', new JsonFileLoader());
		$translator->addLoader('array', new ArrayLoader());

		foreach ($toRemove as $componentId => $toRemoveTranslations) {
			$translationFilePath = $this->getComponentTranslationPath($componentId, $language);
			if (file_exists($translationFilePath)) {
				$translator->addResource('json_file', $translationFilePath, $language, $componentId);
			}
		}
		foreach ($toRemove as $componentId => $toRemoveTranslations) {
			$translations = $translator->getCatalogue($language)->all($componentId);
			$translator->getCatalogue($language)->replace(array_diff_key($translations, $toRemoveTranslations), $componentId);
		}

		$catalogue = $translator->getCatalogue($language);
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
		if (getenv('CI')) {
			return;
		}
		foreach ($this->getExtensionsComponents() as $component) {
			if (!file_exists($this->getComponentSourcePath($component->getId()))) {
				continue;
			}
			$new = ArrayConverter::expandToTree($catalogue->all($component->getId()));
			$old = json_decode(file_get_contents($this->getComponentSourcePath($component->getId())), true, 512, JSON_THROW_ON_ERROR);
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

	public function isConstraintSupported(string $constraint): ?bool {
		$unclear = false;
		foreach ($this->getUnsupportedVersions() as $release) {
			if (Semver::satisfies($release, $constraint)) {
				$unclear = true;
			}
		}
		foreach ($this->getSupportedVersions() as $release) {
			if (Semver::satisfies($release, $constraint)) {
				return $unclear ? null : true;
			}
		}

		return false;
	}
}
