<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2021 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\helpers;

use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class HttpClient.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class HttpClient {

	static private $client;

	public static function create(array $defaultOptions = []): HttpClientInterface {
		$defaultOptions['headers']['User-Agent'] = $defaultOptions['headers']['User-Agent'] ?? 'flarum-translations-builder (+https://github.com/rob006-software/flarum-translations-builder)';
		return SymfonyHttpClient::create($defaultOptions);
	}

	public static function get(string $url, array $options = []): ResponseInterface {
		if (self::$client === null) {
			self::$client = static::create();
		}

		return self::$client->request('GET', $url, $options);
	}
}
