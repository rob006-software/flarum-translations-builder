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

namespace app\models;

use app\components\extensions\ExtensionsRepository;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function strncmp;
use function strpos;
use function strtolower;

/**
 * Class Extension.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
abstract class Extension {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $id;

	public function __construct(string $id) {
		$this->id = $id;
	}

	public function getId(): string {
		return $this->id;
	}

	abstract public function getName(): string;

	abstract public function getVendor(): string;

	abstract public function getPackageName(): string;

	abstract public function getRepositoryUrl(): string;

	public function getProjectId(): string {
		if (strncmp('fof/', $this->getPackageName(), 4) === 0) {
			return 'fof';
		}
		if (strncmp('flarum/', $this->getPackageName(), 7) === 0) {
			return 'flarum';
		}

		return 'various';
	}

	public function hasTranslationSource(): bool {
		$url = $this->getStableTranslationSourceUrl();
		return $url !== null && strpos($url, ExtensionsRepository::NO_TRANSLATION_FILE) === false;
	}

	/**
	 * @return bool `true` if name was not changed, `false` otherwise.
	 */
	abstract public function verifyName(): bool;

	public static function nameToId(string $name): string {
		return strtr(strtolower($name), [
			'/flarum-ext-' => '-',
			'/flarum-' => '-',
			'/' => '-',
		]);
	}
}
