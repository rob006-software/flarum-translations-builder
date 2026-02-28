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

namespace app\components\inheritors;

/**
 * Interface InheritorInterface.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
interface InheritorInterface {

	public function inherit(): void;

	public function getId(): string;

	public function getHash(): string;

	public function getInheritFromLabel(): string;
}
