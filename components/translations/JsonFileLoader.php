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

namespace app\components\translations;

use Symfony\Component\Translation\Loader\JsonFileLoader as BaseJsonFileLoader;
use Yii;
use function is_array;
use function is_string;

/**
 * Class JsonFileLoader.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class JsonFileLoader extends BaseJsonFileLoader {

	public $skipEmpty = false;

	public function __construct(array $config = []) {
		Yii::configure($this, $config);
	}

	protected function loadResource(string $resource) {
		$data = parent::loadResource($resource);
		if (is_array($data)) {
			$data = $this->filter($data, $resource);
		}

		return $data;
	}

	private function filter(array $data, string $resource): array {
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$value = $this->filter($value, $resource);
			} elseif (!is_string($value) && $value !== null) {
				$message = "Non-string translation occurred for '$key' key in '$resource'.";
				Yii::$app->frequencyLimiter->run(
					__METHOD__ . '#' . $message,
					31 * 24 * 3600,
					static function () use ($message) {
						Yii::warning($message, __CLASS__);
					}
				);
				$value = null;
			}

			if ($value === null || $value === [] || ($this->skipEmpty && $value === '')) {
				unset($data[$key]);
			} else {
				$data[$key] = $value;
			}
		}

		return $data;
	}
}
