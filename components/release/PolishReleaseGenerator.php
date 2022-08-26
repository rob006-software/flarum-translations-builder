<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\release;

use app\models\Repository;
use function strncmp;

/**
 * Class PolishReleaseGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PolishReleaseGenerator extends ReleaseGenerator {

	protected function getCoreChanges(): array {
		$coreChanges = parent::getCoreChanges();
		// treat changes inside `less/` dir as styles changes
		foreach ($this->getChangedFiles() as $file => $changeType) {
			if (strncmp('less/', $file, 5) === 0) {
				$coreChanges['config.css'] = Repository::CHANGE_MODIFIED;
				break;
			}
		}

		return $coreChanges;
	}
}
