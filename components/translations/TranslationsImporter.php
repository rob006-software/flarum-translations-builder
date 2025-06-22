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

namespace app\components\translations;

use app\models\Component;
use app\models\Translations;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use function array_filter;
use function file_exists;
use function file_get_contents;

/**
 * Class TranslationsImporter.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class TranslationsImporter {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	/** @var Translations */
	private $translations;
	/** @var Component */
	private $component;

	public function __construct(Translations $translations, Component $component) {
		$this->translations = $translations;
		$this->component = $component;
	}

	public function import(string $source, string $language): void {
		$sourceTranslator = new Translator($language);
		$sourceTranslator->addLoader('yaml', new YamlLoader());
		$sourceTranslator->addResource('yaml', file_get_contents($source), $language, 'sources');

		$translator = new Translator($language);
		$translator->addLoader('array', new ArrayLoader());
		$translator->addLoader('json', new JsonFileLoader(['skipEmpty' => false]));
		if (file_exists($this->translations->getComponentTranslationPath($this->component->getId(), $language))) {
			$translator->addResource(
				'json',
				$this->translations->getComponentTranslationPath($this->component->getId(), $language),
				$language,
				$this->component->getId()
			);
		}

		// do not import empty translations
		$newTranslations = array_filter($sourceTranslator->getCatalogue()->all('sources'), static function ($data) {
			return $data !== '';
		});
		$translator->addResource('array', $newTranslations, $language, $this->component->getId());

		$catalogue = $translator->getCatalogue($language);
		assert($catalogue instanceof MessageCatalogue);
		$this->translations->saveTranslations($catalogue, $this->translations->getTranslationsPath($language));
	}
}
