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

namespace app\models\extiverse;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;

/**
 * Class ApiResult.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ApiResult {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var string */
	private $name;
	/** @var string|null */
	private $title;
	/** @var string|null */
	private $description;
	/** @var string */
	private $version;
	/** @var int */
	private $downloads;
	/** @var int */
	private $subscribers;
	/** @var string|null */
	private $requiredFlarum;
	/** @var int */
	private $subscriptionPlansCount;

	private function __construct(array $data) {
		foreach ($data as $field => $value) {
			$this->$field = $value;
		}
	}

	public static function createFromApiResponse(array $data): self {
		return new self([
			'name' => $data['attributes']['name'],
			'title' => $data['attributes']['title'] ?? null,
			'description' => $data['attributes']['description'] ?? null,
			'version' => $data['attributes']['highest-version'],
			'downloads' => (int) $data['attributes']['downloads'],
			'subscribers' => (int) $data['attributes']['subscribers-count'],
			'requiredFlarum' => $data['attributes']['latest-flarum-version-supported'],
			'subscriptionPlansCount' => (int) $data['attributes']['plans-count'],
		]);
	}

	public function getName(): string {
		return $this->name;
	}

	public function getTitle(): ?string {
		return $this->title;
	}

	public function getDescription(): ?string {
		return $this->description;
	}

	public function getVersion(): string {
		return $this->version;
	}

	public function getDownloads(): int {
		return $this->downloads;
	}

	public function getSubscribers(): int {
		return $this->subscribers;
	}

	public function getRequiredFlarum(): ?string {
		return $this->requiredFlarum;
	}

	public function getSubscriptionPlansCount(): int {
		return $this->subscriptionPlansCount;
	}
}
