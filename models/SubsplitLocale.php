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
use MessageFormatter;
use RuntimeException;
use Webmozart\Assert\Assert;
use Yii;
use yii\helpers\ArrayHelper;
use function file_exists;
use function file_get_contents;
use function intl_get_error_message;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Class SubsplitLocale.
 *
 * Phrases use ICU MessageFormat syntax, like Flarum translations.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SubsplitLocale {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private const FALLBACK_LANGUAGE = 'en';

	private $language;
	private $locale;
	private $fallbackLocale;

	public function __construct(string $language, ?string $path, string $fallbackPath) {
		$this->language = $language;
		if ($path !== null && file_exists($path)) {
			$this->locale = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		} else {
			$this->locale = [];
		}
		$this->fallbackLocale = json_decode(file_get_contents($fallbackPath), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array $params Message arguments indexed by argument name (without braces), like `['count' => 3]`.
	 */
	public function t(string $key, array $params = []): string {
		$string = ArrayHelper::getValue($this->locale, $key);
		if ($string !== null && $string !== '') {
			$result = MessageFormatter::formatMessage($this->language, $string, $params);
			if ($result !== false) {
				return $result;
			}
			// broken translation should not break release - use English phrase instead
			Yii::warning("Unable to format '$key' phrase for '$this->language' language: " . intl_get_error_message(), __METHOD__);
		}

		$string = ArrayHelper::getValue($this->fallbackLocale, $key);
		Assert::stringNotEmpty($string);
		$result = MessageFormatter::formatMessage(self::FALLBACK_LANGUAGE, $string, $params);
		if ($result === false) {
			throw new RuntimeException("Unable to format '$key' phrase: " . intl_get_error_message());
		}

		return $result;
	}
}
