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

use Composer\Semver\Semver;
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

	private $id;

	public function __construct(string $id) {
		$this->id = $id;
	}

	public function getId(): string {
		return $this->id;
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

	abstract public function getPackageName(): string;

	abstract public function getRepositoryUrl(): string;

	abstract public function isAbandoned(): bool;

	abstract public function isLanguagePack(): bool;

	public function isOutdated(array $supportedReleases, array $unsupportedReleases): ?bool {
		$requiredFlarum = $this->getRequiredFlarumVersion();
		if ($requiredFlarum === null) {
			return true;
		}

		$unclear = false;
		foreach ($unsupportedReleases as $release) {
			if (Semver::satisfies($release, $requiredFlarum)) {
				$unclear = true;
			}
		}
		foreach ($supportedReleases as $release) {
			if (Semver::satisfies($release, $requiredFlarum)) {
				return $unclear ? null : false;
			}
		}

		return true;
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
