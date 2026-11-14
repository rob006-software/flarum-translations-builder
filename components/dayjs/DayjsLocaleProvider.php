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
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function is_string;
use function preg_match;
use function strncmp;
use function trim;

/**
 * Class DayjsLocaleProvider.
 *
 * Fetches compiled Day.js locales from unpkg.com - the same files that are pasted into `locale/config.js` of language packs.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class DayjsLocaleProvider {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** Flarum uses Day.js 1.x - locales from other major versions may be incompatible. */
	private const VERSION_CONSTRAINT = '1';

	private $client;
	private $latestVersion;

	public function __construct(?HttpClientInterface $client = null) {
		$this->client = $client ?? HttpClient::create();
	}

	/**
	 * @return string Latest Day.js version matching `VERSION_CONSTRAINT`, like `1.11.23`.
	 */
	public function getLatestVersion(): string {
		if ($this->latestVersion === null) {
			$url = 'https://unpkg.com/dayjs@' . self::VERSION_CONSTRAINT . '/package.json';
			$version = $this->client->request('GET', $url)->toArray()['version'] ?? null;
			if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
				throw new RuntimeException("Unable to detect latest Day.js version from $url.");
			}
			$this->latestVersion = $version;
		}

		return $this->latestVersion;
	}

	/**
	 * @return string Compiled (UMD) Day.js locale for given version.
	 */
	public function getLocaleScript(string $version, string $locale): string {
		$url = self::getLocaleScriptUrl($version, $locale);
		$script = trim($this->client->request('GET', $url)->getContent());
		// make sure that we got compiled locale, and not some error page
		if (strncmp($script, '!function(', 10) !== 0) {
			throw new RuntimeException("Unexpected content of $url.");
		}

		return $script;
	}

	public static function getLocaleScriptUrl(string $version, string $locale): string {
		return "https://unpkg.com/dayjs@$version/locale/$locale.js";
	}

	public static function getLocaleSourceUrl(string $version, string $locale): string {
		return "https://github.com/iamkun/dayjs/blob/v$version/src/locale/$locale.js";
	}
}
