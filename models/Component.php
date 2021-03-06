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

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;

/**
 * Class Component.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Component {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $sources;
	private $id;
	private $languages;

	/**
	 * Component constructor.
	 *
	 * @param string[] $sources
	 * @param string $id
	 * @param string[] $languages
	 */
	public function __construct(array $sources, string $id, array $languages) {
		$this->sources = $sources;
		$this->id = $id;
		$this->languages = $languages;
	}

	/**
	 * @return string[]
	 */
	public function getSources(): array {
		return $this->sources;
	}

	public function getId(): string {
		return $this->id;
	}

	/**
	 * @return string[]
	 */
	public function getLanguages(): array {
		return $this->languages;
	}

	public function isValidForLanguage(string $language): bool {
		return in_array($language, $this->languages, true);
	}

	public function isExtension(): bool {
		return !in_array($this->getId(), ['core', 'validation'], true);
	}
}
