<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\dayjs;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use function preg_replace;

/**
 * Class DayjsConfigGenerator.
 *
 * Generates `locale/config.js` of language pack with compiled Day.js locale. Flarum does not activate Day.js locale on
 * its own, so `config.js` needs to do this explicitly.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class DayjsConfigGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private const SOURCE_URL_PATTERN = '#https://github\.com/iamkun/dayjs/blob/[^/\s]+/src/locale/[^/\s)]+\.js#';

	private $version;
	private $locale;
	private $script;

	/**
	 * @param string $version Day.js version, like `1.11.23`.
	 * @param string $locale Day.js locale name, like `pl`.
	 * @param string $script Compiled locale from unpkg.com.
	 */
	public function __construct(string $version, string $locale, string $script) {
		$this->version = $version;
		$this->locale = $locale;
		$this->script = $script;
	}

	public function generate(): string {
		$scriptUrl = DayjsLocaleProvider::getLocaleScriptUrl($this->version, $this->locale);
		$sourceUrl = DayjsLocaleProvider::getLocaleSourceUrl($this->version, $this->locale);

		return <<<JS
			// source: $scriptUrl
			// see: $sourceUrl

			{$this->script}

			dayjs.locale('{$this->locale}');

			JS;
	}

	/**
	 * Checks whether existing `config.js` differs from generated one only by Day.js version in URLs - there is no
	 * point in updating it in such case, since the translation itself is the same.
	 */
	public function isUpToDate(string $config): bool {
		return self::normalize($config) === self::normalize($this->generate());
	}

	/**
	 * Updates links to Day.js locale source in README.
	 *
	 * @return string|null Updated README, or `null` if README does not contain any link to Day.js locale source.
	 */
	public function updateReadme(string $readme): ?string {
		$count = 0;
		$result = preg_replace(
			self::SOURCE_URL_PATTERN,
			DayjsLocaleProvider::getLocaleSourceUrl($this->version, $this->locale),
			$readme,
			-1,
			$count
		);

		return $count > 0 ? $result : null;
	}

	/**
	 * Removes Day.js version from URLs in `config.js` header.
	 */
	private static function normalize(string $config): string {
		return preg_replace(
			['#(https://unpkg\.com/dayjs@)[^/\s]+/#', '#(https://github\.com/iamkun/dayjs/blob/)[^/\s]+/#'],
			'$1X/',
			$config
		);
	}
}
