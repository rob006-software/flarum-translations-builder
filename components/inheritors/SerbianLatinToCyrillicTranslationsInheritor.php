<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2025 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);


namespace app\components\inheritors;

use Turanjanin\SerbianTransliterator\Transliterator;
use function str_starts_with;

/**
 * Class SerbianLatinToCyrillicTranslationsInheritor.
 *
 * @see https://github.com/rob006-software/flarum-translations/issues/1052#issuecomment-3399134450
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class SerbianLatinToCyrillicTranslationsInheritor extends TranslationsInheritor {

	protected function processFromTranslation(string $translation): string {
		if (str_starts_with($translation, '=> ')) {
			return $translation;
		}

		return Transliterator::toCyrillic($translation);
	}
}
