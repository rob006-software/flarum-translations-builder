<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\models\packagist;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;

/**
 * Class SearchResult.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SearchResult {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var string */
	private $name;
	/** @var string */
	private $description;
	/** @var string */
	private $url;
	/** @var int */
	private $downloads;
	/** @var string */
	private $repository;
	/** @var int */
	private $favers;
	/** @var string|null */
	private $abandoned;

	private function __construct(array $data) {
		foreach ($data as $field => $value) {
			$this->$field = $value;
		}
	}

	public static function createFromApiResponse(array $data): self {
		return new static($data);
	}

	public function getName(): string {
		return $this->name;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getRepository(): string {
		return $this->repository;
	}

	public function getDownloads(): int {
		return $this->downloads;
	}

	public function getFavers(): int {
		return $this->favers;
	}

	public function getAbandoned(): ?string {
		return $this->abandoned;
	}

	public function isFormGithub(): bool {
		return strncmp('https://github.com/', $this->repository, 19) === 0;
	}

	public function isFormGitlab(): bool {
		return strncmp('https://gitlab.com/', $this->repository, 19) === 0;
	}
}
