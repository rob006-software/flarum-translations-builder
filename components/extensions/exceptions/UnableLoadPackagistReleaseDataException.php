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

namespace app\components\extensions\exceptions;

use yii\base\InvalidArgumentException;

/**
 * Class UnableLoadPackagistReleaseDataException.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class UnableLoadPackagistReleaseDataException extends InvalidArgumentException implements
	UnprocessableExtensionExceptionInterface,
	SoftFailureInterface {

}
