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

use app\components\readme\ReadmeGenerator;
use app\components\release\ReleaseGenerator;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use yii\base\InvalidConfigException;

/**
 * Class Subsplit.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
abstract class Subsplit {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $id;
	private $repository;
	private $path;
	private $components;
	private $releaseGenerator;
	private $repositoryUrl;

	public function __construct(
		string $id,
		string $repository,
		string $branch,
		string $path,
		?array $components,
		?string $releaseGenerator = null
	) {
		$this->id = $id;
		$this->path = $path;
		$this->components = $components;
		$this->releaseGenerator = $releaseGenerator;
		$this->repositoryUrl = $repository;
		$this->repository = new Repository($repository, $branch, APP_ROOT . "/runtime/subsplits/$id");
	}

	public function getRepository(): Repository {
		return $this->repository;
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getPath(): string {
		return $this->path;
	}

	public function getDir(): string {
		return $this->getRepository()->getPath();
	}

	public function isValidForComponent(string $componentId): bool {
		return $this->components === null || in_array($componentId, $this->components, true);
	}

	abstract public function split(Translations $translations): void;

	abstract public function getReadmeGenerator(Translations $translations): ReadmeGenerator;

	public function createReleaseGenerator(): ReleaseGenerator {
		if ($this->releaseGenerator === null) {
			throw new InvalidConfigException('$releaseGenerator is not configured for this subsplit.');
		}

		return new $this->releaseGenerator($this);
	}
}
