<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\extensions;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use mindplay\readable;
use Throwable;
use Yii;
use yii\base\Exception;
use function array_slice;
use function array_values;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_string;
use function krsort;
use function preg_match;
use function preg_quote;
use function sort;
use function str_replace;
use function substr_count;

/**
 * Updates `core` and `validation` components and `supportedVersions` in translations config, so they track the last
 * release of two last minor versions of `flarum/core`.
 *
 * It is designed to fail hard - any unexpected input (API error, unexpected format of config, broken URL, downgrade)
 * results in exception and config files are left untouched.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class CoreConfigGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private const PACKAGE_NAME = 'flarum/core';
	private const REPOSITORY_URL = 'https://github.com/flarum/flarum-core.git';
	private const RAW_URL_PREFIX = 'https://raw.githubusercontent.com/flarum/flarum-core/';
	private const COMPONENTS = ['core', 'validation'];
	private const TRACKED_MINORS = 2;
	private const SUPPORTED_VERSIONS_COMMENT = ' // automatically support minor and patch releases';

	private $configPath;
	private $componentsPath;
	private $majorVersion;

	public function __construct(string $configPath, string $componentsPath, int $majorVersion) {
		$this->configPath = $configPath;
		$this->componentsPath = $componentsPath;
		$this->majorVersion = $majorVersion;
	}

	/**
	 * @return string[]|null Tags of tracked releases (newest first), or `null` if config was already up to date.
	 */
	public function update(): ?array {
		$currentConfig = $this->loadConfig();
		$currentTags = $this->extractTags($currentConfig['components']);
		$tags = $this->findTrackedTags();
		$this->checkForDowngrade($currentTags, $tags);

		$expectedConfig = $currentConfig;
		$expectedConfig['supportedVersions'] = $this->generateSupportedVersions($tags);
		foreach (self::COMPONENTS as $componentId) {
			$expectedConfig['components'][$componentId] = $this->generateUrls($componentId, $tags);
		}
		if ($expectedConfig === $currentConfig) {
			return null;
		}

		foreach (self::COMPONENTS as $componentId) {
			foreach ($expectedConfig['components'][$componentId] as $url) {
				if (!Yii::$app->extensionsRepository->testSourceUrl($url)) {
					throw new Exception("Unable to load translation source from $url.");
				}
			}
		}

		$originalComponentsContent = file_get_contents($this->componentsPath);
		$originalConfigContent = file_get_contents($this->configPath);
		$componentsContent = $this->replaceSection(
			$this->componentsPath,
			$originalComponentsContent,
			$this->renderComponents($currentConfig['components']),
			$this->renderComponents($expectedConfig['components'])
		);
		$configContent = $this->replaceSection(
			$this->configPath,
			$originalConfigContent,
			$this->renderSupportedVersions($currentConfig['supportedVersions']),
			$this->renderSupportedVersions($expectedConfig['supportedVersions'])
		);

		try {
			$this->saveFile($this->componentsPath, $componentsContent);
			$this->saveFile($this->configPath, $configContent);
			// make sure that we changed exactly what we wanted to change, and nothing more
			if ($this->loadConfig() !== $expectedConfig) {
				throw new Exception('Config after update does not match expected config.');
			}
		} catch (Throwable $exception) {
			$this->saveFile($this->componentsPath, $originalComponentsContent);
			$this->saveFile($this->configPath, $originalConfigContent);
			throw $exception;
		}

		return $tags;
	}

	/**
	 * @return string[]
	 */
	private function findTrackedTags(): array {
		$lastReleases = [];
		foreach (Yii::$app->extensionsRepository->getPackagistReleasesData(self::PACKAGE_NAME) as $release) {
			if (VersionParser::parseStability($release['version_normalized']) !== 'stable') {
				continue;
			}
			[$major, $minor] = explode('.', $release['version_normalized']);
			if ((int) $major !== $this->majorVersion) {
				continue;
			}
			if (
				!isset($lastReleases[(int) $minor])
				|| Comparator::greaterThan($release['version_normalized'], $lastReleases[(int) $minor]['version_normalized'])
			) {
				$lastReleases[(int) $minor] = $release;
			}
		}
		krsort($lastReleases);
		$lastReleases = array_slice($lastReleases, 0, self::TRACKED_MINORS);
		if (empty($lastReleases)) {
			throw new Exception('Unable to find any stable release of ' . self::PACKAGE_NAME . " {$this->majorVersion}.x.");
		}

		$parser = new VersionParser();
		$tags = [];
		foreach ($lastReleases as $release) {
			$tag = $release['version'];
			if (($release['source']['url'] ?? null) !== self::REPOSITORY_URL) {
				throw new Exception("Unexpected source of {$tag} release: " . readable::value($release['source'] ?? null) . '.');
			}
			// tags are used to build URLs, so we accept only plain versions with optional "v" prefix
			if (
				!preg_match('/^v?\d+\.\d+\.\d+$/', $tag)
				|| $parser->normalize($tag) !== $release['version_normalized']
			) {
				throw new Exception("Unexpected tag name: {$tag}.");
			}
			$tags[] = $tag;
		}

		return $tags;
	}

	/**
	 * @return string[]
	 */
	private function extractTags(array $components): array {
		$result = null;
		foreach (self::COMPONENTS as $componentId) {
			$urls = $components[$componentId] ?? null;
			if (!is_array($urls) || empty($urls) || array_values($urls) !== $urls) {
				throw new Exception("Unexpected format of '{$componentId}' component in config.");
			}
			$tags = [];
			$pattern = '#^' . preg_quote(self::RAW_URL_PREFIX, '#') . "([^/]+)/locale/{$componentId}\.yml$#";
			foreach ($urls as $url) {
				if (!is_string($url) || !preg_match($pattern, $url, $matches)) {
					throw new Exception("Unexpected URL in '{$componentId}' component: " . readable::value($url) . '.');
				}
				$tags[] = $matches[1];
			}
			if ($result !== null && $result !== $tags) {
				throw new Exception('Components ' . implode(', ', self::COMPONENTS) . ' use different versions.');
			}
			$result = $tags;
		}

		return $result;
	}

	private function checkForDowngrade(array $currentTags, array $newTags): void {
		$parser = new VersionParser();
		$normalize = static function (string $tag) use ($parser): string {
			return $parser->normalize($tag);
		};
		$getMinor = static function (string $normalizedVersion): string {
			[$major, $minor] = explode('.', $normalizedVersion);
			return "$major.$minor";
		};

		$newVersions = [];
		foreach ($newTags as $tag) {
			$newVersions[$getMinor($normalize($tag))] = $normalize($tag);
		}
		$newest = $normalize($newTags[0]);
		foreach ($currentTags as $tag) {
			$current = $normalize($tag);
			if (Comparator::greaterThan($current, $newest)) {
				throw new Exception("Current version {$tag} is newer than the newest found release {$newTags[0]}.");
			}
			$new = $newVersions[$getMinor($current)] ?? null;
			if ($new !== null && Comparator::lessThan($new, $current)) {
				throw new Exception("Found release {$new} is older than current version {$tag}.");
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function generateSupportedVersions(array $tags): array {
		$minors = [];
		foreach ($tags as $tag) {
			[, $minor] = explode('.', (new VersionParser())->normalize($tag));
			$minors[] = (int) $minor;
		}
		sort($minors);

		$result = [];
		foreach ($minors as $minor) {
			$result[] = "{$this->majorVersion}.{$minor}.0";
		}
		$result[] = $this->getWildcardVersion();

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function generateUrls(string $componentId, array $tags): array {
		$result = [];
		foreach ($tags as $tag) {
			$result[] = self::RAW_URL_PREFIX . "{$tag}/locale/{$componentId}.yml";
		}

		return $result;
	}

	private function getWildcardVersion(): string {
		return "{$this->majorVersion}.99.99";
	}

	private function renderComponents(array $components): string {
		$result = '';
		foreach (self::COMPONENTS as $componentId) {
			$result .= "\t'{$componentId}' => [\n";
			foreach ($components[$componentId] as $url) {
				$result .= "\t\t'{$url}',\n";
			}
			$result .= "\t],\n";
		}

		return $result;
	}

	private function renderSupportedVersions(array $versions): string {
		$result = "\t'supportedVersions' => [\n";
		foreach ($versions as $version) {
			$result .= "\t\t'{$version}',";
			if ($version === $this->getWildcardVersion()) {
				$result .= self::SUPPORTED_VERSIONS_COMMENT;
			}
			$result .= "\n";
		}
		$result .= "\t],\n";

		return $result;
	}

	private function replaceSection(string $path, string $content, string $oldSection, string $newSection): string {
		// old section is rendered from current config - if it does not match file content exactly, file was edited
		// manually or its format has changed, and we should not touch it
		if (substr_count($content, $oldSection) !== 1) {
			throw new Exception("Unable to find expected section in {$path}:\n{$oldSection}");
		}

		return str_replace($oldSection, $newSection, $content);
	}

	private function saveFile(string $path, string $content): void {
		if (file_put_contents($path, $content) === false) {
			throw new Exception("Unable to save {$path}.");
		}
	}

	private function loadConfig(): array {
		$config = require $this->configPath;
		foreach (['supportedVersions', 'components'] as $key) {
			if (!isset($config[$key]) || !is_array($config[$key])) {
				throw new Exception("Missing '{$key}' in {$this->configPath}.");
			}
		}

		return $config;
	}
}
