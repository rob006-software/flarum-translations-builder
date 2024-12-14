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

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use yii\helpers\Inflector;
use function explode;
use function strncmp;
use function strtolower;
use function substr;

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

	private $name;

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getId(): string {
		return self::nameToId($this->name);
	}

	public function getPackageName(): string {
		return $this->name;
	}

	public function getTitle(): string {
		$title = $this->getName();
		if (strncmp($title, 'flarum-ext-', 11) === 0) {
			$title = substr($title, 11);
		} elseif (strncmp($title, 'flarum-', 7) === 0) {
			$title = substr($title, 7);
		}

		return Inflector::titleize(strtr($title, ['-' => ' ']));
	}

	public function getName(): string {
		return explode('/', $this->getPackageName())[1];
	}

	public function getVendor(): string {
		return explode('/', $this->getPackageName())[0];
	}

	abstract public function getRepositoryUrl(): string;

	abstract public function isAbandoned(): bool;

	abstract public function isLanguagePack(): bool;

	public function isOutdated(): ?bool {
		$requiredFlarum = $this->getRequiredFlarumVersion();
		if ($requiredFlarum === null) {
			return true;
		}

		$result = Translations::$instance->isConstraintSupported($requiredFlarum);
		if ($result === null) {
			return null;
		}

		return !$result;
	}

	abstract public function getRequiredFlarumVersion(): ?string;

	abstract public function hasTranslationSource(): bool;

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
