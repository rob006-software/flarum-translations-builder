<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2025 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\inheritors;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Symfony\Component\Translation\Util\ArrayConverter;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use function basename;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_decode;
use function json_encode;
use function ksort;
use function md5;
use function md5_file;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Class TranslationsInheritor.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class TranslationsInheritor {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var string */
	private $id;
	/** @var string */
	private $inheritFromLabel;
	/** @var string */
	private $inheritFromSources;
	/** @var string */
	private $inheritFromTranslations;
	/** @var string */
	private $inheritToSources;
	/** @var string */
	private $inheritToTranslations;
	/** @var string */
	private $metadataFile;

	public function __construct(
		$id,
		$inheritFromLabel,
		$inheritFromSources,
		$inheritFromTranslations,
		$inheritToSources,
		$inheritToTranslations,
		$metadataFile
	) {
		$this->id = $id;
		$this->inheritFromLabel = $inheritFromLabel;
		$this->inheritFromSources = $inheritFromSources;
		$this->inheritFromTranslations = $inheritFromTranslations;
		$this->inheritToSources = $inheritToSources;
		$this->inheritToTranslations = $inheritToTranslations;
		$this->metadataFile = $metadataFile;
	}

	public function inherit(): void {
		$metadata = json_decode(file_get_contents($this->metadataFile), true, 512, JSON_THROW_ON_ERROR);
		$newMetadata = [];
		foreach (FileHelper::findFiles($this->inheritToTranslations, ['only' => ['*.json']]) as $file) {
			$fileName = basename($file);
			if (
				!file_exists("{$this->inheritFromSources}/$fileName")
				|| !file_exists("{$this->inheritFromTranslations}/$fileName")
				|| !file_exists("{$this->inheritToSources}/$fileName")
				|| !file_exists("{$this->inheritToTranslations}/$fileName")
			) {
				continue;
			}

			$newMetadata[$fileName] = $this->handleComponentInheritance($fileName, $metadata[$fileName] ?? []);
		}

		$newMetadata = array_filter($newMetadata);
		ksort($newMetadata);
		file_put_contents($this->metadataFile, json_encode($newMetadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
	}

	private function handleComponentInheritance(string $fileName, array $metadata): array {
		$metadata = ArrayHelper::flatten($metadata);
		$fromSources = ArrayHelper::flatten(json_decode(file_get_contents("{$this->inheritFromSources}/$fileName"), true, 512, JSON_THROW_ON_ERROR));
		$fromTranslations = ArrayHelper::flatten(json_decode(file_get_contents("{$this->inheritFromTranslations}/$fileName"), true, 512, JSON_THROW_ON_ERROR));
		$toSources = ArrayHelper::flatten(json_decode(file_get_contents("{$this->inheritToSources}/$fileName"), true, 512, JSON_THROW_ON_ERROR));
		$toTranslations = ArrayHelper::flatten(json_decode(file_get_contents("{$this->inheritToTranslations}/$fileName"), true, 512, JSON_THROW_ON_ERROR));

		$newMetadata = [];
		$newTranslations = $toTranslations;
		foreach ($toTranslations as $key => $toTranslation) {
			$fromSource = $fromSources[$key] ?? '';
			$fromTranslation = $this->processFromTranslation($fromTranslations[$key] ?? '');
			$toSource = $toSources[$key] ?? '';

			// keep existing metadata, even if there is nothing to inherit - old strings could be restored in the future
			if (isset($metadata[$key])) {
				$newMetadata[$key] = $metadata[$key];
			}

			if ($fromSource === '' || $toSource === '') {
				// phrase is outdated (empty source)
				continue;
			}
			if ($fromTranslation === '') {
				// phrase is not translated in origin...
				if ($toTranslation !== '') {
					// ...but it is translated in target - save metadata to prevent inheritance in the future
					$newMetadata[$key] = implode(' ', [md5($fromTranslation), md5($newTranslations[$key])]);
				}
				continue;
			}

			if ($fromSource !== $toSource) {
				// different source - there is no point to inherit...
				continue;
			}

			if (!isset($metadata[$key])) {
				// the first occurrence of this phrase - we can inherit (sources are compared above) as long as
				//  the phrase was not translated yet
				if ($toTranslation === '') {
					$newTranslations[$key] = $fromTranslation;
				}
			} else {
				$meta = explode(' ', $metadata[$key]);
				// translation has been inherited before - we need to check if a current phrase is inherited
				if ($meta[1] === md5($toTranslation) && $meta[0] === $meta[1]) {
					$newTranslations[$key] = $fromTranslation;
				}
			}

			// save metadata - it could be useful in the future
			$newMetadata[$key] = implode(' ', [md5($fromTranslation), md5($newTranslations[$key])]);
		}

		if ($toTranslations !== $newTranslations) {
			file_put_contents(
				"{$this->inheritToTranslations}/$fileName",
				json_encode(ArrayConverter::expandToTree($newTranslations), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
			);
		}

		return ArrayConverter::expandToTree($newMetadata);
	}

	protected function processFromTranslation(string $translation): string {
		return $translation;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getHash(): string {
		$values = [];
		foreach (FileHelper::findFiles($this->inheritFromTranslations, ['only' => ['*.json']]) as $file) {
			$values[$file] = md5_file($file);
		}
		foreach (FileHelper::findFiles($this->inheritToTranslations, ['only' => ['*.json']]) as $file) {
			$values[$file] = md5_file($file);
		}
		ksort($values);

		return md5(json_encode($values, JSON_THROW_ON_ERROR));
	}

	public function getInheritFromLabel(): string {
		return $this->inheritFromLabel;
	}
}
