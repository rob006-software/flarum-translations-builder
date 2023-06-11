<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2023 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\helpers;

use Locale;

/**
 * Class Language.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class Language {

	private const OVERWRITES = [
		'bn' => 'Bengali',
		'ckb' => 'Kurdish (Central)',
		'de@formal' => 'German (formal)',
		'kmr' => 'Kurdish (Northern)',
	];

	public static function name(string $id): string {
		return self::OVERWRITES[$id] ?? Locale::getDisplayName($id, 'en');
	}
}
