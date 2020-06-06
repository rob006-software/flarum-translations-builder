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

use app\models\extiverse\ApiResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use yii\base\Component;
use function json_decode;

/**
 * Class ExtiverseApi.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class ExtiverseApi extends Component {

	public $authToken;
	public $apiUrl = 'https://extiverse.com/api/v1';
	public $cacheUrl = 'https://raw.githubusercontent.com/rob006-software/flarum-translations/master/cache/extiverse.json';

	private $_client;
	private $_cachedExtensions;

	/**
	 * @return ApiResult[]
	 */
	public function searchExtensions(): array {
		$response['links']['next'] = $this->apiUrl . '/extensions?sort=-created_at&filter[is][]=premium';

		$results = [];
		do {
			$response = $this->getClient()->request('GET', $response['links']['next'])->toArray();
			foreach ($response['data'] as $item) {

				$result = ApiResult::createFromApiResponse($item);
				$results[$result->getName()] = $result;
			}
		} while (isset($response['links']['next']));

		return $results;
	}

	public function getCachedExtensions(): array {
		if ($this->_cachedExtensions === null) {
			// We cannot use toArray() here - raw.githubusercontent.com always returns content-type as
			// "text/plain; charset=utf-8" while HttpClient expects JSON compatible content-type header.
			$response = HttpClient::create()->request('GET', $this->cacheUrl);
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
