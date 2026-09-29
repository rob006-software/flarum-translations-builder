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
		if ($this->skipEmpty && !empty($data)) {
			$data = $this->removeEmpty($data, $resource);
		}

		return $data;
	}

	private function removeEmpty(array $data, string $resource): array {
		foreach ($data as $i => $item) {
			if (is_string($item)) {
				if ($item === '') {
					unset($data[$i]);
				}
			} elseif (is_array($item)) {
				$data[$i] = $this->removeEmpty($item, $resource);
				if (empty($data[$i])) {
					unset($data[$i]);
				}
			} else {
				$message = "Non-string translation occurred for '$i' key in '$resource'.";
				Yii::$app->frequencyLimiter->run(
					__METHOD__ . '#' . $message,
					31 * 24 * 3600,
					static function () use ($message) {
						Yii::warning($message, __CLASS__);
					}
				);
			}
		}

		return $data;
	}
}
