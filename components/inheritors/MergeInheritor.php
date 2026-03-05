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

use app\models\Repository;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use yii\helpers\FileHelper;
use function array_map;
use function basename;
use function json_encode;
use function ksort;
use function md5;
use function md5_file;
use const JSON_THROW_ON_ERROR;

/**
 * Class MergeInheritor.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class MergeInheritor implements InheritorInterface {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var string */
	private $id;
	/** @var string */
	private $inheritFromLabel;
	/** @var string */
	private $metadataFileTemplate;

	/** @var Repository */
	private $inheritFromRepository;
	/** @var Repository */
	private $inheritToRepository;

	public function __construct(
		$id,
		$inheritFromLabel,
		Repository $inheritFromRepository,
		Repository $inheritToRepository,
		$metadataFileTemplate
	) {
		$this->id = $id;
		$this->inheritFromLabel = $inheritFromLabel;
		$this->inheritFromRepository = $inheritFromRepository;
		// need this to ensure that `getHash()` will use up-to-date data
		// (this uses filesystem repo as a remote, so it should be really fast)
		$this->inheritFromRepository->update();
		$this->inheritToRepository = $inheritToRepository;
		$this->inheritToRepository->addRemote("{$id}-upstream", $this->inheritFromRepository->getPath());
		$this->metadataFileTemplate = $metadataFileTemplate;
	}

	public function inherit(): void {
		$this->inheritFromRepository->update();

		// simulate merge, but with discarding all changes from the merged branch - we will handle fetching related
		// changes using inheritors
		$this->inheritToRepository->fetch("{$this->id}-upstream");
		$this->inheritToRepository->merge('-s', 'ours', "{$this->id}-upstream/{$this->inheritFromRepository->getBranch()}", '--no-commit', '--no-ff');

		// update translations using inheritors
		$languages = array_map(
			'basename',
			FileHelper::findDirectories($this->inheritFromRepository->getPath() . '/translations', ['recursive' => false])
		);
		foreach ($languages as $language) {
			$inheritor = new TranslationsInheritor(
				$language,
				'',
				$this->inheritFromRepository->getPath() . '/sources',
				$this->inheritFromRepository->getPath() . '/translations/' . $language,
				$this->inheritToRepository->getPath() . '/sources',
				$this->inheritToRepository->getPath() . '/translations/' . $language,
				strtr($this->metadataFileTemplate, ['{language}' => $language]),
			);
			$inheritor->inherit();
		}
	}

	public function getId(): string {
		return $this->id;
	}

	public function getHash(): string {
		$values = [];
		$filesToTest = [];
		$inheritToSourcesPath = $this->inheritToRepository->getPath() . '/sources';
		foreach (FileHelper::findFiles($inheritToSourcesPath, ['only' => ['*.json']]) as $file) {
			$filesToTest[] = basename($file);
		}

		foreach (FileHelper::findFiles($this->inheritToRepository->getPath() . '/translations', ['only' => ['*.json']]) as $file) {
			if (in_array(basename($file), $filesToTest, true)) {
				$values[$file] = md5_file($file);
			}
		}
		foreach (FileHelper::findFiles($this->inheritFromRepository->getPath() . '/translations', ['only' => ['*.json']]) as $file) {
			if (in_array(basename($file), $filesToTest, true)) {
				$values[$file] = md5_file($file);
			}
		}
		ksort($values);

		return md5(json_encode($values, JSON_THROW_ON_ERROR));
	}

	public function getInheritFromLabel(): string {
		return $this->inheritFromLabel;
	}
}
