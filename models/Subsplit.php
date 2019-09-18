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

use app\components\readme\ReadmeGenerator;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;

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
	private $updateReadme;

	public function __construct(string $id, string $repository, string $branch, string $path, ?array $components, ?bool $updateReadme = false) {
		$this->id = $id;
		$this->path = $path;
		$this->components = $components;
		$this->updateReadme = $updateReadme;
		$this->repository = new Repository($repository, $branch, APP_ROOT . "/runtime/subsplits/$id");
	}

	public function getRepository(): Repository {
		return $this->repository;
	}

	public function getId(): string {
		return $this->id;
	}

	public function shouldUpdateReadme(): bool {
		return $this->updateReadme;
	}

	public function getPath(): string {
		return $this->path;
	}

	public function getDir(): string {
		return $this->getRepository()->getPath();
	}

	public function isValidForComponent(string $projectId, string $componentId): bool {
		return $this->components === null
			|| (!empty($this->components[$projectId]) && in_array($componentId, $this->components[$projectId], true));
	}

	abstract public function split(Translations $translations): void;

	abstract public function getReadmeGenerator(Translations $translations, Project $project): ReadmeGenerator;
}
