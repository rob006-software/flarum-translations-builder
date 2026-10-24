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

namespace app\commands;

use app\components\ConsoleController;
use app\components\forum\LanguagePackDiscussionGenerator;
use app\models\LanguageSubsplit;
use app\models\MultiLanguageSubsplit;
use app\models\Subsplit;
use app\models\Translations;
use InvalidArgumentException;
use mindplay\readable;
use RuntimeException;
use Throwable;
use Yii;
use yii\helpers\Console;
use function array_merge;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function str_replace;
use function var_export;

/**
 * Class ForumController.
 *
 * Manages language packs discussions on forum (discuss.flarum.org). These commands are intended to be run manually.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class ForumController extends ConsoleController {

	/** @var bool */
	public $dryRun = true;
	/** @var string[] Slugs of tags assigned to created discussions. */
	public $tags = ['extensions', 'languages'];

	private $tagIds;

	public function options($actionID) {
		return array_merge(parent::options($actionID), [
			'dryRun',
			'tags',
			'update',
			'verbose',
		]);
	}

	/**
	 * Creates discussion for language packs without `discussThreadId` in subsplits config (and saves its ID in config),
	 * or updates first post of already configured discussion. Content of the post is generated from template - any
	 * manual changes in first post will be overwritten.
	 *
	 * Runs in dry-run mode by default - use `--dryRun=0` to apply changes. Use `--verbose=1` to print generated post -
	 * with dry run it is a way to get the post for manual edition.
	 */
	public function actionSyncDiscussion(array $subsplits, string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		// release lock early, since we're only using config
		Yii::$app->locks->releaseRepoLock($translations->getRepository()->getPath());

		echo 'Using forum: ', Yii::$app->flarumApi->getForumUrl(), $this->dryRun ? ' (dry run)' : '', "\n";

		foreach ($subsplits as $subsplitId) {
			try {
				$this->syncDiscussion($translations, $translations->getSubsplit($subsplitId));
			} catch (Throwable $exception) {
				Yii::error($exception);
				echo Console::renderColoredString("%r$subsplitId: {$exception->getMessage()}%n"), "\n";
			}
		}
	}

	private function syncDiscussion(Translations $translations, Subsplit $subsplit): void {
		if (!$subsplit instanceof LanguageSubsplit && !$subsplit instanceof MultiLanguageSubsplit) {
			throw new InvalidArgumentException('Unsupported subsplit type: ' . readable::typeof($subsplit) . '.');
		}

		$language = $subsplit->getLanguage();
		// package names of all language packs are the same as their repository names on GitHub
		[$owner, $repoName] = Yii::$app->githubApi->explodeRepoUrl($subsplit->getRepositoryUrl());
		$generator = new LanguagePackDiscussionGenerator(
			$translations->getLanguageName($language),
			$language,
			"$owner/$repoName"
		);

		// print it before any request, so it is available even if forum account cannot edit the post
		if ($this->verbose) {
			echo "{$subsplit->getId()}: generated first post:\n\n{$generator->generateContent()}\n\n";
		}

		if ($subsplit->getDiscussThreadId() === null) {
			$this->createDiscussion($subsplit, $generator);
		} else {
			$this->updateFirstPost($subsplit, $generator);
		}
	}

	private function createDiscussion(Subsplit $subsplit, LanguagePackDiscussionGenerator $generator): void {
		$title = $generator->generateTitle();
		$content = $generator->generateContent();
		echo "{$subsplit->getId()}: create discussion ", readable::value($title), "\n";
		if ($this->dryRun) {
			return;
		}

		$discussion = Yii::$app->flarumApi->createDiscussion($title, $content, array_values($this->getTagIds()));
		$discussionId = (int) $discussion['id'];
		$url = Yii::$app->flarumApi->getDiscussionUrl($discussionId);
		try {
			$this->saveDiscussThreadId($subsplit->getId(), $discussionId);
		} catch (Throwable $exception) {
			throw new RuntimeException("Discussion $url was created, but its ID could not be saved in config - add it manually: {$exception->getMessage()}", 0, $exception);
		}
		echo Console::renderColoredString("%g{$subsplit->getId()}: created $url%n"), "\n";
	}

	private function updateFirstPost(Subsplit $subsplit, LanguagePackDiscussionGenerator $generator): void {
		$discussionId = $subsplit->getDiscussThreadId();
		$url = Yii::$app->flarumApi->getDiscussionUrl($discussionId);
		$response = Yii::$app->flarumApi->getDiscussionWithFirstPost($discussionId);
		$firstPostId = $response['data']['relationships']['firstPost']['data']['id'] ?? null;
		$firstPost = null;
		foreach ($response['included'] ?? [] as $included) {
			if ($included['type'] === 'posts' && $included['id'] === $firstPostId) {
				$firstPost = $included;
			}
		}
		if ($firstPost === null) {
			throw new RuntimeException("Cannot find first post of $url.");
		}
		// raw content is returned only if user is allowed to edit the post
		if (!isset($firstPost['attributes']['content'])) {
			throw new RuntimeException("Content of the first post of $url is not available - forum account is probably not allowed to edit it.");
		}

		$content = $generator->generateContent();
		if ($this->normalizeContent($firstPost['attributes']['content']) === $this->normalizeContent($content)) {
			echo "{$subsplit->getId()}: first post of $url is up to date\n";
			return;
		}

		echo "{$subsplit->getId()}: update first post of $url\n";
		if ($this->dryRun) {
			return;
		}

		Yii::$app->flarumApi->updatePost((int) $firstPostId, $content);
		echo Console::renderColoredString("%g{$subsplit->getId()}: updated $url%n"), "\n";
	}

	private function normalizeContent(string $content): string {
		return rtrim(str_replace("\r\n", "\n", $content));
	}

	/**
	 * @return int[]
	 */
	private function getTagIds(): array {
		if ($this->tagIds === null) {
			$this->tagIds = Yii::$app->flarumApi->getTagIds($this->tags);
		}

		return $this->tagIds;
	}

	/**
	 * Adds `discussThreadId` to subsplit entry in subsplits config, right after `weblateMaintainers`.
	 */
	private function saveDiscussThreadId(string $subsplitId, int $discussionId): void {
		$path = APP_ROOT . '/config/translations/subsplits.php';
		$config = file_get_contents($path);
		$id = preg_quote(var_export($subsplitId, true), '/');
		$newConfig = preg_replace(
			"/^(\\t$id => \\[\\n(?:\\t\\t.*\\n)*?\\t\\t'weblateMaintainers' => .*\\n)/m",
			"\$1\t\t'discussThreadId' => $discussionId,\n",
			$config,
			1,
			$count
		);
		if ($count !== 1) {
			throw new RuntimeException('Cannot find ' . readable::value($subsplitId) . " subsplit with `weblateMaintainers` in $path.");
		}

		file_put_contents($path, $newConfig);
	}
}
