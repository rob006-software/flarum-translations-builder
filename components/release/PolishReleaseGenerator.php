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
use function strncmp;

/**
 * Class PolishReleaseGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PolishReleaseGenerator extends ReleaseGenerator {

	protected $versionTemplate = 'v0.Major.Minor.Patch';
	protected $skipPatch = true;

	protected function generateChangelogEntryContent(): string {
		$added = [];
		$changed = [];
		$removed = [];
		foreach ($this->getChangedExtensions() as $extensionId => $changeType) {
			if ($changeType === Repository::CHANGE_ADDED) {
				$added[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId);
			} elseif ($changeType === Repository::CHANGE_MODIFIED) {
				$changed[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId);
			} elseif ($changeType === Repository::CHANGE_DELETED) {
				$removed[$extensionId] = Yii::$app->extensionsRepository->getExtension($extensionId);
			}
		}

		$content = '';

		if (!empty($this->getCoreChanges())) {
			$content .= "**Ogólne usprawnienia**:\n\n";
			foreach ($this->getCoreChanges() as $file => $changeType) {
				$label = self::getCoreChangesLabels()[$file] ?? "Aktualizacja `$file`";
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
			$content .= "**Zaktualizowano tłumaczenia dla rozszerzeń**:\n\n";
			/* @var $extension Extension */
			foreach ($changed as $id => $extension) {
				$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
			}
			$content .= "\n\n";
		}
		if (!empty($removed)) {
			$content .= "**Usunięto wsparcie dla rozszerzeń**:\n\n";
			/* @var $extension Extension */
			foreach ($removed as $extension) {
				$content .= "* [`{$extension->getPackageName()}`]({$extension->getRepositoryUrl()})\n";
			}
			$content .= "\n\n";
		}

		$old = $this->getPreviousVersion();
		$new = $this->getNextVersion();
		$content .= "Wszystkie zmiany: [{$old}...{$new}](https://github.com/rob006-software/flarum-lang-polish/compare/{$old}...{$new}).\n\n\n";

		return $content;
	}

	private static function getCoreChangesLabels(): array {
		return [
			'core.yml' => 'Aktualizacja tłumaczeń głównego silnika Flarum',
			'validation.yml' => 'Aktualizacja tłumaczeń komunikatów walidacji',
			'config.js' => 'Aktualizacja formatu dat',
			'config.css' => 'Aktualizacja stylów',
		];
	}

	public function getAnnouncement(): string {
		return <<<MD
https://discuss.flarum.org/d/18134-polish-language-pack
-------------------------------------------------------
## Wersja [`{$this->getNextVersion()}`](https://github.com/rob006-software/flarum-lang-polish/releases/tag/{$this->getNextVersion()})

{$this->generateChangelogEntryContent()}
Aby zaktualizować:

```console
composer update rob006/flarum-lang-polish
php flarum cache:clear
```

MD;
	}

	protected function getCoreChanges(): array {
		$coreChanges = parent::getCoreChanges();
		$changes = $this->getRepository()->getChangesFrom($this->getPreviousVersion());
		// treat changes inside of `less/` dir as styles changes
		foreach ($changes as $file => $changeType) {
			if (strncmp('less/', $file, 5) === 0) {
				$coreChanges['config.css'] = Repository::CHANGE_MODIFIED;
				break;
			}
		}

		return $coreChanges;
	}
}
