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

namespace app\components;

use app\helpers\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;
use yii\base\Component;
use function rtrim;

/**
 * Class FlarumApi.
 *
 * Simple client for Flarum forum API.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class FlarumApi extends Component {

	/** @var string */
	public $forumUrl = 'https://discuss.flarum.org/';
	/** @var string */
	public $authToken;

	private $_client;

	public function init() {
		Assert::notNull($this->authToken, 'Authentication token is required.');

		parent::init();
	}

	/**
	 * Creates a new post (reply) in given discussion.
	 *
	 * @return array Attributes of created post, as returned by API.
	 */
	public function createPost(int $discussionId, string $content): array {
		$response = $this->getClient()->request('POST', $this->getApiUrl() . '/posts', [
			'json' => [
				'data' => [
					'type' => 'posts',
					'attributes' => [
						'content' => $content,
					],
					'relationships' => [
						'discussion' => [
							'data' => [
								'type' => 'discussions',
								'id' => (string) $discussionId,
							],
						],
					],
				],
			],
		]);

		return $response->toArray()['data'];
	}

	public function getDiscussionUrl(int $discussionId): string {
		return $this->getForumUrl() . "/d/$discussionId";
	}

	public function getPostUrl(int $discussionId, int $postNumber): string {
		return $this->getDiscussionUrl($discussionId) . "/$postNumber";
	}

	public function getForumUrl(): string {
		return rtrim($this->forumUrl, '/');
	}

	private function getApiUrl(): string {
		return $this->getForumUrl() . '/api';
	}

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
}
