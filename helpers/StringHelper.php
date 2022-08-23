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

namespace app\helpers;

use yii\helpers\StringHelper as BaseStringHelper;
use function explode;

/**
 * Class StringHelper.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class StringHelper extends BaseStringHelper {

	public static function getBetween(string $string, string $start, string $end): ?string {
		$parts = explode($start, $string, 2);
		if (isset($parts[1])) {
			return explode($end, $parts[1], 2)[0];
		}

		return null;
	}
}
