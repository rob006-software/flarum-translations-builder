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
use Yii;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function json_encode;
use function strcmp;
use function strpos;
use function substr;
use function uksort;

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
		$this->saveExtensionConfig($extension->getId(), $this->renderConfig($extension, $config[$extension->getId()] ?? null));
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

	private function renderConfig(Extension $extension, $extensionConfig): string {
		if (!is_array($extensionConfig)) {
			$extensionConfig = [];
		}
		$extensionConfig = self::generateConfig($extension, $extensionConfig);

		$result = "'{$extension->getId()}' => [\n";
		foreach ($extensionConfig as $key => $value) {
			$result .= "\t\t'$key' => '$value',\n";
		}
		$result .= "\t],";

		return $result;
	}

	public static function generateConfig(Extension $extension, array $extensionConfig = []): array {
		$configJson = json_encode($extensionConfig, JSON_THROW_ON_ERROR);
		return Yii::$app->arrayCache->getOrSet(__METHOD__ . "({$extension->getId()}, $configJson)", static function () use ($extension, $extensionConfig) {
			if ($extension instanceof RegularExtension) {
				if ($extension->hasTranslationSource()) {
					$extensionConfig['tag'] = $extension->getStableTranslationSourceUrl();
				} else {
					unset($extensionConfig['tag']);
				}
				if ($extension->hasBetaTranslationSource()) {
					$extensionConfig['beta'] = $extension->getBetaTranslationSourceUrl();
				} else {
					unset($extensionConfig['beta']);
				}
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

			// make sure tha beta is after stable, so stable translations will have precedence
			uksort($extensionConfig, static function ($a, $b) {
				if (in_array($a, ['tag', 'beta'], true) && in_array($b, ['tag', 'beta'], true)) {
					if ($a === 'tag' && $b === 'beta') {
						return -1;
					}
					if ($a === 'beta' && $b === 'tag') {
						return 1;
					}
				}

				return 0;
			});

			return $extensionConfig;
		});
	}
}
