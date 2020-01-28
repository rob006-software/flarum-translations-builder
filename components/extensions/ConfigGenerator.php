<?php

declare(strict_types=1);

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\extensions;

use app\models\Component;
use app\models\Extension;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function file_get_contents;
use function file_put_contents;
use function strcmp;
use function substr;

/**
 * Class ConfigGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ConfigGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $configPath;
	private $availableComponents;

	/**
	 * ConfigGenerator constructor.
	 *
	 * @param string $configFilePath
	 * @param Component[] $availableComponents
	 */
	public function __construct(string $configFilePath, array $availableComponents) {
		$this->configPath = $configFilePath;
		$this->availableComponents = $availableComponents;
	}

	public function updateExtension(Extension $extension): void {
		$config = require $this->configPath;
		$configContent = file_get_contents($this->configPath);
		$position = $this->findPrecedingComponent($extension->getId());

		if ($position === null) {
			$end = strpos($configContent, '/* extensions list end */');
		} else {
			$end = strpos($configContent, "'{$position}' => ");
		}
		if (isset($config[$extension->getId()])) {
			$begin = strpos($configContent, "'{$extension->getId()}' => ");
		} else {
			$begin = $end;
		}

		$extensionConfig = $this->injectConfig(
			$begin,
			$end,
			$configContent,
			$this->generateConfig($extension, $config[$extension->getId()] ?? null)
		);
		file_put_contents($this->configPath, $extensionConfig);
	}

	private function injectConfig(int $begin, int $end, string $originalConfig, string $toInject): string {
		$result = substr($originalConfig, 0, $begin);
		$result .= $toInject . "\n\t";
		$result .= substr($originalConfig, $end);

		return $result;
	}

	private function findPrecedingComponent(string $subject): ?string {
		$config = require $this->configPath;
		foreach ($this->availableComponents as $component) {
			if (
				strcmp($component->getId(), $subject) > 0
				&& isset($config[$component->getId()])
			) {
				return $component->getId();
			}
		}

		return null;
	}

	private function generateConfig(Extension $extension, $extensionConfig): string {
		$tagUrl = $extension->getStableTranslationSourceUrl();
		if (strpos($tagUrl, ExtensionsRepository::NO_TRANSLATION_FILE) !== false) {
			return "'{$extension->getId()}' => '$tagUrl',";
		}

		if (!is_array($extensionConfig)) {
			$extensionConfig = [];
		}

		$extensionConfig['tag'] = $tagUrl;
		foreach ($extensionConfig as $key => $url) {
			if (strncmp($key, 'tag:', 4) === 0) {
				$extensionConfig[$key] = $extension->getStableTranslationSourceUrl([substr($key, 4)]);
			}
		}
		$extensionConfig['branch'] =  $extension->getTranslationSourceUrl();
		foreach ($extensionConfig as $key => $url) {
			if (strncmp($key, 'branch:', 7) === 0) {
				$extensionConfig[$key] = $extension->getTranslationSourceUrl(substr($key, 4));
			}
		}

		$result = "'{$extension->getId()}' => [\n";
		foreach ($extensionConfig as $key => $value) {
			$result .= "\t\t'$key' => '$value',\n";
		}
		$result .= "\t],";

		return $result;
	}
}
