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

	public function addExtension(Extension $extension): void {
		$this->extensions[] = $extension;
	}

	/**
	 * @return Extension[]
	 */
	protected function getExtensions(): array {
		return $this->extensions;
	}

	abstract public function generate(): string;
}
