<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\readme;

use app\models\Extension;
use app\models\Project;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use yii\helpers\Inflector;

/**
 * Class ReadmeGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
abstract class ReadmeGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $extensions = [];

	private $project;
	private $vendors;

	public function __construct(Project $project, ?array $vendors) {
		$this->project = $project;
		$this->vendors = $vendors;
	}

	public function addExtension(Extension $extension): void {
		$this->extensions[] = $extension;
	}

	/**
	 * @return Extension[]
	 */
	protected function getExtensions(): array {
		return $this->extensions;
	}

	/**
	 * @return string[]|null
	 */
	protected function getVendors(): ?array {
		return $this->vendors;
	}

	protected function generateVendorName(Extension $extension): string {
		if ($this->vendors === null) {
			return '';
		}

		return ' by ' . ($this->vendors[$extension->getId()] ?? Inflector::titleize(strtr($extension->getVendor(), ['-' => ' '])));
	}

	protected function getProject(): Project {
		return $this->project;
	}

	abstract public function generate(): string;
}
