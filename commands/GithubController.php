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
use app\components\GhCli;
use app\models\Subsplit;
use Throwable;
use Yii;
use yii\helpers\Console;
use function array_column;
use function array_map;
use function array_merge;
use function in_array;
use function strtolower;

/**
 * Class GithubController.
 *
 * Administrative tasks for language packs repositories on GitHub. These commands use `gh` CLI tool and permissions of
 * user logged in there - bot does not have permissions for them. They are intended to be run manually.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
final class GithubController extends ConsoleController {

	/** Permission for maintainers of language packs - enough to approve PRs, manage labels and close issues. */
	private const MAINTAINER_PERMISSION = 'triage';
	/** Organization members cannot have lower permission than admin for direct access. */
	private const ORGANIZATION_MEMBER_PERMISSION = 'admin';
	/** Accounts which have direct access to all language packs, but are not maintainers. */
	private const IGNORED_COLLABORATORS = ['robbot006'];

	/** @var bool */
	public $dryRun = true;

	private $gh;
	private $organizationsMembers = [];

	public function options($actionID) {
		return array_merge(parent::options($actionID), [
			'dryRun',
			'update',
			'verbose',
		]);
	}

	/**
	 * Synchronizes direct access to language packs repositories with `maintainers` from subsplits config. Maintainers
	 * are invited with `triage` permission (`admin` for members of the organization), direct collaborators and pending
	 * invitations for users which are not maintainers are removed.
	 *
	 * Runs in dry-run mode by default - use `--dryRun=0` to apply changes.
	 */
	public function actionSyncMaintainers(array $subsplits = [], string $configFile = '@app/translations/config.php') {
		$translations = $this->getTranslations($configFile);
		// release lock early, since we're only using config, and this action can take a while
		Yii::$app->locks->releaseRepoLock($translations->getRepository()->getPath());

		if (empty($subsplits)) {
			$subsplits = $translations->getSubsplits();
		} else {
			foreach ($subsplits as $key => $subsplitId) {
				$subsplits[$key] = $translations->getSubsplit($subsplitId);
			}
		}

		$this->gh = new GhCli();
		echo 'Using GitHub account: ', $this->gh->getCurrentUser(), $this->dryRun ? ' (dry run)' : '', "\n";

		foreach ($subsplits as $subsplit) {
			try {
				$this->syncSubsplitMaintainers($subsplit);
			} catch (Throwable $exception) {
				Yii::error($exception);
				echo Console::renderColoredString("%r{$subsplit->getId()}: {$exception->getMessage()}%n"), "\n";
			}
		}
	}

	private function syncSubsplitMaintainers(Subsplit $subsplit): void {
		[$owner, $repoName] = Yii::$app->githubApi->explodeRepoUrl($subsplit->getRepositoryUrl());
		$repo = "$owner/$repoName";

		// GitHub logins are case-insensitive
		$expected = [];
		foreach ($subsplit->getMaintainers() as $login) {
			$key = strtolower($login);
			$expected[$key] = [
				'login' => $login,
				'permission' => in_array($key, $this->getOrganizationMembers($owner), true)
					? self::ORGANIZATION_MEMBER_PERMISSION
					: self::MAINTAINER_PERMISSION,
			];
		}
		$collaborators = [];
		foreach ($this->gh->getAll("repos/$repo/collaborators?affiliation=direct") as $collaborator) {
			if (!in_array(strtolower($collaborator['login']), self::IGNORED_COLLABORATORS, true)) {
				$collaborators[strtolower($collaborator['login'])] = $collaborator;
			}
		}
		$invitations = [];
		foreach ($this->gh->getAll("repos/$repo/invitations") as $invitation) {
			$invitations[strtolower($invitation['invitee']['login'])] = $invitation;
		}

		$changes = [];
		foreach ($expected as $key => $maintainer) {
			if (isset($collaborators[$key])) {
				$current = $collaborators[$key]['role_name'];
				if ($current !== $maintainer['permission']) {
					$changes[] = [
						"~ {$maintainer['login']}: change permission from $current to {$maintainer['permission']}",
						'PUT', "repos/$repo/collaborators/{$maintainer['login']}", ['permission' => $maintainer['permission']],
					];
				}
			} elseif (isset($invitations[$key])) {
				$current = $invitations[$key]['permissions'];
				if ($current !== $maintainer['permission']) {
					$changes[] = [
						"~ {$maintainer['login']}: change permission in pending invitation from $current to {$maintainer['permission']}",
						'PATCH', "repos/$repo/invitations/{$invitations[$key]['id']}", ['permissions' => $maintainer['permission']],
					];
				} elseif ($this->verbose) {
					echo "$repo: {$maintainer['login']} has pending invitation.\n";
				}
			} else {
				$changes[] = [
					"+ {$maintainer['login']}: add with {$maintainer['permission']} permission",
					'PUT', "repos/$repo/collaborators/{$maintainer['login']}", ['permission' => $maintainer['permission']],
				];
			}
		}
		foreach ($collaborators as $key => $collaborator) {
			if (!isset($expected[$key])) {
				$changes[] = [
					"- {$collaborator['login']}: remove ({$collaborator['role_name']} permission)",
					'DELETE', "repos/$repo/collaborators/{$collaborator['login']}", null,
				];
			}
		}
		foreach ($invitations as $key => $invitation) {
			if (!isset($expected[$key])) {
				$changes[] = [
					"- {$invitation['invitee']['login']}: cancel pending invitation ({$invitation['permissions']} permission)",
					'DELETE', "repos/$repo/invitations/{$invitation['id']}", null,
				];
			}
		}

		if (empty($changes)) {
			if ($this->verbose) {
				echo "$repo: no changes.\n";
			}
			return;
		}

		echo "$repo:\n";
		foreach ($changes as [$label, $method, $endpoint, $data]) {
			echo "  $label\n";
			if (!$this->dryRun) {
				try {
					$this->gh->api($method, $endpoint, $data);
				} catch (Throwable $exception) {
					Yii::error($exception);
					echo Console::renderColoredString("  %r{$exception->getMessage()}%n"), "\n";
				}
			}
		}
	}

	/**
	 * @return string[] Lowercased logins of organization members.
	 */
	private function getOrganizationMembers(string $organization): array {
		if (!isset($this->organizationsMembers[$organization])) {
			$logins = array_column($this->gh->getAll("orgs/$organization/members"), 'login');
			$this->organizationsMembers[$organization] = array_map('strtolower', $logins);
		}

		return $this->organizationsMembers[$organization];
	}
}
