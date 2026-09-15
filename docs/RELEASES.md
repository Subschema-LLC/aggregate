# Signed release packages

[Deployment](../DEPLOYMENT.md#updates) · [Configuration](CONFIGURATION.md) · [JavaScript build](JS-BUILD.md)

GitHub Releases on the public `Subschema-LLC/aggregate` repository host installable ZIPs. A separate artifact repository is unnecessary. The foundation includes production packaging, signed manifests, version checks without Git, and offline package verification. Automatic replacement of an existing installation is a follow-up: the dashboard currently checks versions and links to packages.

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

The public key and Actions secret are intentionally not generated or populated by this source change. Signing fails until both are configured and match. Future key rotation needs a trust transition for existing installations; replacing a key in a downloaded package does not authorize that new key.

## Publish a release

1. Merge the tested release changes into `master`, or the branch configured in `config/release.yaml`.
2. Create and push an immutable stable tag such as `v1.0.0` on a commit from that branch. An existing stable tag can also be supplied to the workflow's manual dispatch.
3. The **Build release package** workflow tests PHP, JavaScript, and release tooling; prepares clean production dependencies; compiles dashboard and minified browser assets; creates and signs the ZIP; and boots the extracted package.
4. After success, review and publish the **draft GitHub Release**. Drafts and prereleases are ignored by installation checks. An existing release's assets are not overwritten; use a new version for corrections.

Each release includes:

| Asset | Purpose |
| --- | --- |
| `aggregate-X.Y.Z.zip` | Application source, production dependencies, configuration examples, built assets, public signing key, and embedded `release.json` |
| `aggregate-release.json` | Version, repository, branch, commit, UTC build time, runtime requirements, ZIP filename, size, and SHA-256 |
| `aggregate-release.json.sig` | Base64 detached Ed25519 signature over the exact manifest bytes |

The workflow uses short-lived Actions artifacts to transfer files between jobs; customers download durable GitHub Release assets. Packages contain no development dependencies, application cache, logs, database files, user configuration, branding uploads, or deployment secrets. They include a generated production `.env` with an empty `APP_SECRET`; configure `.env.local` before installation. The readable tracker and its BSD license remain alongside generated minified scripts.

PHP requirements come from Composer's generated platform check with a baseline of PHP 8.2; required extensions include production Composer requirements and packaging/verification requirements. The host also needs the PDO driver for its chosen database. PHP/platform checks do not establish database-server compatibility or migration readiness.

## Check and verify on an installation

```bash
php bin/console app:updates:check --refresh --json
php bin/console app:updates:verify-package \
  /path/to/aggregate-1.0.0.zip \
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

## Install or deploy a verified package

The ZIP contains its files at the archive root. Extract it into a fresh application directory, configure `.env.local` with database credentials and a newly generated `APP_SECRET`, point the web server at `public/`, and use `/install` for schema/admin setup. The existing web installer requires those infrastructure settings first; a full browser-only credentials/bootstrap installer is not included in this groundwork. No Git, Composer, or Node installation is needed on the target server for a prepared release package.

For an existing installation, stage the new directory and retain deployment data deliberately:

- Preserve `.env.local` and other actual environment overrides, the active `config/aggregate.yaml` or environment-specific files, and `config/websites.yaml`.
- Merge customized `config/goals.yaml` and `config/navigation.yaml` as needed. Do not carry forward the entire old `config/` directory, which would hide new routes and service definitions.
- Preserve `var/branding`, any SQLite database, and separately configured logo/MMDB paths. Do not share the whole `var/` directory: `var/cache` belongs to the new release and `var/browser` contains that release's compiled tracker template.
- Back up the database and configuration, pause collection/async workers as required, run migrations and rebuild production caches against the preserved configuration, activate the new release, restart workers/reload PHP, and verify `/api/health` before resuming collection.

Replacing application files does not reverse database migrations. File recovery and database restoration need separate procedures. The future automatic updater must coordinate these operations, report progress, resume after interruptions, and prevent concurrent updates; the current verifier intentionally leaves the installation untouched.

## Local package development

Use Python 3.11+, PHP with sodium, Composer, and the pinned JavaScript build dependency on the build machine. Prepare a **separate clean staging checkout** so installing production dependencies does not remove development tools from your working copy. The workflow is the complete reference for dependency preparation and asset compilation. It excludes development fixtures before compiling the production container.

After preparing the stage, build and sign:

```bash
python3 scripts/build-release.py \
  --source-dir /path/to/prepared-stage \
  --output-dir /path/to/empty-dist \
  --version 1.0.0 --branch master --commit FULL_COMMIT_SHA
php scripts/sign-release.php \
  /path/to/empty-dist/aggregate-release.json \
  config/release-signing.pub
```

For signing, provide `RELEASE_SIGNING_PRIVATE_KEY` through your local secret manager or CI environment; avoid typing private keys into shell history. `--built-at` accepts a UTC timestamp for reproducible ZIP timestamps. The local builder validates package inputs but does not prove Git ancestry; the release workflow performs that check. Existing outputs are never replaced.

Release-tooling tests run with `python3 -m unittest discover -s tests/Release -p 'test_*.py' -v`. PHP's normal suite covers branch selection, release discovery, package verification, and UI/CLI behavior. Run the extracted ZIP's production container and console commands before publishing.
