<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2021 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\models\extiverse\exceptions;

use app\components\extensions\exceptions\UnprocessableExtensionInterface;
use yii\base\InvalidArgumentException;

/**
 * Class InvalidApiResponseException.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class InvalidApiResponseException extends InvalidArgumentException implements UnprocessableExtensionInterface {

}
