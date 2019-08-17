<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace app\components\extensions;

use app\components\GithubApi;
use app\models\Extension;
use app\models\ForkRepository;
use app\models\Translations;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use function array_keys;
use function file_get_contents;
use function file_put_contents;
use function strcmp;

/**
 * Class PullRequestGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class PullRequestGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $repository;
	private $translations;
	private $githubApi;

	public function __construct(ForkRepository $repository, Translations $translations, ?GithubApi $githubApi = null) {
		$repository->rebase();
		$repository->syncBranchesWithRemote();

		$this->repository = $repository;
		$this->translations = $translations;
		$this->githubApi = $githubApi ?? Yii::$app->githubApi;
	}

	/**
	 * @param Extension[] $extensions
	 * @param int $limit
	 */
	public function generateForNewExtensions(array $extensions, int $limit): void {
		foreach ($extensions as $extension) {
			$branchName = "new/{$extension->getId()}";
			if ($this->repository->hasBranch($branchName)) {
				continue;
			}

			$this->repository->createBranch($branchName);

			$this->addNewExtensionToConfig($extension);
			$this->repository->commit("Add {$extension->getPackageName()}.");
			$this->repository->push();

			$this->openPullRequestForNewExtension($branchName, $extension);

			$this->repository->rebase();
			if (--$limit <= 0) {
				return;
			}
		}
	}

	private function addNewExtensionToConfig(Extension $extension): void {
		$project = $this->translations->getProject($extension->getProjectId());
		$components = $project->getComponents();
		unset($components['core']);

		$filePath = $this->repository->getPath() . "/config/{$project->getId()}-project.php";
		$configContent = file_get_contents($filePath);
		$position = $this->findPrecedingComponent(array_keys($components), $extension->getId());
		if ($position === null) {
			$configContent = strtr($configContent, [
				"\n\t/* extensions list end */" => "\n\t'{$extension->getId()}' => '{$extension->getTranslationSourceUrl()}',\n\t/* extensions list end */",
			]);
		} else {
			$configContent = strtr($configContent, [
				"\n\t'{$position}' => " => "\n\t'{$extension->getId()}' => '{$extension->getTranslationSourceUrl()}',\n\t'{$position}' => ",
			]);
		}
		file_put_contents($filePath, $configContent);
	}

	private function findPrecedingComponent(array $components, string $subject): ?string {
		foreach ($components as $component) {
			if (strcmp($component, $subject) > 0) {
				return $component;
			}
		}

		return null;
	}

	private function openPullRequestForNewExtension(string $branchName, Extension $extension): void {
		$this->githubApi->openPullRequest(
			Yii::$app->params['translationsRepository'],
			Yii::$app->params['translationsForkRepository'],
			$branchName,
			[
				'title' => "Add {$extension->getPackageName()}",
				'body' => $this->generatePullRequestBadges($extension),
			]
		);
	}

	private function generatePullRequestBadges(Extension $extension): string {
		$name = $extension->getPackageName();

		$output = <<<MD
## [$name at Packagist](https://packagist.org/packages/$name)

![License](https://poser.pugx.org/$name/license)

![Latest Stable Version](https://poser.pugx.org/$name/v/stable) ![Latest Unstable Version](https://poser.pugx.org/$name/v/unstable)
[![Total Downloads](https://poser.pugx.org/$name/downloads) ![Monthly Downloads](https://poser.pugx.org/$name/d/monthly) ![Daily Downloads](https://poser.pugx.org/$name/d/daily)](https://packagist.org/packages/$name/stats)


MD;

		if (Yii::$app->extensionsRepository->isGithubRepo($extension->getRepositoryUrl())) {
			$name = Yii::$app->githubApi->getRepoInfo($extension->getRepositoryUrl())['full_name'];
			$output .= <<<MD
## [$name at GitHub](https://github.com/$name)

![GitHub license](https://img.shields.io/github/license/$name)

[![GitHub last tag](https://img.shields.io/github/tag-date/$name)](https://github.com/$name/releases) [![GitHub contributors](https://img.shields.io/github/contributors/$name)](https://github.com/$name/graphs/contributors)
[![GitHub stars](https://img.shields.io/github/stars/$name)](https://github.com/$name/stargazers) [![GitHub forks](https://img.shields.io/github/forks/$name)](https://github.com/$name/network) [![GitHub issues](https://img.shields.io/github/issues/$name)](https://github.com/$name/issues)

[![GitHub last commit](https://img.shields.io/github/last-commit/$name)](https://github.com/$name/commits) [![GitHub commit activity](https://img.shields.io/github/commit-activity/m/$name)](https://github.com/$name/graphs/contributors) [![GitHub commit activity](https://img.shields.io/github/commit-activity/y/$name)](https://github.com/$name/graphs/contributors)


MD;
		}

		$output .= "## Other\n\n";
		$output .= "https://flagrow.io/extensions/{$extension->getPackageName()}\n";
		$discussUrl = $extension->getThreadUrl();
		if ($discussUrl !== null) {
			$output .= "$discussUrl\n";
		}

		return $output;
	}
}
