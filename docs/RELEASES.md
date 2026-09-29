# Signed release packages

[Deployment](../DEPLOYMENT.md#updates) · [Configuration](CONFIGURATION.md) · [JavaScript build](JS-BUILD.md)

GitHub Releases on the public `Subschema-LLC/aggregate` repository host installable ZIPs. A separate artifact repository is unnecessary. Installations check versions without Git, verify packages against an independently trusted key, and install them in place with `app:updates:apply` or the dashboard **Install update** button, keeping their configuration.

Before the first public release, complete the [repository and reporting setup](PUBLIC-RELEASE.md).

## Branch settings

Deployments select their update channel in the active aggregate YAML configuration:

```yaml
updates_branch: master
```

`master` is the default when omitted. Environment-specific files or `environments` entries can override it; uppercase environment variables do not override this setting. Git checks compare against this upstream branch, even if the checkout is on another branch or detached. Source pulls require the local branch to match and never switch branches automatically.

Maintainers select the publishing branch in the tracked `config/release.yaml`:

```yaml
branch: master
```

The workflow verifies that the stable release tag points to a commit on that branch. Keep this separate from each customer's installed configuration. ZIP update checks use the manifest's explicit branch, since GitHub's `target_commitish` can be a branch name or a commit hash.

## One-time maintainer setup

Generate an Ed25519 signing key pair on a trusted machine with PHP sodium. Keep the private file outside the repository:

```bash
mkdir -p "$HOME/.config/aggregate-release"
php scripts/sign-release.php --generate-keypair \
  "$HOME/.config/aggregate-release/private.key" \
  config/release-signing.pub
```

The helper creates a private file with mode `0600` and refuses to overwrite existing destinations. It prints file paths, never key contents. Commit **only** `config/release-signing.pub`, which contains the base64 public key. Add the contents of the private file as the repository's GitHub Actions secret **`RELEASE_SIGNING_PRIVATE_KEY`**. Back up the private key securely; never place it in application YAML, an environment example, or a ZIP.

The public key and Actions secret are intentionally not generated or populated by this source change. Until both are configured and match, the release workflow stops at its first step, **Check release signing setup**, which names the problem: the secret is empty, is not base64, holds the public key instead of the private key, or does not match `config/release-signing.pub`. Check a key locally the same way without printing it:

```bash
RELEASE_SIGNING_PRIVATE_KEY="$(cat "$HOME/.config/aggregate-release/private.key")" \
  php scripts/sign-release.php --check-key
```

The workflow builds the tagged commit, so the tag must point to a commit that already contains `config/release-signing.pub`. After committing the key and promoting it to the publishing branch, tag that commit with the next [version](#version-numbers). If an earlier tag failed and no release was published from it, you may delete that tag and tag the new commit instead. Future key rotation needs a trust transition for existing installations; replacing a key in a downloaded package does not authorize that new key.

## Version numbers

Releases use calendar versions: `vYYYY.MM.NN`, the year, the two-digit month, and
`NN`, the release's number within that month starting at `01`. `NN` is not the day:
the first release in September 2026 is `v2026.09.01`, the second is `v2026.09.02`,
and the first in October is `v2026.10.01`. Numbering continues after the highest
existing release for the month; a skipped number is not reused.

Print the next unused tag for the current UTC month, or for a given month:

```bash
python3 scripts/release-version.py next
python3 scripts/release-version.py next --month 2026.10
python3 scripts/release-version.py check v2026.09.01
```

The release workflow and `build-release.py` accept only this format; a tag such as
`v2026.9.1`, `v2026.09.00` or `v1.2.3` fails before anything is built. Package
metadata and file names drop the `v` (`2026.09.01`, `aggregate-2026.09.01.zip`).
Installations order versions by year, month and index, so every calendar release is
newer than an earlier `X.Y.Z` package, and installations from such packages can
still read their own metadata and update.

## Publish a release

Follow the [branching strategy](../CONTRIBUTING.md#branching-strategy): working
branches start from `development` and merge back into it through contributor PRs.

1. Open and merge a promotion PR from `development` to `uat`, then complete user acceptance testing. Submit any fixes through working-branch PRs to `development` and promote them to `uat` for testing too.
2. After acceptance testing passes, open and merge a promotion PR from `uat` to `master`. Production publishing defaults to `master`; maintainers using a different publishing branch must keep `config/release.yaml` aligned with their production target.
3. Create and push an immutable [calendar version](#version-numbers) tag on a commit from the configured publishing branch, for example `tag=$(python3 scripts/release-version.py next) && git tag "$tag" origin/master && git push origin "$tag"`. An existing release tag can also be supplied to the workflow's manual dispatch.
4. The **Build release package** workflow tests PHP, JavaScript, and release tooling; prepares clean production dependencies; compiles dashboard and minified browser assets; creates and signs the ZIP; and boots the extracted package.
5. After success, review and publish the **draft GitHub Release**. Drafts and prereleases are ignored by installation checks. An existing release's assets are not overwritten; use a new version for corrections.

Each release includes:

| Asset | Purpose |
| --- | --- |
| `aggregate-YYYY.MM.NN.zip` | Application source, production dependencies, configuration defaults and examples, built assets, public signing key, embedded `release.json`, and `release-files.json` (the SHA-256 of every file in the package) |
| `aggregate-release.json` | Version, repository, branch, commit, UTC build time, runtime requirements, ZIP filename, size, and SHA-256 |
| `aggregate-release.json.sig` | Base64 detached Ed25519 signature over the exact manifest bytes |

The workflow uses short-lived Actions artifacts to transfer files between jobs; customers download durable GitHub Release assets. Packages contain no development dependencies, application cache, logs, database files, user configuration, branding uploads, or deployment secrets. They include a generated production `.env` with an empty `APP_SECRET`; configure `.env.local` before installation. The readable tracker and its BSD license remain alongside generated minified scripts.

PHP requirements come from Composer's generated platform check with a baseline of PHP 8.2; required extensions include production Composer requirements and packaging/verification requirements. The host also needs the PDO driver for its chosen database. PHP/platform checks do not establish database-server compatibility or migration readiness.

## Check and verify on an installation

The deployment-wide `updates` [feature flag](FEATURE-FLAGS.md) must be enabled
(the default) for these commands and the Updates page. Its navigation visibility
is independent of availability. Disabling it also blocks Git source pulls;
re-enable it through active YAML or the Feature flags admin page when needed.

```bash
php bin/console app:updates:check --refresh --json
php bin/console app:updates:verify-package \
  /path/to/aggregate-2026.09.01.zip \
  /path/to/aggregate-release.json \
  /path/to/aggregate-release.json.sig
```

The Updates page uses the same checks. A source checkout with its own `.git` uses Git; a ZIP installation with `release.json` and no `.git` uses stable releases without invoking Git. Keep `release.json` from the original package intact; invalid metadata is reported as an error. Source ZIPs automatically supplied by GitHub do not contain this production package or its metadata.

Release discovery checks stable versions, configured branch, asset metadata, and PHP/extension compatibility. It does **not** verify signatures or download/extract the ZIP. Results are cached for one hour, errors for one minute. Checks examine at most 60 release entries and 20 manifests; a bounded search that cannot establish current status reports that limitation. Public checks require no token; `AGGREGATE_GITHUB_TOKEN` is optional for higher API limits.

The verification command uses the independently trusted public key already installed at `config/release-signing.pub`. A deployment may explicitly override it in YAML:

```yaml
updates_signing_public_key: 'BASE64_ED25519_PUBLIC_KEY'
```

The command verifies the signature, branch, runtime requirements, ZIP size/hash, bounded archive layout, and agreement with embedded `release.json`. Unsafe paths, symlinks, duplicate/case-conflicting paths, missing required files, and oversized archives are rejected. Inputs must be local files; the command performs no network requests, extraction, file replacement, or database changes. It checks the PHP runtime running the command; verify that the web process uses a compatible runtime too. Signature verification requires a public key trusted independently of the package being verified, including during initial installation.

## Update an installation

Run as the user that owns the application files:

```bash
php bin/console app:updates:apply --preflight   # checks only; changes nothing
php bin/console app:updates:apply                # downloads, verifies and installs the newest release
php bin/console app:updates:apply --release=2026.10.01
php bin/console app:updates:apply \
  --package=/path/to/aggregate-2026.10.01.zip \
  --manifest=/path/to/aggregate-release.json \
  --signature=/path/to/aggregate-release.json.sig
```

Add `--database-backup-confirmed` for PostgreSQL, MySQL, MariaDB and SQL Server
after backing up the database; SQLite is snapshotted automatically. Administrators
can start the same update from the dashboard **Updates** page. Downgrades are refused.

The update runs these steps and records them in `var/updates/state.json`, so it can
be resumed with `--resume` after an interruption. A lock prevents two updates at once.

1. **Download and verify** the three release assets (skipped for local files). The
   manifest signature, branch, runtime requirements, package checksum and archive
   layout are checked as by `app:updates:verify-package`.
2. **Stage and plan.** The ZIP is extracted under `var/updates/staging/`, every file
   is checked against `release-files.json`, and the update works out which files
   change. It stops here, before anything changes, if a file is not writable or an
   edited configuration default conflicts with an existing override.
3. **Maintenance and backup.** Web requests get a 503 page; workers are signalled;
   an SQLite database is copied into the backup.
4. **Install files** in place, dependencies first, each file written to a temporary
   file and renamed. Every replaced or removed file is copied to
   `var/updates/backups/<id>/` first. `release.json` and `release-files.json` are
   written last. If this step fails, the files are restored automatically.
5. **Continue with the new code.** A fresh `app:updates:apply --resume` process runs
   migrations, `app:analytics:glossary:sync`, cache warmup and the worker restart
   signal, then leaves maintenance mode and removes the staging copy.

How files are treated:

| Files | Update behavior |
| --- | --- |
| `.env.local`, `.env.*.local`, `.env.prod`, `config/aggregate.yaml`, `config/aggregate_*.yaml`, `config/websites.yaml`, `config/tag-manager/sites/`, `config/*.local.yaml`, `config/secrets/`, `var/` (except `var/browser/`) | Never replaced or deleted. Packages cannot contain them: the builder refuses them and the updater rejects a package that includes one. |
| `config/goals.yaml`, `config/navigation.yaml`, `config/quick_search.yaml` | Edits move to `config/NAME.local.yaml` (see [local overrides](CONFIGURATION.md#local-overrides-for-shipped-defaults)); the new default is installed. |
| `.env` | Existing values kept; keys new in the release are appended. |
| `config/release-signing.pub` | The installed trusted key is never replaced by a package. |
| `public/.htaccess`, `public/robots.txt` | Kept when you changed them; the release's version is saved in the backup's `incoming/` folder. |
| Other shipped files | Replaced when they changed. Locally edited copies are reported and kept in the backup. |
| Files in release-owned directories (`src`, `templates`, `translations`, `migrations`, `assets`, `micro-consent-dropins`, `scripts`, `vendor`, `public/assets`, `public/bundles`, `config/packages`, `config/routes`) that the new release does not ship | Removed after backup, so stale classes cannot be autoloaded. Keep custom code outside these directories. |
| Root files that the previous release shipped and the new one does not | Removed when unchanged; kept and reported when you edited them. |

Installations whose code predates `app:updates:apply` (such as the v0.2
prerelease) need one manual update, described below; later updates use the
command. The comparison uses the installed `release-files.json`. When it is missing,
for example after a manual deployment, the update still runs, but because unchanged
files cannot be told apart from edits, any shipped config default that differs from
the new one is kept as an override; review those override files after the update. Packages without `release-files.json` cannot be applied automatically;
install them manually as described below.

Recovery keeps files and the database separate:

- `php bin/console app:updates:rollback` restores the files the last update changed
  (Git checkouts return to the previous commit) and warms the cache. It never
  reverses migrations. `--restore-database` additionally restores the SQLite
  snapshot, discarding data recorded since the update started.
- If the console cannot start, `php scripts/restore-update-files.php` restores the
  files from the backup without loading the application and leaves maintenance
  mode on; then run `cache:clear` and `app:updates:maintenance off`.
- `app:updates:maintenance on|off|status` controls the maintenance page directly.

## Install or deploy a verified package

The ZIP contains its files at the archive root. Extract it into a fresh application directory, configure `.env.local` with database credentials and a newly generated `APP_SECRET`, point the web server at `public/`, and use `/install` for schema/admin setup. The existing web installer requires those infrastructure settings first; a full browser-only credentials/bootstrap installer is not included in this groundwork. No Git, Composer, or Node installation is needed on the target server for a prepared release package.

To update an existing installation, prefer [`app:updates:apply`](#update-an-installation). To deploy manually instead (for example, from a package without `release-files.json`), stage the new directory and retain deployment data deliberately:

- Preserve `.env.local` and other actual environment overrides, the active `config/aggregate.yaml` or environment-specific files, `config/websites.yaml`, and `config/tag-manager/sites/` with each website's tag/CMP settings and environment overrides.
- Preserve `config/*.local.yaml` overrides, and move any edits of the shipped `config/goals.yaml`, `config/navigation.yaml` or `config/quick_search.yaml` into those overrides. Do not carry forward the entire old `config/` directory, which would hide new routes and service definitions.
- Preserve `var/branding`, any SQLite database, and separately configured logo/MMDB paths. Do not share the whole `var/` directory: `var/cache` belongs to the new release and `var/browser` contains that release's compiled tracker, CMP, and tag-manager templates.
- Back up the database and configuration, pause collection/async workers as required, run migrations and rebuild production caches against the preserved configuration, activate the new release, restart workers/reload PHP, and verify `/api/health` before resuming collection.

Replacing application files does not reverse database migrations. File recovery and database restoration need separate procedures. `app:updates:verify-package` only verifies and leaves the installation untouched.

## Local package development

Use Python 3.11+, PHP with sodium, Composer, and the pinned JavaScript build dependency on the build machine. Prepare a **separate clean staging checkout** so installing production dependencies does not remove development tools from your working copy. The workflow is the complete reference for dependency preparation and asset compilation. It excludes development fixtures before compiling the production container.

After preparing the stage, build and sign:

```bash
python3 scripts/build-release.py \
  --source-dir /path/to/prepared-stage \
  --output-dir /path/to/empty-dist \
  --version v2026.09.01 --branch master --commit FULL_COMMIT_SHA
php scripts/sign-release.php \
  /path/to/empty-dist/aggregate-release.json \
  config/release-signing.pub
```

For signing, provide `RELEASE_SIGNING_PRIVATE_KEY` through your local secret manager or CI environment; avoid typing private keys into shell history. `--built-at` accepts a UTC timestamp for reproducible ZIP timestamps. The local builder validates package inputs but does not prove Git ancestry; the release workflow performs that check. Existing outputs are never replaced.

Release-tooling tests run with `python3 -m unittest discover -s tests/Release -p 'test_*.py' -v`. PHP's normal suite covers branch selection, release discovery, package verification, and UI/CLI behavior. Run the extracted ZIP's production container and console commands before publishing.
