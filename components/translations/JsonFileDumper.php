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

use Symfony\Component\Translation\Dumper\JsonFileDumper as BaseJsonFileDumper;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Util\ArrayConverter;

/**
 * Class JsonFileDumper.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class JsonFileDumper extends BaseJsonFileDumper {

	public function formatCatalogue(MessageCatalogue $messages, $domain, array $options = []) {
		$flags = $options['json_encoding'] ?? JSON_PRETTY_PRINT;

		$data = $messages->all($domain);
		if (isset($options['as_tree']) && $options['as_tree']) {
			$data = ArrayConverter::expandToTree($data);
		}

		return json_encode($data, $flags) . "\n";
	}
}
