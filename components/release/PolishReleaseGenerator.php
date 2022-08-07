<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2020 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\components\release;

use app\models\Extension;
use app\models\Repository;
use Yii;
use function file_get_contents;
use function json_decode;
use function ltrim;
use function strncmp;

/**
 * Class PolishReleaseGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PolishReleaseGenerator extends ReleaseGenerator {

	protected $versionTemplate = 'v0.Major.Minor';

	protected function generateChangelogEntryContent(): string {
		$added = [];
		$changed = [];
		$removed = [];
		foreach ($this->getChangedExtensions() as $extensionId => $changeType) {
			if ($changeType === Repository::CHANGE_ADDED) {
				$added[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId, false);
			} elseif ($changeType === Repository::CHANGE_MODIFIED) {
				$changed[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId, false);
			} elseif ($changeType === Repository::CHANGE_DELETED) {
				$removed[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId, false);
			}
		}

		$content = '';

		if (!empty($this->getCoreChanges())) {
			$content .= "**Ogólne usprawnienia**:\n\n";
			foreach ($this->getCoreChanges() as $file => $changeType) {
				$label = $this->getCoreChangesLabels()[$file] ?? "Aktualizacja `$file`";
				$content .= "* $label.\n";
			}
			$content .= "\n\n";
		}

		if (!empty($added)) {
			$content .= "**Dodano wsparcie dla nowych rozszerzeń**:\n\n";
			/* @var $extension Extension */
			foreach ($added as $extension) {
				$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
			}
			$content .= "\n\n";
		}
		if (!empty($changed)) {
			$content .= $this->isMajorUpdate()
				? "**Usunięto przestarzałe frazy dla rozszerzeń**:\n\n"
				: "**Zaktualizowano tłumaczenia dla rozszerzeń**:\n\n";
			/* @var $extension Extension */
			foreach ($changed as $extension) {
				$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
			}
			$content .= "\n\n";
		}
		if (!empty($removed)) {
			$content .= "**Usunięto wsparcie dla przestarzałych rozszerzeń**:\n\n";
			/* @var $extension Extension */
			foreach ($removed as $id => $extension) {
				if ($extension === null) {
					$content .= "* `$id`\n";
				} else {
					$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
				}
			}
			$content .= "\n\n";
		}

		$old = $this->getPreviousVersion();
		$new = $this->getNextVersion();
		$content .= "Wszystkie zmiany: [{$old}...{$new}](https://github.com/rob006-software/flarum-lang-polish/compare/{$old}...{$new}).\n\n\n";

		return $content;
	}

	private function getCoreChangesLabels(): array {
		return [
			'core.yml' => $this->isMajorUpdate()
				? "Usunięto przestarzałe frazy dla głównego silnika Flarum (wspierana jest wersja `{$this->getSupportedFlarumVersion()}` lub wyższa)"
				: 'Aktualizacja tłumaczeń głównego silnika Flarum',
			'validation.yml' => $this->isMajorUpdate()
				? "Usunięto przestarzałe komunikaty walidacji (wspierana jest wersja `{$this->getSupportedFlarumVersion()}` lub wyższa)"
				: 'Aktualizacja tłumaczeń komunikatów walidacji',
			'config.js' => 'Aktualizacja tłumaczeń Day.js',
			'config.css' => 'Aktualizacja stylów',
		];
	}

	private function getSupportedFlarumVersion(): string {
		/* @noinspection JsonEncodingApiUsageInspection */
		$composerInfo = json_decode(file_get_contents($this->getRepository()->getPath() . '/composer.json'), true);
		return ltrim($composerInfo['require']['flarum/core'], '~^');
	}

	public function getAnnouncement(): string {
		$command = $this->isMajorUpdate() ? 'require' : 'update';
		$warning = !$this->isMajorUpdate()
			? ''
			: <<<MD
				
				**Ta wersja usuwa wsparcie dla starszych wersji Flarum oraz starszych wersji niektórych rozszerzeń. 
				Przed aktualizacją upewnij się, że twoje forum jest aktualne i korzysta z najnowszych wersji rozszerzeń.**
				
				MD;

		return <<<MD
			https://discuss.flarum.org/d/18134-polish-language-pack
			-------------------------------------------------------
			## Wersja [`{$this->getNextVersion()}`](https://github.com/rob006-software/flarum-lang-polish/releases/tag/{$this->getNextVersion()})
			
			{$this->generateChangelogEntryContent()}
			Aby zaktualizować:
			
			```console
			composer $command rob006/flarum-lang-polish
			php flarum cache:clear
			```
			$warning
			MD;
	}

	protected function getCoreChanges(): array {
		$coreChanges = parent::getCoreChanges();
		$changes = $this->getRepository()->getChangesFrom($this->getPreviousVersion());
		// treat changes inside `less/` dir as styles changes
		foreach ($changes as $file => $changeType) {
			if (strncmp('less/', $file, 5) === 0) {
				$coreChanges['config.css'] = Repository::CHANGE_MODIFIED;
				break;
			}
		}

		return $coreChanges;
	}
}
