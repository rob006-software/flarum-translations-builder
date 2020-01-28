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
			if ($extension->getStableTranslationSourceUrl() === null) {
				continue;
			}
			$branchName = "new/{$extension->getId()}";
			if ($this->repository->hasBranch($branchName)) {
				if ($this->updateBranch($branchName, $extension)) {
					$this->updatePullRequestForNewExtension($branchName);
				}
				continue;
			}

			$this->repository->checkoutBranch('master');
			$this->repository->createBranch($branchName);

			$this->addExtensionToConfig($extension);
			$this->repository->commit("Add {$extension->getPackageName()}.");
			$this->repository->push();

			$this->openPullRequestForNewExtension($branchName, $extension);

			if (--$limit <= 0) {
				return;
			}
		}
	}

	private function updateBranch(string $branchName, Extension $extension): bool {
		$this->repository->checkoutBranch($branchName);
		$this->addExtensionToConfig($extension);
		$this->repository->commit("Update config for {$extension->getPackageName()}.", $commited);
		$this->repository->push();

		return $commited;
	}

	private function addExtensionToConfig(Extension $extension): void {
		$project = $this->translations->getProject($extension->getProjectId());
		$filePath = $this->repository->getPath() . "/config/{$project->getId()}-project.php";
		$generator = new ConfigGenerator($filePath, $project->getExtensionsComponents());
		$generator->updateExtension($extension);
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

	private function updatePullRequestForNewExtension(string $branchName): void {
		$this->githubApi->addPullRequestComment(
			Yii::$app->params['translationsRepository'],
			Yii::$app->params['translationsForkRepository'],
			$branchName,
			[
				'body' => 'Pull request updated.',
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
