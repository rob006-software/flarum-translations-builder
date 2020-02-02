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

namespace app\models\packagist;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;

/**
 * Class SearchResult.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class SearchResult {

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
		$data['repository'] = self::normalizeRepository($data['repository']);

		return new static($data);
	}

	public static function normalizeRepository(string $repository): string {
		// make sure that domain name has correct case - some extensions uses https://GitHub.com
		if (strncasecmp('https://github.com/', $repository, 19) === 0) {
			return 'https://github.com/' . substr($repository, 19);
		}
		if (strncasecmp('https://gitlab.com/', $repository, 19) === 0) {
			return 'https://gitlab.com/' . substr($repository, 19);
		}
		if (strncasecmp('git@github.com:', $repository, 15) === 0) {
			return 'https://github.com/' . substr($repository, 15);
		}
		if (strncasecmp('git@gitlab.com:', $repository, 15) === 0) {
			return 'https://gitlab.com/' . substr($repository, 15);
		}

		return $repository;
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

	public function isFromGithub(): bool {
		return Yii::$app->extensionsRepository->isGithubRepo($this->repository);
	}

	public function isFromGitlab(): bool {
		return Yii::$app->extensionsRepository->isGitlabRepo($this->repository);
	}
}
