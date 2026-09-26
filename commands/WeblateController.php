<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\commands;

use app\components\ConsoleController;
use app\components\weblate\WeblateApi;
use app\models\Extension;
use app\models\PremiumExtension;
use app\models\Subsplit;
use app\models\Translations;
use Symfony\Component\HttpClient\Exception\ClientException;
use Throwable;
use Yii;
use yii\helpers\Console;
use function array_diff_key;
use function array_merge;
use function assert;
use function basename;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Class WeblateController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class WeblateController extends ConsoleController {

	/** Maintainers groups are shared by all Flarum versions, so group name does not depend on Weblate project. */
	private const MAINTAINERS_GROUP_PREFIX = 'Flarum@Language maintainer - ';

	private const STATS_TO_PRIORITY_MAP = [
		200 => WeblateApi::PRIORITY_HIGH,
		100 => WeblateApi::PRIORITY_MEDIUM,
		20 => WeblateApi::PRIORITY_LOW,
		0 => WeblateApi::PRIORITY_VERY_LOW,
	];

	/** @var bool */
	public $dryRun = true;

	public function options($actionID) {
		if ($actionID === 'sync-maintainers') {
			return array_merge(parent::options($actionID), [
				'dryRun',
				'verbose',
				'update',
			]);
		}

		return array_merge(parent::options($actionID), [
			'verbose',
			'update',
			'frequency',
		]);
	}

	public function actionUpdatePriorities(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		foreach ($translations->getExtensionsComponents() as $component) {
			$extension = Yii::$app->extensionsRepository->getExtension($component->getId());
			if ($extension !== null) {
				Yii::$app->weblateApi->updateComponentPriority($extension->getId(), $this->calculatePriority($extension));
			}
		}
	}

	public function actionUpdateUnitsFlags(string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		$token = __METHOD__ . '#' . $translations->getSourcesHash();
		if ($this->isLimited($token)) {
			return;
		}
		$updateLimit = true;
		foreach ($translations->getComponents() as $component) {
			try {
				foreach (Yii::$app->weblateApi->getUnits($component->getId()) as $unit) {
					if (str_starts_with($unit['source'][0], '=> ') && !str_contains($unit['extra_flags'], 'ignore-same')) {
						Yii::$app->weblateApi->updateUnit($unit['id'], [
							'extra_flags' => trim("{$unit['extra_flags']},ignore-same", ','),
						]);
					}
				}
			} catch (ClientException $exception) {
				Yii::warning("HTTP error while trying to refresh units flags for {$component->getId()}.");
				$updateLimit = false;
			}
		}

		if ($updateLimit) {
			$this->updateLimit($token);
		}
	}

	/**
	 * Synchronizes members of `Flarum@Language maintainer - <language>` groups with `weblateMaintainers` from subsplits
	 * config (for multi-language subsplits - groups of all variants). Groups are not created automatically - missing
	 * group is reported as error if subsplit has maintainers. Groups are shared by all Flarum versions.
	 *
	 * Runs in dry-run mode by default - use `--dryRun=0` to apply changes.
	 */
	public function actionSyncMaintainers(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		if (empty($subsplits)) {
			$subsplits = $translations->getSubsplits();
		} else {
			foreach ($subsplits as $key => $subsplitId) {
				$subsplits[$key] = $translations->getSubsplit($subsplitId);
			}
		}

		$api = Yii::$app->weblateApi;
		$groups = [];
		foreach ($api->getGroups() as $group) {
			$groups[$group['name']] = $group;
		}
		// there is no API endpoint for listing members of a group, so we need to check groups of all users
		$members = [];
		foreach ($api->getUsers() as $user) {
			foreach ($user['groups'] as $groupUrl) {
				// compare usernames case-insensitively, just in case config has different letter case
				$members[$groupUrl][strtolower($user['username'])] = $user['username'];
			}
		}

		foreach ($subsplits as $subsplit) {
			assert($subsplit instanceof Subsplit);
			$expected = [];
			foreach ($subsplit->getWeblateMaintainers() as $username) {
				$expected[strtolower($username)] = $username;
			}
			foreach ($subsplit->getLanguages() as $language) {
				$groupName = self::MAINTAINERS_GROUP_PREFIX . $language;
				if (!isset($groups[$groupName])) {
					if (!empty($expected)) {
						echo Console::renderColoredString("%r{$subsplit->getId()}: there is no \"$groupName\" group on Weblate - create it manually.%n"), "\n";
					} elseif ($this->verbose) {
						echo "{$subsplit->getId()}: there is no \"$groupName\" group and no maintainers - skip.\n";
					}
					continue;
				}

				$this->syncGroupMembers($groups[$groupName], $members[$groups[$groupName]['url']] ?? [], $expected);
			}
		}
	}

	/**
	 * @param array $group Group data from API.
	 * @param string[] $current Current members of the group, indexed by lowercased username.
	 * @param string[] $expected Expected members of the group, indexed by lowercased username.
	 */
	private function syncGroupMembers(array $group, array $current, array $expected): void {
		$api = Yii::$app->weblateApi;
		// API does not return group ID directly - it needs to be extracted from URL
		$groupId = (int) basename(rtrim($group['url'], '/'));
		$changes = [];
		foreach (array_diff_key($expected, $current) as $username) {
			$changes["+ $username: add to group"] = static function () use ($api, $username, $groupId) {
				$api->addUserToGroup($username, $groupId);
			};
		}
		foreach (array_diff_key($current, $expected) as $username) {
			$changes["- $username: remove from group"] = static function () use ($api, $username, $groupId) {
				$api->removeUserFromGroup($username, $groupId);
			};
		}

		if (empty($changes)) {
			if ($this->verbose) {
				echo "{$group['name']}: no changes.\n";
			}
			return;
		}

		echo "{$group['name']}:\n";
		foreach ($changes as $label => $callback) {
			echo "  $label\n";
			if (!$this->dryRun) {
				try {
					$callback();
				} catch (Throwable $exception) {
					Yii::error($exception);
					echo Console::renderColoredString("  %r{$exception->getMessage()}%n"), "\n";
				}
			}
		}
	}

	private function calculatePriority(Extension $extension): int {
		if ($extension->getVendor() === 'flarum') {
			return WeblateApi::PRIORITY_VERY_HIGH;
		}

		if ($extension instanceof PremiumExtension) {
			$downloads = Yii::$app->stats->getStats($extension)->getSubscribersCount() * 2;
		} else {
			$downloads = Yii::$app->stats->getStats($extension)->getMonthlyDownloads();
		}

		foreach (self::STATS_TO_PRIORITY_MAP as $count => $priority) {
			if ($downloads >= $count) {
				return $priority;
			}
		}

		return WeblateApi::PRIORITY_VERY_LOW;
	}

	protected function getTranslations(string $configFile): Translations {
		$translations = parent::getTranslations($configFile);
		// release lock early, since we're only using config, and these actions can take a while
		Yii::$app->locks->releaseRepoLock($translations->getRepository()->getPath());

		return $translations;
	}
}
