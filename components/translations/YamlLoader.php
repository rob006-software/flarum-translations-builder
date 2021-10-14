<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\translations;

use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Yaml\Yaml;
use function is_array;

/**
 * Class YamlLoader.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class YamlLoader extends ArrayLoader {

	public function load($resource, $locale, $domain = 'messages') {
		$messages = Yaml::parse($resource);
		$messages = self::filter($messages);
		return parent::load($messages, $locale, $domain);
	}

	public static function filter($item) {
		if (is_array($item)) {
			foreach ($item as $key => $value) {
				$filtered = self::filter($value);
				if ($filtered === null || $filtered === []) {
					unset($item[$key]);
				} else {
					$item[$key] = $filtered;
				}
			}
		}

		return $item;
	}
}
