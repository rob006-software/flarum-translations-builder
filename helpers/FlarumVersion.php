<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2026 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\helpers;

use Yii;

/**
 * Class Version.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class FlarumVersion {

	public const V1 = 'v1';
	public const V2 = 'v2';

	public static function branch(): string {
		switch (Yii::$app->params['flarumVersion']) {
			case self::V2:
				return 'flarum2';
			case self::V1:
				return 'master';
		}
	}

	public static function newPrPrefix(string $prefix = ''): string {
		switch (Yii::$app->params['flarumVersion']) {
			case self::V2:
				return "new2/{$prefix}";
			case self::V1:
				return "new/{$prefix}";
		}
	}

	public static function lineName(): string {
		switch (Yii::$app->params['flarumVersion']) {
			case self::V2:
				return '2.x';
			case self::V1:
				return '1.x';
		}
	}

	public static function weblateProject(): string {
		switch (Yii::$app->params['flarumVersion']) {
			case self::V2:
				return 'flarum2';
			case self::V1:
				return 'flarum';
		}
	}

	public static function composerConstraint(): string {
		switch (Yii::$app->params['flarumVersion']) {
			case self::V2:
				return '^2.0';
			case self::V1:
				return '^1.0';
		}
	}
}
