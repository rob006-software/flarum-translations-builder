<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\translations;

use app\models\Component;
use app\models\Project;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use mindplay\readable;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use yii\base\InvalidArgumentException;
use function file_exists;
use function array_filter;
use function file_get_contents;

/**
 * Class TranslationsImporter.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class TranslationsImporter {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var Project */
	private $project;
	/** @var Component */
	private $component;

	public function __construct(Project $project, Component $component) {
		$this->project = $project;
		$this->component = $component;
	}

	public function import(string $source, string $language): void {
		if (file_exists($source)) {
			throw new InvalidArgumentException(sprintf('File %s does not exist.', readable::value($source)));
		}

		$sourceTranslator = new Translator($language);
		$sourceTranslator->addLoader('yaml', new YamlLoader());
		$sourceTranslator->addResource('yaml', file_get_contents($source), $language, 'sources');

		$translator = new Translator($language);
		$translator->addLoader('array', new ArrayLoader());
		$translator->addLoader('json', new JsonFileLoader());
		$translator->addResource(
			'json',
			$this->project->getComponentTranslationPath($this->component, $language),
			$language,
			$this->component->getId()
		);

		// do not import empty translations
		$newTranslations = array_filter($sourceTranslator->getCatalogue()->all('sources'), static function ($data) {
			return $data !== '';
		});
		$translator->addResource('array', $newTranslations, $language, $this->component->getId());

		$catalogue = $translator->getCatalogue($language);
		assert($catalogue instanceof MessageCatalogue);
		$this->project->saveTranslations($catalogue, $this->project->getTranslationsPath($language));
	}
}
