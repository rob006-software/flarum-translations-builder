<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\models;

use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Symfony\Component\HttpClient\HttpClient;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use function json_decode;
use function parse_url;
use function strpos;
use function substr;
use const PHP_URL_PATH;

/**
 * Class Extension.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class Extension {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $id;
	private $repositoryUrl;
	private $config;

	public function __construct(string $id, string $repositoryUrl, array $config = []) {
		$this->id = $id;
		$this->repositoryUrl = $repositoryUrl;
		$this->config = $config;
	}

	public static function createFromGithubusercontentUrl(string $id, string $url): self {
		$path = trim(parse_url($url, PHP_URL_PATH), '/');
		$repositoryName = substr($path, 0, strpos($path, '/', strpos($path, '/') + 1));
		$repositoryUrl = "https://github.com/$repositoryName";

		return new static($id, $repositoryUrl);
	}

	public function getId(): string {
		return $this->id;
	}

	public function getExtensionName(): string {
		return $this->config['name']
			?? $this->getComposerValue('extra.flarum-extension.title')
			?? Inflector::titleize(strtr($this->getPackageName(), ['-' => ' ']));
	}

	public function getPackageName(): string {
		return explode('/', $this->getComposerValue('name'))[1];
	}

	public function getPackageVendor(): string {
		return explode('/', $this->getComposerValue('name'))[0];
	}

	public function getRepositoryUrl(): string {
		return $this->repositoryUrl;
	}

	private function getComposerValue(string $key) {
		return ArrayHelper::getValue($this->getComposerData(), $key);
	}

	private function generateGithubusercontentUrl(string $file, string $branch = 'master'): string {
		$path = trim(parse_url($this->repositoryUrl, PHP_URL_PATH), '/');
		return "https://raw.githubusercontent.com/{$path}/{$branch}/{$file}";
	}

	private function getComposerData(): array {
		return Yii::$app->cache->getOrSet($this->repositoryUrl, function () {
			$url = $this->generateGithubusercontentUrl('composer.json');
			$client = HttpClient::create();
			$response = $client->request('GET', $url);
			return json_decode($response->getContent(), true);
		}, 7 * 24 * 60 * 60);
	}
}
