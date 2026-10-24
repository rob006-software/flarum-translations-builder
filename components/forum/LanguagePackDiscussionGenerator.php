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

namespace app\components\forum;

/**
 * Class LanguagePackDiscussionGenerator.
 *
 * Generates title and first post of language pack discussion on forum. Covers both Flarum lines, so the result does
 * not depend on the instance it is generated from.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class LanguagePackDiscussionGenerator {

	private $languageName;
	private $language;
	private $packageName;

	/**
	 * @param string $languageName English name of the language, like `Polish`.
	 * @param string $language Language code used by Weblate and status pages, like `pl`.
	 * @param string $packageName Composer package name, like `flarum-lang/polish`.
	 */
	public function __construct(string $languageName, string $language, string $packageName) {
		$this->languageName = $languageName;
		$this->language = $language;
		$this->packageName = $packageName;
	}

	public function generateTitle(): string {
		return "{$this->languageName} Language Pack";
	}

	public function generateContent(): string {
		$package = $this->packageName;
		$language = $this->language;

		return <<<MD
			[![License](https://img.shields.io/packagist/l/$package)](https://packagist.org/packages/$package) [![Total Downloads](https://img.shields.io/packagist/dt/$package)](https://packagist.org/packages/$package/stats) [![Monthly Downloads](https://img.shields.io/packagist/dm/$package)](https://packagist.org/packages/$package/stats)

			{$this->languageName} language pack for [Flarum](https://flarum.org), its bundled extensions and many popular third-party extensions. Supports Flarum 1.x and 2.x.

			### Installation

			```
			composer require "$package:*"
			```

			Then enable the extension in the admin panel and choose it as the default language in *Basics*. Composer will pick the version that matches your Flarum.

			### Updating

			```
			composer update $package
			php flarum cache:clear
			```

			### Contributing

			Translations are done on [Weblate](https://weblate.rob006.net/) - no coding or Git knowledge needed. Flarum 1.x and 2.x have separate Weblate projects, so pick the one for the version you want to improve:

			- **Flarum 2.x**: [translate](https://weblate.rob006.net/languages/$language/flarum2/) · [status per extension](https://rob006-software.github.io/flarum-translations/flarum2/status/$language.html)
			   ![status](https://weblate.rob006.net/widgets/flarum2/$language/svg-badge.svg)
			- **Flarum 1.x**: [translate](https://weblate.rob006.net/languages/$language/flarum/) · [status per extension](https://rob006-software.github.io/flarum-translations/master/status/$language.html)
			   ![status](https://weblate.rob006.net/widgets/flarum/$language/svg-badge.svg)

			Found a bug or a missing extension? Open an [issue on GitHub](https://github.com/$package/issues) or reply here.

			### Links

			- [GitHub](https://github.com/$package)
			- [Packagist](https://packagist.org/packages/$package)
			- [Flarum translations project](https://github.com/rob006-software/flarum-translations) ([FAQ](https://github.com/rob006-software/flarum-translations/wiki))

			New releases are announced in this discussion - follow it to get notified.
			MD;
	}
}
