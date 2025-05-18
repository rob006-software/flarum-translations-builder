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
use Yii;
use function parse_url;

/**
 * Class HttpClient.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class HttpClient {

	public const USER_AGENT = 'flarum-translations-builder (+https://github.com/rob006-software/flarum-translations-builder)';

	/** @var HttpClientInterface[] */
	static private $client = [];

	public static function create(array $defaultOptions = []): HttpClientInterface {
		$defaultOptions['headers']['User-Agent'] = $defaultOptions['headers']['User-Agent'] ?? self::USER_AGENT;
		return SymfonyHttpClient::create($defaultOptions);
	}

	public static function get(string $url, array $options = []): ResponseInterface {
		$host = parse_url($url, PHP_URL_HOST);
		if (!isset(self::$client[$host])) {
			$defaultOptions = [];
			if ($host === 'raw.githubusercontent.com' && Yii::$app->githubApi->authToken !== null) {
				$defaultOptions['auth_bearer'] = Yii::$app->githubApi->authToken;
			}
			self::$client[$host] = static::create($defaultOptions);
		}

		return self::$client[$host]->request('GET', $url, $options);
	}
}
