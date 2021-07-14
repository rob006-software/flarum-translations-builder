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

use app\models\extiverse\exceptions\MissingVersionException;
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

	private function __construct(array $data) {
		foreach ($data as $field => $value) {
			$this->$field = $value;
		}
	}

	public static function createFromApiResponse(array $data, array $included): self {
		if ($data['attributes']['highest-version'] === null) {
			throw new MissingVersionException(
				"Missing version for {$data['attributes']['name']} extension.",
			);
		}

		$lastRelease = $data['attributes']['highest-version'];
		$versions = [];
		foreach ($data['relationships']['versions']['data'] as $version) {
			if ($version['type'] === 'extension-versions') {
				$versions[$version['id']] = $version['id'];
			}
		}

		$requiredFlarum = null;
		foreach ($included as $item) {
			if (
				$item['type'] === 'extension-versions' && isset($versions[$item['id']])
				&& $item['attributes']['version'] === $lastRelease
			) {
				$requiredFlarum = $item['attributes']['flarum-version-required'];
				break;
			}
		}

		return new self([
			'name' => $data['attributes']['name'],
			'title' => $data['attributes']['title'] ?? null,
			'description' => $data['attributes']['description'] ?? null,
			'version' => $data['attributes']['highest-version'],
			'downloads' => (int) $data['attributes']['downloads'],
			'subscribers' => (int) $data['attributes']['subscribers-count'],
			'requiredFlarum' => $requiredFlarum,
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
}
