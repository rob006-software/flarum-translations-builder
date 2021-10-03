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

/**
 * Class ListResult.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ListResult {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var string */
	private $name;
	/** @var string */
	private $repository;
	/** @var bool|string */
	private $abandoned;

	/**
	 * @param bool|string $abandoned
	 */
	public function __construct(string $name, string $repository, $abandoned) {
		$this->name = $name;
		$this->repository = self::normalizeRepository($repository);
		$this->abandoned = $abandoned;
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

	public function getRepository(): string {
		return $this->repository;
	}

	public function getAbandoned(): ?string {
		if ($this->abandoned === false) {
			return null;
		}

		if ($this->abandoned === true) {
			return '';
		}

		return $this->abandoned;
	}
}
