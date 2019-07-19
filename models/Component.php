<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

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
	private $name;
	private $project;
	private $languages;

	/**
	 * Component constructor.
	 *
	 * @param string[] $sources
	 * @param string $name
	 * @param string $project
	 * @param string[] $languages
	 */
	public function __construct(array $sources, string $name, string $project, array $languages) {
		$this->sources = $sources;
		$this->name = $name;
		$this->project = $project;
		$this->languages = $languages;
	}

	/**
	 * @return string[]
	 */
	public function getSources(): array {
		return $this->sources;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getProject(): string {
		return $this->project;
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
}
