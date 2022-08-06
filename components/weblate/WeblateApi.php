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

namespace app\components\weblate;

use app\helpers\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use yii\base\Component;

/**
 * Class WeblateApi.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class WeblateApi extends Component {

	public const PRIORITY_VERY_HIGH = 60;
	public const PRIORITY_HIGH = 80;
	public const PRIORITY_MEDIUM = 100;
	public const PRIORITY_LOW = 120;
	public const PRIORITY_VERY_LOW = 140;

	/** @var string */
	public $weblateUrl = 'https://weblate.rob006.net';
	/** @var string */
	public $authToken;

	private $_client;

	private function getClient(): HttpClientInterface {
		if ($this->_client === null) {
			$this->_client = HttpClient::create([
				'headers' => [
					'Authorization' => "Token $this->authToken",
				],
			]);
		}
		return $this->_client;
	}

	public function updateComponentPriority(string $componentId, int $priority): void {
		$response = $this->getClient()->request('PATCH', "$this->weblateUrl/api/components/flarum/$componentId/", [
			'json' => ['priority' => $priority],
		]);
		// call this to trigger errors if response is invalid
		$response->toArray();
	}
}
