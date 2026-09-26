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

namespace app\components;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use RuntimeException;
use Symfony\Component\Process\Process;
use function array_merge;
use function json_decode;
use function json_encode;
use function trim;
use const JSON_THROW_ON_ERROR;

/**
 * Class GhCli.
 *
 * Wrapper for GitHub API calls made through `gh` CLI tool, authenticated as user logged in `gh` on the current machine.
 * This is intended for administrative tasks run manually, which require more permissions than bot has.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class GhCli {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $binary;

	public function __construct(string $binary = 'gh') {
		$this->binary = $binary;
	}

	/**
	 * @return array|null Decoded response, or `null` for empty response (like `204 No Content`).
	 */
	public function api(string $method, string $endpoint, ?array $data = null): ?array {
		$command = [$this->binary, 'api', '--method', $method, $endpoint];
		if ($data !== null) {
			$command[] = '--input';
			$command[] = '-';
		}

		$output = trim($this->run($command, $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR)));
		return $output === '' ? null : json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array Merged items from all pages of list endpoint.
	 */
	public function getAll(string $endpoint): array {
		$output = $this->run([$this->binary, 'api', '--paginate', '--slurp', $endpoint]);
		$pages = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		return empty($pages) ? [] : array_merge(...$pages);
	}

	public function getCurrentUser(): string {
		return $this->api('GET', 'user')['login'];
	}

	private function run(array $command, ?string $input = null): string {
		$process = new Process($command);
		$process->setTimeout(60);
		if ($input !== null) {
			$process->setInput($input);
		}
		$process->run();
		if (!$process->isSuccessful()) {
			throw new RuntimeException(trim($process->getErrorOutput()) ?: trim($process->getOutput()));
		}

		return $process->getOutput();
	}
}
