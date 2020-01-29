<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\models;

use function strncmp;

/**
 * Class ForkRepository.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ForkRepository extends Repository {

	private $_branches;

	public function __construct(string $remote, string $upstream, ?string $branch, string $workingCopyDir) {
		parent::__construct($remote, $branch, $workingCopyDir);

		if (!$this->getWorkingCopy()->hasRemote('upstream')) {
			$this->getWorkingCopy()->addRemote('upstream', $upstream);
		}
	}

	public function rebase(): string {
		$output = '';
		$output .= $this->getWorkingCopy()->fetchAll(['prune' => true]);
		$output .= $this->getWorkingCopy()->checkout('master');
		$output .= $this->getWorkingCopy()->pull('upstream', 'master');
		$output .= $this->getWorkingCopy()->push();

		return $output;
	}

	public function syncBranchesWithRemote(): string {
		$output = '';
		$branches = $this->getWorkingCopy()->getBranches()->all();
		foreach ($branches as $branch) {
			if (
				strncmp($branch, 'new/', 4) === 0
				&& !in_array("remotes/origin/$branch", $branches, true)
			) {
				$output .= $this->getWorkingCopy()->branch('-D', $branch);
			} elseif (
				strncmp($branch, 'remotes/origin/', 15) === 0 && strpos($branch, ' -> ') === false
				&& !in_array(substr($branch, 15), $branches, true)
			) {
				$output .= $this->getWorkingCopy()->checkout('-b', substr($branch, 15), '--track', 'origin/' . substr($branch, 15));
				$output .= $this->getWorkingCopy()->checkout('master');
			}
		}

		return $output;
	}

	public function hasBranch(string $name, bool $useCache = true): bool {
		return in_array($name, $this->getBranches($useCache), true);
	}

	public function createBranch(string $name): string {
		$output = '';
		$output .= $this->getWorkingCopy()->checkoutNewBranch($name);
		$output .= $this->getWorkingCopy()->push('--set-upstream', 'origin', $name);

		return $output;
	}

	public function checkoutBranch(string $name): string {
		return $this->getWorkingCopy()->checkout($name)
			. $this->getWorkingCopy()->pull();
	}

	public function getBranches(bool $useCache = true): array {
		if ($this->_branches === null || !$useCache) {
			$this->_branches = $this->getWorkingCopy()->getBranches()->all();
		}

		return $this->_branches;
	}
}
