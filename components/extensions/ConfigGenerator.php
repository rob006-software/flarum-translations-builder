<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\extensions;

use app\models\Extension;
use app\models\PremiumExtension;
use app\models\RegularExtension;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function file_get_contents;
use function file_put_contents;
use function strcmp;
use function strpos;
use function substr;

/**
 * Class ConfigGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ConfigGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $configPath;

	public function __construct(string $configFilePath) {
		$this->configPath = $configFilePath;
	}

	public function updateExtension(Extension $extension): void {
		$config = require $this->configPath;
		$this->saveExtensionConfig($extension->getId(), $this->generateConfig($extension, $config[$extension->getId()] ?? null));
	}

	public function removeExtension(string $extensionId): void {
		$this->saveExtensionConfig($extensionId, null);
	}

	private function saveExtensionConfig(string $extensionId, ?string $extensionConfig): void {
		$config = require $this->configPath;
		$configContent = file_get_contents($this->configPath);
		$position = $this->findFollowingComponent($extensionId);

		if ($position === null) {
			$end = strpos($configContent, '/* extensions list end */');
		} else {
			$end = strpos($configContent, "'{$position}' => ");
		}
		if (isset($config[$extensionId])) {
			$begin = strpos($configContent, "'{$extensionId}' => ");
		} else {
			$begin = $end;
		}

		$newConfig = $this->injectConfig(
			$begin,
			$end,
			$configContent,
			$extensionConfig
		);
		file_put_contents($this->configPath, $newConfig);
	}

	private function injectConfig(int $begin, int $end, string $originalConfig, ?string $toInject): string {
		$result = substr($originalConfig, 0, $begin);
		if ($toInject !== null) {
			$result .= $toInject . "\n\t";
		}
		$result .= substr($originalConfig, $end);

		return $result;
	}

	private function findFollowingComponent(string $subject): ?string {
		foreach (require $this->configPath as $componentId => $config) {
			if (
				// ignore internal components and config keys
				strncmp($componentId, '__', 2) === 0
				|| in_array($componentId, ['core', 'validation'], true)
			) {
				continue;
			}
			if (strcmp($componentId, $subject) > 0) {
				return $componentId;
			}
		}

		return null;
	}

	private function generateConfig(Extension $extension, $extensionConfig): string {
		if (!is_array($extensionConfig)) {
			$extensionConfig = [];
		}

		if ($extension instanceof RegularExtension) {
			$extensionConfig['tag'] = $extension->getStableTranslationSourceUrl();
			foreach ($extensionConfig as $key => $url) {
				if (strncmp($key, 'tag:', 4) === 0) {
					$extensionConfig[$key] = $extension->getStableTranslationSourceUrl([substr($key, 4)]);
				}
			}
			if (isset($extensionConfig['branch'])) {
				$extensionConfig['branch'] = $extension->getTranslationSourceUrl();
			}
			foreach ($extensionConfig as $key => $url) {
				if (strncmp($key, 'branch:', 7) === 0) {
					$extensionConfig[$key] = $extension->getTranslationSourceUrl(substr($key, 4));
				}
			}
		} elseif ($extension instanceof PremiumExtension) {
			$extensionConfig['tag'] = $extension->getTranslationSourceUrl();
		}

		$result = "'{$extension->getId()}' => [\n";
		foreach ($extensionConfig as $key => $value) {
			$result .= "\t\t'$key' => '$value',\n";
		}
		$result .= "\t],";

		return $result;
	}
}
