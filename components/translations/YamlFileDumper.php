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

use Closure;
use Symfony\Component\Translation\Dumper\YamlFileDumper as BaseYamlFileDumper;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Util\ArrayConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Class YamlFileDumper.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class YamlFileDumper extends BaseYamlFileDumper {

	private $prefixGenerator;

	public function __construct(?Closure $prefixGenerator = null, string $extension = 'yml') {
		$this->prefixGenerator = $prefixGenerator;

		parent::__construct($extension);
	}

	/**
	 * {@inheritdoc}
	 */
	public function formatCatalogue(MessageCatalogue $messages, $domain, array $options = []) {
		$data = $messages->all($domain);

		if (isset($options['as_tree']) && $options['as_tree']) {
			$data = ArrayConverter::expandToTree($data);
		}

		$prefix = $this->prefixGenerator === null ? '' : ($this->prefixGenerator)($domain, $options);
		return $prefix . Yaml::dump($data, $options['inline'] ?? 2, 2, $options['flags'] ?? 0);
	}
}
