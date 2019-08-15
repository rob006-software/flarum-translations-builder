<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\extensions\exceptions;

use yii\base\InvalidArgumentException;

/**
 * Class InvalidRepositoryUrlException.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class InvalidRepositoryUrlException extends InvalidArgumentException implements UnprocessableExtensionInterface {

}
