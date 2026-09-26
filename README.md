# flarum-translations-builder

Builder for [rob006-software/flarum-translations](https://github.com/rob006-software/flarum-translations) - collects
translation sources from Flarum extensions, keeps Weblate and the translations monorepo in sync, splits translations into
`flarum-lang/*` language packs and releases them.

There are two production instances, one per Flarum line, with the same code but different `translations` branches:

| Flarum | `translations` branch | Language packs branches          |
|--------|-----------------------|----------------------------------|
| 1.x    | `master`              | `master` / `main`                |
| 2.x    | `flarum2`             | `2.x` (a few use `3.x`-`5.x`)    |

Both instances work on the **same** `flarum-lang/*` repositories. The line is selected by `params['flarumVersion']`
in `config/local.php`, and everything version-dependent goes through `app\helpers\FlarumVersion`.

> [!WARNING]
> Almost every command writes to public repositories (translations repo, language packs, pull requests, releases,
> forum posts). Without `--push`, most commands only change local clones, but **`translations/split` and all
> `release/*` commands push regardless of `--push`**. Use `--dryRun=1` where it exists, and test ad-hoc code through
> `test/*` actions (see [Testing](#testing)).


## Cheat sheet

```bash
./yii                                   # list all commands
./yii help translations/split           # arguments and options of a command

./cron                                  # regular sync - run every 10 minutes on prod

./yii translations/split pl --push      # split translations only for Polish language pack
./yii readme/update-subsplits pl --push # update README of Polish language pack
./yii release/pr pl                     # create/update release PR for Polish language pack
./yii release/merge pl                  # merge approved release PR (normally done by webhook)
./yii release/check-pull-requests --dryRun=1 --verbose=1

./yii janitor/components                # list extensions that should be removed from translations
./yii janitor/remove-extension vendor-ext
./yii janitor/migrate-extension old-ext-id new-ext-id
./yii janitor/orphans                   # find (and remove) files for unknown components/languages
./yii janitor/reset-rate-limit-for-extension vendor/package

./yii queue/info                        # queue status
```


## Common conventions

**Arguments.** Array arguments are comma-separated: `./yii translations/split pl,de,fr`. An empty list means "all".
Every command accepts an optional last argument with path to translations config
(default: `@app/translations/config.php`), which is practically never used.

**Options** (defined in `app\components\ConsoleController`, but each command whitelists which of them are accepted -
check `./yii help <command>`):

| Option        | Default | Meaning                                                                                   |
|---------------|---------|-------------------------------------------------------------------------------------------|
| `--update`    | `1`     | Pull `translations/` repo (or the builder itself for `self-update`) before running.       |
| `--commit`    | `0`     | Commit changes (`config/update` commits by default).                                      |
| `--push`      | `0`     | Commit **and** push changes.                                                              |
| `--frequency` | -       | Seconds. Skip the run if the same inputs were already processed within this time.         |
| `--verbose`   | `0`     | Print git output / progress. Cron-oriented commands are silent without it.                |

**Frequency limit.** `--frequency=N` skips the command when it was already run within `N` seconds **for the same
input** - the limit key contains a hash of the relevant state (config, sources, translations, subsplit translations,
week number, etc.), so any real change in inputs makes the command run immediately anyway. Without `--frequency`
the command always runs. Keys are stored in the file cache (`runtime/cache`) for 31 days.

**Locks.** Each action takes a mutex for itself (waits up to 15 minutes, then fails with
`Cannot acquire lock for ...`), and repositories are locked while in use, so overlapping cron runs are safe.


## Schedule

Both instances run the same schedule:

| Commands                                                                                                                    | When                                                                     |
|-----------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------|
| `queue/single-listen`                                                                                                       | every minute                                                             |
| `config/update`, `translations/update`, `translations/split`, `readme/update`, `readme/update-subsplits`, `extensions/detect-new 10` | every 10 minutes (via `./cron`), with 6 hours rate limit      |
| `translations/inherit`                                                                                                      | every 10 minutes (via `./cron`), with 24 hours rate limit                |
| `translations/update-inheritors-status`, `stats`, `weblate/update-priorities`, `weblate/update-units-flags`, `release/check-pull-requests` | daily, in the morning                          |
| `translations/update-outdated-translations-metadata`, `translations/update-outdated-subsplits-metadata`                    | weekly, on Sunday morning                                                |
| `janitor/logs`                                                                                                              | daily, at night                                                          |

Every batch (`./cron`, daily, weekly) starts with `self-update`. Rate limit means `--frequency`, so a command runs
earlier when its input changes. `./cron` runs its steps sequentially - see the script for the order.

**Not scheduled - run manually:**

* `translations/cleanup-outdated` - once a year (removes outdated translations and bumps language packs minor version).
* `release/pr`, `release/merge` - when automation needs a nudge.
* `janitor/*` - housekeeping, from time to time.


## Commands

### `self-update`

```bash
./yii self-update [--update=1]
```

Pulls the builder itself (`master` branch), runs `composer install`, and pulls `translations/` repo (skipped with
`--update=0`). Run before every cron batch so all commands use fresh code.

### `config/*` - extensions config

Config of components lives in the translations repo, in `config/components.php`.

```bash
./yii config/update [--push] [--frequency=N]
./yii config/update-built-in-languages <extensionIds>
```

* `config/update` - refreshes config of every extension component (URLs of stable/beta translation sources). Commits
  **by default**, one commit per extension (`[1.x] Update config for vendor/package`).
* `config/update-built-in-languages ext-a,ext-b` - as above, but also detects languages that are bundled in the
  extension repository and saves them in `__builtInLanguages` (these languages are then skipped for that component).
  Only changes the file - review and commit manually.

### `translations/*` - translations monorepo

```bash
./yii translations/update [--push] [--frequency=N]
./yii translations/inherit [inheritorIds] [--push] [--frequency=N]
./yii translations/split [subsplitIds] [--push] [--frequency=N]
./yii translations/update-inheritors-status [--push]
./yii translations/import <sourceFile> <componentId> <language> [--push]

./yii translations/update-outdated-translations-metadata [--push]
./yii translations/update-outdated-subsplits-metadata [--push]
./yii translations/cleanup-outdated-translations [range] [--push]
./yii translations/cleanup-outdated-subsplits [range] [--push]
./yii translations/cleanup-outdated [range] [--push]
```

* **`update`** - downloads English sources from extensions into `sources/*.json` and updates translation files of
  every language (adds new keys as empty strings). Weblate picks it up from the repo.
* **`inherit`** - copies translations between related languages (e.g. `de` → `de@formal`, `sr_Cyrl` → `sr_Latn`), one
  commit per inheritor. Inheritors are configured in `translations/config/inheritors.php`; bookkeeping is in
  `metadata/inheritors/<id>.json`. Manual overrides in the inheriting language are never replaced.
* **`update-inheritors-status`** - regenerates `status/inheritors/` (differences between inheriting languages).
* **`split`** - writes YAML translations into each language pack repository (`runtime/subsplits/*`) and commits
  `Sync translations with main repository` with `Co-authored-by` trailers for translators. Then, if releases are
  enabled for the language pack, creates or updates the release PR (see [`release/*`](#release---language-packs-releases)).
  Per-language-pack frequency limit, so only changed packs are processed. Errors in one pack do not stop others.
  ⚠️ The release PR step always pushes, even without `--push`.
* **`import`** - imports a YAML file (Flarum format, e.g. from an existing language pack) into
  `translations/<language>/<componentId>.json`. Non-empty strings from the file override existing translations.
  `sourceFile` may be a Yii alias.

**Outdated translations lifecycle** - translations for keys removed from English source (and language pack files for
components that no longer exist) are not removed immediately:

1. `update-outdated-translations-metadata` (weekly) - records the date when each translation became outdated in
   `metadata/outdated-translations/<language>.json`.
2. `update-outdated-subsplits-metadata` (weekly) - records outdated YAML files in language packs (files that do not
   match any component valid for the pack) in `metadata/outdated-subsplits/<subsplitId>.json`.
3. `cleanup-outdated [range]` (yearly, manual) - removes translations and language pack files that are outdated long
   enough, then bumps release minor version (in `metadata/versions.json`) for packs that already released the current
   minor, so the removals are released as a new minor version. `cleanup-outdated-translations` and
   `cleanup-outdated-subsplits` do only one half of it, without version bump.

`range` defaults to `-1 year` and it works on whole years: removed are entries outdated in year
`year(strtotime(range)) - 1` or earlier - e.g. run in 2026 with `-1 year` removes everything that became outdated in
2024 or before.

### `readme/*` - READMEs and status pages

```bash
./yii readme/update [--push] [--frequency=N]
./yii readme/update-subsplits [subsplitIds] [--push] [--frequency=N]
```

* `readme/update` - updates translations repo: extensions and language lists in `README.md` (between
  `<!-- *-list-start -->` / `<!-- *-list-stop -->` markers), `status/summary.md`, `status/licenses.md` and
  `status/language-packs.md`.
* `readme/update-subsplits` - updates translations status table in `README.md` of each language pack. Table headers
  are localized with `resources/locale/subsplits/<subsplitId>.json`.

### `extensions/*` - new extensions

```bash
./yii extensions/detect-new [limit=2] [--push] [--frequency=N] [--useCache=1]
./yii extensions/pending [--push] [--frequency=N]
./yii extensions/list [--useCache=1]
./yii extensions/update-cache [--removeOutdated=1] [--push]
```

* **`detect-new`** - finds extensions (Packagist, plus premium ones from `cache/extiverse.json`) with translation sources that are not in the translations
  repo yet, and opens pull requests adding them - at most `limit` new PRs per run (cron uses 10). PRs are created from
  the fork `robbot006/flarum-translations` (local clone: `runtime/translations-fork`), branches are prefixed per Flarum
  line (`FlarumVersion::newPrPrefix()`). Also regenerates `status/pending.md`.
* **`pending`** - only regenerates `status/pending.md` (list of extensions with open PRs).
* **`list`** - prints Markdown list of supported extensions.
* **`update-cache`** - saves Extiverse API response for premium extensions into `cache/extiverse.json` in translations
  repo (the API is not public). Entries are merged with the old cache unless `--removeOutdated=1`. Disabled in `./cron`.

`--useCache=1` uses cached extensions list instead of fetching it again (faster for repeated manual runs).

Extensions that repeatedly fail to process are ignored for 1-6 months; the same happens for detected language packs
and outdated extensions. Use `janitor/reset-rate-limit-for-extension` to clear it.

### `stats`

```bash
./yii stats [languageCodes] [--push] [--frequency=N]
```

Default action `stats/update`. Regenerates `status/<language>.md` (translation status of every extension) for all or
selected languages. Commit: `Update translations status as per <date>`.

### `weblate/*`

```bash
./yii weblate/update-priorities
./yii weblate/update-units-flags [--frequency=N]
```

Both only talk to Weblate API - nothing is committed.

* `update-priorities` - sets priority of Weblate components: `flarum/*` very high, others by monthly downloads
  (≥200 high, ≥100 medium, ≥20 low, otherwise very low; premium extensions use subscribers count × 2).
* `update-units-flags` - adds `ignore-same` flag to units whose source is a reference (`=> core.ref.something`), so
  Weblate does not warn about translations identical to source.

### `release/*` - language packs releases

```bash
./yii release/pr <subsplitId> [--previousVersion=x.y.z] [--nextVersion=x.y.z]
./yii release/merge <subsplitId>
./yii release/check-pull-requests [subsplitIds] [--dryRun=1] [--verbose=1]
```

How releases work:

1. `translations/split` creates/updates branch `release/<pack branch>` with a changelog draft and opens a **draft PR**
   titled ``Release `x.y.z` `` (release notes live between `<!-- release-notes-begin -->` markers in PR body - keep
   them intact). Version is taken from the PR title on merge, so it can be edited in the title.
2. Maintainer approves the PR → GitHub webhook (`POST /github/language-subsplit`) → `MergeReleasePullRequestJob` in
   queue → PR is merged, tagged, GitHub release is created and release is announced (on the forum if
   `discussThreadId` is configured for the pack, otherwise as a PR comment with announcement to copy).
3. **Auto-merge fallback** (only for packs in `ReleasePullRequestGenerator::AUTO_MERGE_SUBSPLITS`, currently `pl`):
   6 days after opening the PR the bot adds `ci-merge-queued` label with a comment, and merges the PR once the label is
   present for 24 hours. Removing the label postpones the merge (it will be added again by the next daily check).

Versions: release `major.minor` for each pack is stored in `metadata/versions.json` in translations repo (missing entry =
`1.0` / `2.0` depending on Flarum line, `null` = releases disabled). Previous version is the newest tag reachable from
the pack branch, next one is `major.minor.0` for a new minor or previous patch + 1.

* **`pr`** - creates/updates the release PR manually (the same as `translations/split` does). `--previousVersion` /
  `--nextVersion` override detected versions. Pushes and opens PRs.
* **`merge`** - merges the release PR of the pack if it is approved by a maintainer - the same as webhook does.
  Useful when the webhook failed.
* **`check-pull-requests`** (daily) - fallback for lost queue jobs, for packs with auto-merge enabled: deletes release
  branches without PR (or with PR closed more than 1 hour ago), and queues auto-merge for open PRs older than 7 days.
  Silent unless something fails; `--verbose=1` shows what happens, `--dryRun=1` shows what would be done.
  ⚠️ Even when testing something unrelated, use `--dryRun=1` - without it, jobs are pushed to the real queue.

Release notes (`CHANGELOG.md`, GitHub release) are always in English; the forum announcement is localized with
`resources/locale/subsplits/<subsplitId>.json` (ICU MessageFormat, translated on Weblate, falls back to `en.json`).

### `janitor/*` - housekeeping

```bash
./yii janitor/components [--useCache=1]
./yii janitor/branches [--useCache=1]
./yii janitor/remove-extension <extensionId>
./yii janitor/migrate-extension <oldExtensionId> <newExtensionId>
./yii janitor/orphans
./yii janitor/reset-rate-limit-for-extension <vendor/package>
./yii janitor/logs
```

* **`components`** - lists components whose extension no longer has translation sources or is not valid anymore -
  candidates for removal. Markers: `X` abandoned, `!` outdated, `?` unknown.
* **`branches`** - lists branches in the fork (`runtime/translations-fork`) with new-extension PRs that are no longer
  needed. Only prints - delete them manually.
* **`remove-extension`** - removes extension from `config/components.php` and deletes its source and translation files
  in `translations/`. Does not commit - review and commit manually.
* **`migrate-extension`** - moves config, source and translations from old extension ID to new one (e.g. after the
  extension was renamed/forked). Does not commit.
* **`orphans`** - finds source/translation files that do not match any component or language, and asks to delete
  them.
* **`reset-rate-limit-for-extension`** - clears "ignore this extension" cache (failures, detected language pack,
  detected outdated extension) for given package name.
* **`logs`** (daily) - removes files older than 1 month from `runtime/git-logs`.

### `queue/*` - background jobs

```bash
./yii queue/single-listen   # run worker, unless another one is already running (used by cron)
./yii queue/listen          # run worker in foreground
./yii queue/run             # process all waiting jobs and exit
./yii queue/info            # queue statistics
./yii queue/exec ...        # internal - executes single job
./yii queue/remove <id>
./yii queue/clear
```

File queue in `runtime/queue` (TTR 15 minutes, 10 attempts), log in `runtime/logs/queue.log`. Jobs (`jobs/`):

| Job                                  | Queued by                                         | Does                                                          |
|--------------------------------------|---------------------------------------------------|---------------------------------------------------------------|
| `MergeReleasePullRequestJob`         | webhook after maintainer approval                 | merges and releases the pack                                  |
| `QueueMergeReleasePullRequestJob`    | release PR creation (6 days delay), daily check   | adds `ci-merge-queued` label + comment, queues auto-merge     |
| `AutoMergeReleasePullRequestJob`     | `QueueMergeReleasePullRequestJob` (~25h delay)    | merges the PR if the label is present for 24h                 |
| `AnnounceReleaseOnForumJob`          | merge, if `discussThreadId` is configured         | posts announcement on Flarum forum and links it in the PR     |

To drop a job queued by mistake (before a worker picks it up), use `queue/remove <id>` - the ID is the number in
`runtime/queue/job<id>.data`.


## Web endpoint

`public/index.php` serves only the GitHub webhook `POST /github/language-subsplit`
(`controllers/GithubController`): handles `pull_request_review` events for release PRs and queues
`MergeReleasePullRequestJob` when a maintainer (owner/member/collaborator) approves.


## Testing

* Run everything with `php7.3` locally (`php7.3 yii ...`) - this is the production PHP version.
* `test/*` (`commands/TestController`) is a scratch controller for ad-hoc code: add a temporary action, run it,
  restore the file. It defaults to `--update=0`.
* Safe order: `php7.3 -l` → read-only runs → `--dryRun=1` → real run.
* Local config (API tokens, Flarum line) is in `config/local.php` (template: `config/templates/local.php`), environment
  (`YII_ENV`, `YII_DEBUG`) in `config/environment.php`.


## Where things are

| Path                                 | What                                                                  |
|--------------------------------------|-----------------------------------------------------------------------|
| `translations/`                      | clone of the translations monorepo (branch depends on Flarum line)    |
| `translations/config.php`            | main translations config, includes files from `translations/config/`  |
| `translations/config/`               | `components.php`, `languages.php`, `subsplits.php` (language packs), `inheritors.php`, `ignored-extensions.php` |
| `runtime/subsplits/`                 | clones of language pack repositories                                  |
| `runtime/translations-fork/`         | clone of the fork used for new-extension PRs                          |
| `runtime/logs/`, `runtime/git-logs/` | app logs, git command logs                                            |
| `resources/locale/subsplits/`        | localized phrases used in language packs READMEs and announcements    |
| `commands/`                          | console commands                                                      |
| `components/`, `models/`, `jobs/`    | the rest of the code                                                  |
