<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/* @noinspection PhpConcatenationWithEmptyStringCanBeInlinedInspection */

declare(strict_types=1);

namespace app\models;

/**
 * Class ForkRepository.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ForkRepository extends Repository {

	public function __construct(string $remote, string $upstream, ?string $branch, string $workingCopyDir) {
		parent::__construct($remote, $branch, $workingCopyDir);

		if (!$this->getWorkingCopy()->hasRemote('upstream')) {
			$this->getWorkingCopy()->addRemote('upstream', $upstream);
		}
	}

	public function rebase(): string {
		$output = '';
		$output .= $this->getWorkingCopy()->fetchAll(['prune' => true, 'prune-tags' => true]);
		$output .= $this->getWorkingCopy()->checkout($this->getBranch() ?? 'master');
		$output .= $this->getWorkingCopy()->pull('upstream', $this->getBranch() ?? 'master');
		$output .= $this->getWorkingCopy()->push();

		return $output;
	}
}
