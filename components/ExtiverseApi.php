<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components;

use app\components\extensions\exceptions\SoftFailureInterface;
use app\helpers\HttpClient;
use app\models\extiverse\ApiResult;
use app\models\extiverse\exceptions\InvalidApiResponseException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Yii;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\di\Instance;
use function json_decode;

/**
 * Class ExtiverseApi.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ExtiverseApi extends Component {

	public $authToken;
	public $apiUrl = 'https://flarum.org/api';
	public $cacheUrl = 'https://raw.githubusercontent.com/rob006-software/flarum-translations/master/cache/extiverse.json';

	/** @var string|array|CacheInterface */
	public $cache = 'arrayCache';
	/** @var int */
	public $cacheDuration = 3600;

	private $_client;
	private $_cachedExtensions;

	public function init(): void {
		parent::init();

		$this->cache = Instance::ensure($this->cache, CacheInterface::class);
	}

	/**
	 * @param bool $useCache
	 * @return ApiResult[]
	 */
	public function searchExtensions(bool $useCache = true): array {
		$callback = function () {
			$response['links']['next'] = $this->apiUrl . '/extensions?filter[is][]=premium&page[limit]=100';

			$results = [];
			do {
				$response = $this->getClient()->request('GET', $response['links']['next'])->toArray();
				foreach ($response['data'] as $item) {
					try {
						$result = ApiResult::createFromApiResponse($item);
						$results[$result->getName()] = $result;
					} catch (InvalidApiResponseException $exception) {
						if (!$exception instanceof SoftFailureInterface) {
							Yii::warning($exception->getMessage());
						}
					}
				}
			} while (isset($response['links']['next']));

			return $results;
		};

		if ($useCache) {
			return $this->cache->getOrSet(__METHOD__, $callback, $this->cacheDuration);
		}

		$result = $callback();
		Yii::$app->cache->set(__METHOD__, $result);
		return $result;
	}

	public function getCachedExtensions(): array {
		if ($this->_cachedExtensions === null) {
			// We cannot use toArray() here - raw.githubusercontent.com always returns content-type as
			// "text/plain; charset=utf-8" while HttpClient expects JSON compatible content-type header.
			$response = HttpClient::get($this->cacheUrl);
			/* @noinspection JsonEncodingApiUsageInspection */
			$this->_cachedExtensions = json_decode($response->getContent(), true);
		}

		return $this->_cachedExtensions;
	}

	private function getClient(): HttpClientInterface {
		if ($this->_client === null) {
			$this->_client = HttpClient::create([
				'auth_bearer' => $this->authToken,
			]);
		}

		return $this->_client;
	}
}
