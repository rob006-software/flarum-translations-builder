<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\translations;

use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * Class YamlLoader.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class YamlLoader extends ArrayLoader {

	public function load($resource, $locale, $domain = 'messages') {
		$messages = Yaml::parse($resource);
		return parent::load($messages, $locale, $domain);
	}
}
