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
use InvalidArgumentException;
use mindplay\readable;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;
use yii\base\Component;
use function in_array;
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

	/**
	 * Creates a new discussion.
	 *
	 * @param int[] $tagIds
	 * @return array Created discussion, as returned by API.
	 */
	public function createDiscussion(string $title, string $content, array $tagIds): array {
		$tags = [];
		foreach ($tagIds as $tagId) {
			$tags[] = [
				'type' => 'tags',
				'id' => (string) $tagId,
			];
		}
		$response = $this->getClient()->request('POST', $this->getApiUrl() . '/discussions', [
			'json' => [
				'data' => [
					'type' => 'discussions',
					'attributes' => [
						'title' => $title,
						'content' => $content,
					],
					'relationships' => [
						'tags' => [
							'data' => $tags,
						],
					],
				],
			],
		]);

		return $response->toArray()['data'];
	}

	/**
	 * @return array Discussion with its first post (in `included`), as returned by API.
	 */
	public function getDiscussionWithFirstPost(int $discussionId): array {
		$response = $this->getClient()->request('GET', $this->getApiUrl() . "/discussions/$discussionId", [
			'query' => [
				'include' => 'firstPost',
			],
		]);

		return $response->toArray();
	}

	/**
	 * Updates content of existing post.
	 *
	 * @return array Attributes of updated post, as returned by API.
	 */
	public function updatePost(int $postId, string $content): array {
		$response = $this->getClient()->request('PATCH', $this->getApiUrl() . "/posts/$postId", [
			'json' => [
				'data' => [
					'type' => 'posts',
					'id' => (string) $postId,
					'attributes' => [
						'content' => $content,
					],
				],
			],
		]);

		return $response->toArray()['data'];
	}

	/**
	 * @param string[] $slugs
	 * @return int[] IDs of tags, indexed by slug.
	 */
	public function getTagIds(array $slugs): array {
		$response = $this->getClient()->request('GET', $this->getApiUrl() . '/tags');
		$ids = [];
		foreach ($response->toArray()['data'] as $tag) {
			if (in_array($tag['attributes']['slug'], $slugs, true)) {
				$ids[$tag['attributes']['slug']] = (int) $tag['id'];
			}
		}
		foreach ($slugs as $slug) {
			if (!isset($ids[$slug])) {
				throw new InvalidArgumentException('There is no tag with ' . readable::value($slug) . " slug on {$this->getForumUrl()}.");
			}
		}

		return $ids;
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
