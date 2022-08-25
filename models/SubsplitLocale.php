<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
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
use Webmozart\Assert\Assert;
use yii\helpers\ArrayHelper;
use function file_exists;
use function file_get_contents;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Class SubsplitLocale.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SubsplitLocale {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $locale;
	private $fallbackLocale;

	public function __construct(?string $path, string $fallbackPath) {
		if ($path !== null && file_exists($path)) {
			$this->locale = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		} else {
			$this->locale = [];
		}
		$this->fallbackLocale = json_decode(file_get_contents($fallbackPath), true, 512, JSON_THROW_ON_ERROR);
	}

	public function t(string $key, array $params = []): string {
		$string = ArrayHelper::getValue($this->locale, $key);
		if ($string === null || $string === '') {
			$string = ArrayHelper::getValue($this->fallbackLocale, $key);
		}
		Assert::stringNotEmpty($string);
		return strtr($string, $params);
	}
}
