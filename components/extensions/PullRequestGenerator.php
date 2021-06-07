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

namespace app\components\extensions;

use app\components\GithubApi;
use app\models\Extension;
use app\models\ForkRepository;
use app\models\RegularExtension;
use Dont\DontCall;
use Dont\DontCallStatic;
use Dont\DontGet;
use Dont\DontSet;
use Yii;
use yii\base\InvalidArgumentException;

/**
 * Class PullRequestGenerator.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class PullRequestGenerator {

	use DontCall;
	use DontCallStatic;
	use DontGet;
	use DontSet;

	private $repository;
	private $githubApi;

	public function __construct(ForkRepository $repository, ?GithubApi $githubApi = null) {
		$repository->rebase();
		$repository->syncBranchesWithRemote();

		$this->repository = $repository;
		$this->githubApi = $githubApi ?? Yii::$app->githubApi;
	}

	/**
	 * @param Extension[] $extensions
	 * @param int $limit
	 */
	public function generateForNewExtensions(array $extensions, int $limit): void {
		foreach ($extensions as $extension) {
			if ($extension instanceof RegularExtension && $extension->getStableTranslationSourceUrl() === null) {
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
			$this->repository->commit("Add `{$extension->getPackageName()}`.");
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
		$this->repository->commit("Update config for `{$extension->getPackageName()}`.", $commited);
		$this->repository->push();

		return $commited;
	}

	private function addExtensionToConfig(Extension $extension): void {
		$filePath = $this->repository->getPath() . '/config/components.php';
		$generator = new ConfigGenerator($filePath);
		$generator->updateExtension($extension);
	}

	private function openPullRequestForNewExtension(string $branchName, Extension $extension): void {
		$this->githubApi->openPullRequest(
			Yii::$app->params['translationsRepository'],
			Yii::$app->params['translationsForkRepository'],
			$branchName,
			[
				'title' => "Add `{$extension->getPackageName()}`",
				'body' => $this->generatePullRequestBody($extension),
			]
		);
	}

	private function updatePullRequestForNewExtension(string $branchName): void {
		$pullRequest = $this->githubApi->getPullRequestForBranch(
			Yii::$app->params['translationsRepository'],
			Yii::$app->params['translationsForkRepository'],
			$branchName
		);
		if ($pullRequest === null) {
			throw new InvalidArgumentException("There is no PR for branch $branchName.");
		}
		if ($pullRequest['state'] === 'open') {
			// Do not add comment if PR is open - new commit will trigger notification. We need comment only if PR is
			// closed, since new commits are ignored in this case (they will not show in PR and will not trigger any
			// notification).
			return;
		}

		$this->githubApi->addPullRequestComment(
			Yii::$app->params['translationsRepository'],
			$pullRequest['number'],
			[
				'body' => 'Pull request updated.',
			]
		);
	}

	private function generatePullRequestBody(Extension $extension): string {
		if ($extension instanceof RegularExtension) {
			return $this->generatePullRequestBadges($extension);
		}

		return $extension->getRepositoryUrl() . "\n";
	}

	private function generatePullRequestBadges(RegularExtension $extension): string {
		$name = $extension->getPackageName();

		$output = <<<MD
			## [`$name` at Packagist](https://packagist.org/packages/$name)
			
			![License](https://img.shields.io/packagist/l/$name)
			
			![Latest Stable Version](https://img.shields.io/packagist/v/$name?color=success&label=stable) ![Latest Unstable Version](https://img.shields.io/packagist/v/$name?include_prereleases&label=unstable) ![Flarum compatibility status](https://flarum-badge-api.davwheat.dev/v1/compat-latest/$name)
			[![Total Downloads](https://img.shields.io/packagist/dt/$name) ![Monthly Downloads](https://img.shields.io/packagist/dm/$name) ![Daily Downloads](https://img.shields.io/packagist/dd/$name)](https://packagist.org/packages/$name/stats)
			
			
			MD;

		if (Yii::$app->extensionsRepository->isGithubRepo($extension->getRepositoryUrl())) {
			[$userName, $repoName] = Yii::$app->githubApi->explodeRepoUrl($extension->getRepositoryUrl());
			$output .= <<<MD
				## [`$userName/$repoName` at GitHub](https://github.com/$userName/$repoName)
				
				![GitHub license](https://img.shields.io/github/license/$userName/$repoName)
				
				[![GitHub last tag](https://img.shields.io/github/tag-date/$userName/$repoName)](https://github.com/$userName/$repoName/releases) [![GitHub contributors](https://img.shields.io/github/contributors/$userName/$repoName)](https://github.com/$userName/$repoName/graphs/contributors)
				[![GitHub stars](https://img.shields.io/github/stars/$userName/$repoName)](https://github.com/$userName/$repoName/stargazers) [![GitHub forks](https://img.shields.io/github/forks/$userName/$repoName)](https://github.com/$userName/$repoName/network) [![GitHub issues](https://img.shields.io/github/issues/$userName/$repoName)](https://github.com/$userName/$repoName/issues)
				
				[![GitHub last commit](https://img.shields.io/github/last-commit/$userName/$repoName)](https://github.com/$userName/$repoName/commits) [![GitHub commit activity](https://img.shields.io/github/commit-activity/m/$userName/$repoName)](https://github.com/$userName/$repoName/graphs/contributors) [![GitHub commit activity](https://img.shields.io/github/commit-activity/y/$userName/$repoName)](https://github.com/$userName/$repoName/graphs/contributors)
				
				
				MD;
		}

		$output .= "## Other\n\n";
		$output .= "https://extiverse.com/extension/{$extension->getPackageName()}\n";
		$discussUrl = $extension->getThreadUrl();
		if ($discussUrl !== null) {
			$output .= "$discussUrl\n";
		}

		return $output;
	}
}
