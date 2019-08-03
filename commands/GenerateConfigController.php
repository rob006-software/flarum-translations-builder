<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\commands;

use Symfony\Component\HttpClient\HttpClient;
use yii\console\Controller;
use function parse_url;
use function strncmp;
use function strtolower;
use function strtr;
use function var_export;
use const PHP_URL_PATH;

/**
 * Class GenerateConfigController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class GenerateConfigController extends Controller {

	public function actionFof() {
		$client = HttpClient::create();
		$response = $client->request('GET', 'https://packagist.org/search.json?type=flarum-extension&q=fof&per_page=100');
		$packages = [];
		foreach ($response->toArray()['results'] as $package) {
			if (strncmp($package['name'], 'fof/', 4) === 0 && !isset($package['abandoned'])) {
				$packageId = strtr(strtolower($package['name']), [
					'/flarum-ext' => '-',
					'/flarum' => '-',
					'/' => '-',
				]);

				$repoPath = trim(parse_url($package['repository'], PHP_URL_PATH), '/');
				$packages[$packageId] = "https://raw.githubusercontent.com/$repoPath/master/resources/locale/en.yml";
			}
		}

		/* @noinspection ForgottenDebugOutputInspection */
		var_export($packages);
	}
}
