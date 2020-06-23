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

namespace app\components\readme;

use app\models\Extension;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use yii\helpers\Inflector;
use function in_array;

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

	private $vendors;

	public function __construct(array $vendors) {
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
	 * @return string[]
	 */
	protected function getVendors(): array {
		return $this->vendors;
	}

	protected function generateVendorName(Extension $extension): string {
		if (in_array($extension->getVendor(), ['fof', 'flarum'], true)) {
			return '';
		}

		return ' by ' . ($this->vendors[$extension->getId()] ?? Inflector::titleize(strtr($extension->getVendor(), ['-' => ' '])));
	}

	abstract public function generate(): string;
}
