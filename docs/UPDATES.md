# Updating Aggregate

Aggregate can update itself in place, or you can deploy it with a tool you
already use. An administrator chooses one of three update methods, and the
dashboard **Updates** page then shows only that method. Everything also runs from
the command line, including with the dashboard disabled.

- [Choose an update method](#choose-an-update-method)
- [Update with release ZIPs (recommended)](#update-with-release-zips-recommended)
- [Update from the repository (advanced)](#update-from-the-repository-advanced)
- [Deploy the code another way](#deploy-the-code-another-way)
- [Set up a Git clone](#set-up-a-git-clone)
- [Switch methods](#switch-methods)
- [Settings](#settings)
- [What an update keeps](#what-an-update-keeps)
- [When an update fails](#when-an-update-fails)
- [Command reference](#command-reference)
- [Troubleshooting](#troubleshooting)

## Choose an update method

| | Release ZIP (recommended) | From the repository (advanced) | Deployed another way |
| --- | --- | --- | --- |
| Who installs updates | Aggregate | Aggregate | Your tool: a hosting panel's Git deployment (such as Plesk or cPanel), CI/CD, rsync |
| What is installed | A signed release published on GitHub | The newest commit of `updates_branch` | Whatever your tool deploys |
| Verification | The release signature is checked with this installation's trusted key before anything changes | None beyond HTTPS to GitHub; a commit is not a signed release | Up to your tool |
| Server needs | PHP only; the ZIP includes dependencies and built assets | Git 2.30 or newer and Composer, run as the user that owns the files | Composer and command-line PHP for the [post-deployment steps](#after-each-deployment) |
| Installation layout | Any directory without a `.git` folder: an extracted release ZIP, or copied files | A full Git clone of the repository in the application directory, on `updates_branch` | Any |
| Dashboard | **Download from GitHub**, or **Upload a release ZIP** for servers that cannot reach GitHub | **Install update** | Read only: the deployed commit, how far behind it is, and whether the post-deployment steps ran |
| Suits | Most installations, including shared hosting | Developers and operators who manage the installation with Git and can fix Git problems on the server | Sites that already deploy from Git with a hosting panel or pipeline |

Choose release ZIPs unless you have a reason to run a Git clone or already deploy
with another tool. The two methods that install updates keep your configuration
and data, put the site in maintenance mode while files change, back up changed
files, and run database migrations, BI glossary sync, cache warmup and a worker
restart signal afterwards. With the third, your tool replaces the files and one
command runs the same steps after it.

**Where to choose.** Only administrators can see the Updates page and choose the
method. Any of these saves the same `updates_method` setting in the active
`config/aggregate.yaml`:

- On the **Updates** page. Until a method is chosen, the page shows only the
  choice, with the method that fits the directory selected. Afterwards, switch
  under **Update settings**.
- On the server: `php bin/console app:updates:method release`,
  `php bin/console app:updates:method repository` or
  `php bin/console app:updates:method deployment`. Without an argument, the
  command shows the current method and whether it fits the directory.
- In YAML: `updates_method: release`, `updates_method: repository` or
  `updates_method: deployment` (deployed another way).

**Until a method is chosen**, the dashboard does not install updates, and the
command line uses the method that fits the directory: repository for a Git clone,
release ZIPs for anything else. Existing installations therefore keep updating
from the command line as before. An invalid `updates_method` value stops updates
until it is corrected.

**A method that does not fit the directory** is saved, but updates stop, and the
system check says what to change. Choosing the repository for a directory without
`.git`, or release ZIPs for a Git clone, both do this. Either
[set up a Git clone](#set-up-a-git-clone) or [switch back](#switch-methods).
"Deployed another way" fits any directory.

## Update with release ZIPs (recommended)

Releases are published on the repository's GitHub **Releases** page with three
assets: `aggregate-YYYY.MM.NN.zip`, `aggregate-release.json` (the manifest) and
`aggregate-release.json.sig` (its signature). Only stable releases published from
`updates_branch` are offered; drafts and prereleases are not. See the
[release guide](RELEASES.md) for how maintainers publish them.

On the **Updates** page:

1. Click **Check now**. The status shows the installed and latest version.
2. For PostgreSQL, MySQL, MariaDB or SQL Server, back up the database and tick the
   confirmation. SQLite is snapshotted automatically.
3. Click **Install update**. The page downloads the three assets, verifies them and
   installs the release in the background. Reload the page to follow progress.

If the server cannot reach GitHub, download the three assets from the release page
on your computer, then select all three under **Upload a release ZIP**. They are
verified the same way. Uploads need PHP's `upload_max_filesize` and
`post_max_size` above the ZIP size (release ZIPs are about 25 MB; 64M is
comfortable). The system check shows the current limit.

From the command line, as the user that owns the application files:

```bash
php bin/console app:updates:check --refresh
php bin/console app:updates:apply --preflight          # system check only
php bin/console app:updates:apply                       # newest release; add --database-backup-confirmed for non-SQLite databases
php bin/console app:updates:apply --release=2026.10.01  # a specific release
php bin/console app:updates:apply --package=aggregate-2026.10.01.zip \
  --manifest=aggregate-release.json --signature=aggregate-release.json.sig
```

Downgrades are refused. Each installed release records its version in
`release.json` and its file list in `release-files.json`.

**The first release update of a directory without `release.json`** (for example
files a deployment tool copied) is offered the latest release, and installing it
records the version. Because there is no file list to compare against yet, any
shipped configuration default that differs from the release is kept as a
`.local.yaml` override; review those files afterwards.

**Only one thing should write the application files.** If a hosting panel, CI/CD
pipeline, rsync or FTP job also deploys this directory, turn off its automatic
deployments before using the updater, or it will overwrite installed updates. To
keep using that tool instead, choose [deployed another way](#deploy-the-code-another-way).

## Update from the repository (advanced)

The repository method fast-forwards a Git clone to the newest commit of
`updates_branch` from `updates_repository`, then:

- runs `composer install` when `composer.lock` changed and `importmap:install`
  when `importmap.php` changed (the update stops before changing files if
  Composer is needed but not found),
- compiles dashboard assets,
- runs database migrations, BI glossary sync and cache warmup, and signals workers.

It installs whatever is on the branch. Commits are not signed releases, and a
branch such as `development` can contain unreleased work. Use `master` unless you
test pre-release code on purpose.

**Requirements**

- The application directory is the top of a full (not partial) Git clone of the
  repository, with the local branch named like `updates_branch`.
- The checkout is clean: no modified or untracked files that Git does not ignore,
  no merge or rebase in progress, and no skip-worktree or assume-unchanged flags.
  Edits to `config/goals.yaml`, `config/navigation.yaml` and
  `config/quick_search.yaml` are the exception: they move to their `.local.yaml`
  overrides before the pull.
- The local branch is behind or equal to GitHub, never ahead or diverged. Updates
  only fast-forward.
- Git 2.30 or newer and Composer are on `PATH` for the user that runs updates. Set
  `AGGREGATE_COMPOSER` when Composer lives elsewhere.
- That user can write the application files and the `.git` directory. When PHP
  runs as a different user from the owner of the checkout, Git may need this exact
  path in that user's `safe.directory` setting; do not use a wildcard.
- For a private repository, the user has Git HTTPS credentials (a credential
  helper). Credentials are never entered in the dashboard.

On the **Updates** page, click **Check now**, back up the database if asked, then
**Install update**. From the command line, `php bin/console app:updates:apply`
does the same. `app:updates:pull` only fast-forwards the code, without the other
steps; use it only with the [manual update steps](../DEPLOYMENT.md#manual-update-steps).

## Deploy the code another way

Choose this method when a tool you already use deploys Aggregate from Git: a
hosting panel's Git deployment such as Plesk Git or cPanel Git Version Control, a
CI/CD pipeline, or an rsync script. That tool pulls and copies the code; Aggregate
never installs updates itself, and the dashboard has no install button.

Choose it on the Updates page (**I deploy the code another way**), with
`php bin/console app:updates:method deployment`, or with
`updates_method: deployment` in YAML. Running `app:updates:deployed` for the first
time also chooses it when no method has been chosen yet.

### What the Updates page shows

The panel is read only:

- **Deployed commit**: the commit recorded the last time the
  [post-deployment steps](#after-each-deployment) ran, or, when files changed since,
  the commit the deployment repository holds now.
- **Latest on the branch** and **commits behind**, from GitHub, with a link to
  review the changes. The branch is `updates_branch`; set it to the branch you deploy.
- **Post-deployment steps last ran**, and a warning when deployed files changed
  since, which means database migrations or cache rebuilds may be pending.
- **Deployment repository**, found without any setting: the application directory
  when it is a Git clone, Plesk's `/var/www/vhosts/DOMAIN/git/NAME.git`, or
  cPanel's `~/repositories/NAME`, when there is exactly one.
- The commands to run after each deployment, with this server's paths.

`php bin/console app:updates:check` shows the same from the command line. A commit
that is not on GitHub (for example from a private fork) shows as unknown rather
than behind.

### After each deployment

Deployed code needs the same steps as an update: Composer dependencies, database
migrations, BI glossary sync, dashboard assets and a cache rebuild. Run these on the
server as the user that owns the files, by hand or from your tool's deployment
action so they run every time. `php bin/console app:updates:deployed --show-action`
prints them with the paths of your server, and the Updates page shows them too:

```bash
rm -rf /var/www/vhosts/example.com/analytics/var/cache/prod
/opt/plesk/php/8.3/bin/php /opt/psa/var/modules/composer/composer.phar install --working-dir=/var/www/vhosts/example.com/analytics --no-interaction --optimize-autoloader --no-dev
/opt/plesk/php/8.3/bin/php /var/www/vhosts/example.com/analytics/bin/console app:updates:deployed --git-dir=/var/www/vhosts/example.com/git/aggregate.git --database-backup-confirmed
```

- The first line removes the compiled cache, which can still refer to code the
  deployment replaced. Run it before anything else boots the application.
- `composer install` installs the locked dependencies (they are not in the
  repository). It also clears the cache and installs dashboard assets.
- `app:updates:deployed` shows a maintenance page, takes an SQLite snapshot, runs
  `composer install` again only if `vendor/` still differs from `composer.lock`,
  runs database migrations and BI glossary sync, installs and compiles dashboard
  assets, warms the cache, signals workers, and records the deployed commit. When
  the steps already ran for the same files, it does nothing; `--force` runs them
  again.
- `--git-dir` names the repository the files came from, so the commit is recorded.
  Give `--commit=SHA` instead when the job has the commit at hand, such as
  `--commit=$(git rev-parse HEAD)` in a clone or `--commit=$GITHUB_SHA` in GitHub
  Actions. Without either, a single repository found next to the site is used.
- `--database-backup-confirmed` is required for PostgreSQL, MySQL, MariaDB and SQL
  Server, because migrations cannot be reversed automatically. Add it to an
  automatic deployment only when the database is backed up on a schedule. SQLite
  needs no flag.

The commands use absolute paths because tools run deployment actions from
different directories. If a step fails, the site stays in maintenance mode: fix
the cause, then run `php bin/console app:updates:apply --resume` or
`app:updates:deployed` again. `app:updates:rollback` turns maintenance mode off
(and with `--restore-database` restores the SQLite snapshot), but cannot return
deployed files: deploy the previous commit with your tool.

### Keep your files out of the deployment

Your tool decides which files it replaces or deletes. Your configuration and data
are not in the repository: `.env.local`, `config/aggregate.yaml` and
`config/aggregate_*.yaml`, `config/websites.yaml`, `config/*.local.yaml`,
`config/tag-manager/sites/`, `config/secrets/` and `var/`. Keep them out of the
repository you deploy from, and exclude them from anything that deletes files, such
as `rsync --delete`. Also exclude what the post-deployment steps create:
`vendor/`, `assets/vendor/`, `public/assets/` and `public/bundles/`.

### Plesk

1. In **Websites & Domains**, open **Git** and add the repository: the official
   `https://github.com/Subschema-LLC/aggregate.git` or your fork, with the branch
   you set as `updates_branch`.
2. Choose automatic or manual deployment, and set the **deployment path** to a
   folder outside `httpdocs`, such as `analytics`. Point the domain's document
   root at `analytics/public`.
3. Under **Repository settings**, enable **additional deployment actions** and
   paste the three commands from the Updates page (or `--show-action`).
4. Deployment actions run as the subscription's system user. When SSH access is
   forbidden for that user, Plesk runs them in a chrooted environment, where PHP
   and Composer are not available. Set the user's SSH access to `/bin/bash` in the
   subscription's web hosting access settings.
5. Deploy, then finish setup in the browser as in the
   [Plesk guide](../PLESK-DEPLOYMENT.md#5-open-the-site-and-finish-setup). The
   deployment action cannot finish until setup has created `.env.local`, so run
   the post-deployment commands once after setup, or deploy again.

### cPanel

cPanel's **Git Version Control** clones a repository into `~/repositories/NAME`
and deploys it with tasks from a `.cpanel.yml` file committed at the top of the
repository, so it needs your own fork or branch. A `.cpanel.yml` that copies the
code next to the repository and runs the post-deployment steps:

```yaml
---
deployment:
  tasks:
    - export DEPLOYPATH=$HOME/analytics
    - /usr/bin/rsync -a --delete --exclude=.git --exclude=/.env --exclude=/.env.local --exclude=/.env.*.local --exclude=/.env.prod --exclude=/config/aggregate.yaml --exclude=/config/aggregate_*.yaml --exclude=/config/websites.yaml --exclude=/config/*.local.yaml --exclude=/config/tag-manager/sites/ --exclude=/config/secrets/ --exclude=/var/ --exclude=/vendor/ --exclude=/assets/vendor/ --exclude=/public/assets/ --exclude=/public/bundles/ --exclude=/SETUP-CODE.txt ./ $DEPLOYPATH/
    - /bin/rm -rf $DEPLOYPATH/var/cache/prod
    - /opt/cpanel/composer/bin/composer install --working-dir=$DEPLOYPATH --no-interaction --optimize-autoloader --no-dev
    - /opt/cpanel/ea-php83/root/usr/bin/php $DEPLOYPATH/bin/console app:updates:deployed --commit=$(git rev-parse HEAD) --database-backup-confirmed
```

Adjust the PHP path to the version the domain uses and the deployment folder to
yours, and point the domain's document root at its `public` folder. This example
has not yet been verified on a real cPanel account; please report what you had
to change. Without a
fork, you can clone straight into the application folder instead: **Update from
Remote** then pulls the code, and you run the post-deployment commands yourself.

### CI/CD and rsync

Copy the files with the exclusions above, then run the post-deployment commands
over SSH with the commit the pipeline built, for example
`--commit=$GITHUB_SHA` in GitHub Actions or `--commit=$CI_COMMIT_SHA` in GitLab.

## Set up a Git clone

The repository method needs the application directory itself to be a Git clone.
Choose one of these routes.

### New installation

Clone the repository and follow the
[native installation](../DEPLOYMENT.md#native-deployment) steps:

```bash
git clone --branch master https://github.com/Subschema-LLC/aggregate.git aggregate
```

Then choose the method with `php bin/console app:updates:method repository` or on
the Updates page.

### Convert an existing installation in place

This works when you know the exact commit the installed files came from. A release
ZIP records it in `release.json`:

```bash
php -r 'echo json_decode(file_get_contents("release.json"), true)["commit"], PHP_EOL;'
```

Run these in the application directory as the user that owns the files. Replace
`master` with your `updates_branch` and `COMMIT` with the commit above:

```bash
git init -b master
git remote add origin https://github.com/Subschema-LLC/aggregate.git
git fetch origin master
git reset COMMIT                                          # points the clone at the installed commit; files are not changed
git ls-files --deleted -z | xargs -0 -r git checkout --   # restores files release ZIPs leave out, such as dotfiles
git status --short
```

`git status --short` should list nothing, or only edits to the three customizable
configuration defaults above. Anything else must be committed, moved or restored
before the first update. `release.json`, `release-files.json`, `vendor/`, `.env`
and your configuration are ignored by Git and stay in place. Then choose the
repository method and click **Check now**.

If you do not know the installed commit, for example after files were copied by a
deployment tool, use a fresh clone instead.

### Fresh clone next to the current installation

1. Clone into a new directory and install dependencies:

   ```bash
   git clone --branch master https://github.com/Subschema-LLC/aggregate.git /srv/aggregate-new
   cd /srv/aggregate-new
   composer install --no-dev --optimize-autoloader
   ```

2. Put the site in maintenance mode on the old installation
   (`php bin/console app:updates:maintenance on`), stop async workers, and back up
   the database.
3. Copy your configuration and data from the old directory: `.env` and
   `.env.local` (and any `.env.*.local`), `config/aggregate.yaml` and
   `config/aggregate_*.yaml`, `config/websites.yaml`, `config/*.local.yaml`,
   `config/tag-manager/sites/`, `config/secrets/` if you use Symfony secrets, and
   `var/` (the SQLite database, branding uploads and generated browser scripts).
4. In the new directory, run the post-update steps:

   ```bash
   php bin/console doctrine:migrations:migrate -n
   php bin/console app:analytics:glossary:sync
   php bin/console importmap:install
   php bin/console asset-map:compile
   php bin/console cache:clear
   ```

5. Point the web server's document root (and workers) at the new directory's
   `public/`, reload PHP, and check `/api/health`.
6. Choose the repository method in the new installation. Keep the old directory
   until you are satisfied, then remove it.

## Switch methods

Switching changes which method the Updates page shows and which one
`app:updates:apply` uses. It does not change any files, and it is refused while an
update is running or needs attention. Switch on the Updates page under **Update
settings**, with `php bin/console app:updates:method`, or in YAML.

**From release ZIPs to the repository.** [Set up a Git clone](#set-up-a-git-clone)
first, or right after switching; until the directory is a clone, updates stop.

**To or from deployed another way.** Choosing it changes nothing on disk; add the
[post-deployment commands](#after-each-deployment) to your tool. Before switching
away from it, turn off the tool's automatic deployments, so only one thing writes
the application files.

**From the repository to release ZIPs.** A Git clone cannot take release ZIPs,
because the ZIP would change tracked files behind Git's back. Either:

- **Keep the directory:** move `.git` out of it (for example
  `mv .git ../aggregate.git-backup`). The directory then counts as copied files,
  and the next release update installs the latest release and records its version.
  Do this only when the clone is at or behind the latest release, such as a clone
  of `master`. A clone of `development` that is ahead of the latest release would
  go back to older code while the database keeps newer migrations.
- **Start clean:** extract the latest release ZIP into a new directory and move your
  configuration and data as in [fresh clone](#fresh-clone-next-to-the-current-installation),
  steps 2 to 5 (skip Composer: the ZIP includes `vendor/`).

## Settings

| Setting | Values | Where to change it |
| --- | --- | --- |
| `updates_method` | `release` (recommended), `repository` (advanced) or `deployment` (deployed another way); unset until an administrator chooses | Updates page, `app:updates:method`, YAML |
| `updates_branch` | Branch the repository method pulls, the branch releases must be published from, and the branch a deployed commit is compared with; default `master` | Updates page, YAML |
| `updates_repository` | GitHub `owner/name`; default `Subschema-LLC/aggregate` | YAML only |
| `updates_signing_public_key` | Optional base64 Ed25519 public key that replaces the shipped `config/release-signing.pub` | YAML only |
| `AGGREGATE_GITHUB_TOKEN` | Optional token that raises the GitHub API rate limit, or reads a private repository | Server environment or `.env.local` |
| `AGGREGATE_COMPOSER` | Path to Composer when it is not on `PATH` | Server environment |

The YAML settings live in `config/aggregate.yaml` with active-environment
precedence and have no uppercase environment-variable override. Settings saved from
the dashboard or the command line are written to the same file, so the file
remains the single source of truth. See
[configuration](CONFIGURATION.md#github-update-checks).

## What an update keeps

With [deployed another way](#deploy-the-code-another-way), your tool replaces the
files; [keep your files out of it](#keep-your-files-out-of-the-deployment). The other
two methods never replace or delete `.env.local` and other local environment files,
`config/aggregate.yaml` and `config/aggregate_*.yaml`, `config/websites.yaml`,
`config/tag-manager/sites/`, `config/*.local.yaml`, `config/secrets/`, the
installed `config/release-signing.pub`, or anything in `var/` (database, branding
uploads, logs). Edits to the shipped `config/goals.yaml`,
`config/navigation.yaml` or `config/quick_search.yaml` move to the matching
[local override](CONFIGURATION.md#local-overrides-for-shipped-defaults). New keys
in a release's `.env` are appended; existing values stay. The
[release guide](RELEASES.md#update-an-installation) lists how every kind of file is
treated.

## When an update fails

Changed files are backed up under `var/updates/backups/` (the last three updates
are kept).

- If a check fails or a download does not verify, nothing changes.
- If installing files fails, the previous files are restored automatically.
- If a later step fails, such as a migration, the site stays in maintenance mode.
  Fix the cause, then run `php bin/console app:updates:apply --resume`, or restore
  the previous files with `php bin/console app:updates:rollback`.

Rolling back files never reverses database migrations. With SQLite,
`app:updates:rollback --restore-database` also restores the snapshot taken before
the update, discarding data recorded since. With other databases, restore your own
backup. If the console itself cannot start, `php scripts/restore-update-files.php`
restores the files without loading the application.

## Command reference

```bash
php bin/console app:updates:method                 # show the method and whether it fits
php bin/console app:updates:method release         # or: repository, deployment
php bin/console app:updates:check --refresh        # what is available
php bin/console app:updates:apply --preflight      # system check as this user; changes nothing
php bin/console app:updates:apply                  # install; add --database-backup-confirmed for non-SQLite databases
php bin/console app:updates:apply --status         # last update and its log
php bin/console app:updates:apply --resume         # continue a stopped update
php bin/console app:updates:rollback               # restore the previous files
php bin/console app:updates:deployed --show-action # deployed another way: the commands to run after each deployment
php bin/console app:updates:deployed               # deployed another way: run the post-deployment steps
php bin/console app:updates:maintenance status     # or: on, off
php bin/console app:updates:verify-package PACKAGE MANIFEST SIGNATURE
```

Run them as the user that owns the application files. The dashboard runs the same
`app:updates:apply` command in the background and writes its output to
`var/updates/last-run.log`.

## Troubleshooting

The **System check** panel on the Updates page lists everything an update depends
on, as seen by the web server user. `app:updates:apply --preflight` prints the same
checks for the command-line user.

| Message or symptom | What to do |
| --- | --- |
| **Update method chosen: Not yet** | Choose a method on the Updates page or with `app:updates:method`. |
| The update method is repository, but the directory is not a Git clone | [Set up a Git clone](#set-up-a-git-clone), or switch back to release ZIPs. |
| The update method is release ZIP, but the directory is a Git clone | See [switch methods](#switch-methods), or switch back to the repository. |
| No packaged stable release was found | Publish a stable (not draft or prerelease) release from `updates_branch` with its three assets. See the [release guide](RELEASES.md#publish-a-release). |
| The upload was larger than this server accepts | Use **Download from GitHub**, or raise `upload_max_filesize` and `post_max_size`. |
| The checkout has local changes or untracked files | Run `git status` on the server; commit, move or restore those files. |
| The installed branch differs from `updates_branch` | Check out the configured branch with Git, or change `updates_branch`. |
| Composer was not found | Install Composer for the update user, or set `AGGREGATE_COMPOSER`. |
| User … cannot write … | Run `app:updates:apply` on the server as the owner of the files, or give the web server user write access to use the dashboard. |
| The site still runs old code after an update | PHP OPcache is not revalidating files (`opcache.validate_timestamps=0`); reload PHP-FPM or the web server. |
| Installed updates disappear later | Another deployment tool overwrote the files; turn off its automatic deployments. |
| **Post-deployment steps: Not recorded yet**, or files changed since | Run the [post-deployment commands](#after-each-deployment), and add them to your tool's deployment action. |
| The deployed commit is unknown | Give `app:updates:deployed` `--git-dir` (the folder your tool keeps the repository in) or `--commit`. |
| Several deployment repositories were found | Name the deployed one with `--git-dir`. |
| A deployment action fails with "command not found" for PHP or Composer | Use the full paths from `--show-action`. On Plesk, set the system user's SSH access to `/bin/bash`, so actions do not run in a chroot. |
| The site shows a maintenance page after a failed update | Run `app:updates:apply --resume` after fixing the cause, or `app:updates:rollback`; then `app:updates:maintenance off` if needed. |
