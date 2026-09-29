# Preparing the public repository

[Release packaging](RELEASES.md) · [Contribution guide](../CONTRIBUTING.md) · [Roadmap](../ROADMAP.md)

Making source public, inviting beta adopters, and publishing a signed package
are separate milestones. The checklists below are maintainer actions, not a
completed audit. Record an owner and evidence for each gate in the release
checklist or promotion PR; keep security findings and credentials private.
Repository files cannot enable GitHub account settings or establish that a
reporting inbox is monitored.

## Local readiness review: 2026-09-22

The source baseline was `647f62f86a00409287b78fe2ee4e8513daa9c51b`, with the
release-preparation changes accompanying this review. This is local evidence;
the publication gates below remain open until their maintainer checks are done.

| Check | Evidence and limits |
| --- | --- |
| Current tracked files | The 327-file baseline excludes operator `.env`/YAML, IDE metadata, runtime data, installed dependencies, and the obsolete architecture PDF. Exclusion from the current tree does not remove history. |
| History scanner | Gitleaks **8.30.1**, downloaded from its official release with the published archive SHA-256 checked, ran with `git --log-opts="--all --full-history -m" --redact=100`. The non-shallow local repository has 224 reachable commits; the scanner processed 217 commit patches, including merge diffs. It reported **11 findings**. Current tracked-file scanning reported **2 findings**, both explicitly labeled website-token examples. Reports stay outside the repository; no secret values are reproduced here. |
| History disposition | Historical secret-setting findings still need owner review and rotation if used operationally; other findings are website-token examples. Historical IDE command metadata contains machine-local paths. The five-page PDF contains architecture text; automated inspection detected no author/creator metadata, email addresses, local paths, or credential-bearing URLs. These checks do not replace the owner's content/publication review. Nothing was rewritten or rotated. |
| Dependency advisories | `composer audit --locked --no-interaction` and `npm audit --json` both completed against their public registries with **zero reported advisories**. `composer validate --strict --no-check-publish --no-interaction` passed. Rerun the audits for the selected candidate; advisory results are time-dependent. |
| Release tooling | **23 tests passed** with Python 3.12.3 and PHP 8.3.6. Packaging includes the consent/tag-manager sources, minified assets, private runtime templates and manifests; it rejects stale templates and missing tracker license text. A real ZIP regression confirms that per-site tag/CMP YAML, including environment overrides and nested operator files under `config/tag-manager/sites/`, is excluded. The release workflow now checks the extracted package's container with the dashboard enabled and disabled. These tests do not establish a signed production package or database compatibility. |
| Community files | Policies, attributed Contributor Covenant text, beta guidance, issue/PR templates, funding configuration, and AGPL/BSD notices are present. Inbox delivery and funding availability remain unverified. |
| GitHub branches | The repository is private with default branch `development`. Effective rules on `development`, `uat`, and `master` require PRs with two approvals and prevent deletion/force pushes. **None requires the final `CI` check**; add that requirement to each ruleset. Latest CI runs for the remote tips below succeeded. |
| GitHub security | Dependabot alerts are enabled; Dependabot security updates, secret scanning, and push protection are disabled. The private-vulnerability-reporting endpoint returned 404; verify availability and enable it when the repository becomes public. These settings were read, not changed. |
| Actions and access | Actions default to read permission and cannot approve PR reviews. All Actions are permitted and repository-wide SHA pinning is not enforced, although the checked-in workflows pin third-party Actions. Two collaborators are visible, with admin/write roles; a maintainer must review their continued access and ruleset bypasses. |
| Signing prerequisites | `config/release-signing.pub` is absent, and no repository Actions secrets were listed; inherited organization secrets were not reviewed. Production signing needs the public key, matching secret, and independent trust instructions. Existing tags `v0.1` and `v0.2` do not satisfy the `vYYYY.MM.NN` [calendar version](RELEASES.md#version-numbers) packaging format. |

The scan covered all locally available refs: local `development`, `master`,
`docs/roadmap-2`, `feature/setup-dropins-tag-manager`, and the
`fix/dependabot-102-ci`, `-103-ci`, `-104-ci`, and `-107-ci` branches;
cached `origin/development`, `origin/uat`, `origin/master`, and `origin/HEAD`;
and tags `v0.1` and `v0.2`. Read-only GitHub queries confirmed that all advertised
branches and tags match the scanned local tips. No fetch changed local refs;
pull-request, fork, and cached GitHub refs were not inspected.

| Ref | Commit | Latest CI |
| --- | --- | --- |
| `origin/development` | `647f62f86a00409287b78fe2ee4e8513daa9c51b` | [Passed](https://github.com/Subschema-LLC/aggregate/actions/runs/35612714921) |
| `origin/uat` | `1571ceb36d01d35300dee8c92e9cd2b00bc6ffcd` | [Passed](https://github.com/Subschema-LLC/aggregate/actions/runs/35240921347) |
| `origin/master` | `42014956bbac2f4e6cee223ca07986947e420e2d` | [Passed](https://github.com/Subschema-LLC/aggregate/actions/runs/35241083092) |

Before publication, repeat the scan on the complete intended ref set and resolve
the private findings. Require `CI`, enable the outstanding security settings,
verify the reporting inbox/access list, complete candidate UAT and recovery
rehearsals, and establish signing trust before publishing a reviewed package.
No candidate was selected or published and no repository settings were changed.

## Before changing repository visibility

- [ ] Review current tracked files and every branch/tag to be published, following
  [the history guidance below](#current-files-and-git-history). Resolve exposed
  secrets and private material before changing visibility.
- [ ] Verify that the private reporting inbox listed in [SECURITY.md](../SECURITY.md)
  and [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md) is monitored and receives mail.
  Keep those policies current if the security and conduct contacts change.
- [ ] Enable GitHub private vulnerability reporting as an additional private channel,
  and enable secret scanning and push protection where available. Review findings
  before changing visibility; if a setting requires a public repository, arrange
  to enable and verify it immediately after the visibility change.
- [ ] Run [CI](../.github/workflows/ci.yml) on GitHub, then require its final **CI** check
  on `development`, `uat`, and `master`, and require pull requests for changes.
  Protect the branches against accidental force pushes and deletion. CI covers
  PHP 8.2/8.3, browser assets, release tooling, and Composer/npm advisory audits.
  It also runs weekly; its fixtures do not prove every supported database engine.
- [ ] Review repository collaborators, Actions permissions/secrets, and enabled
  workflows. Pull requests from forks must run without deployment credentials;
  do not run untrusted PR code in a privileged `pull_request_target` job.
- [ ] Verify the issue forms, PR template, repository description, license notices,
  funding link, and visible beta status. Use `development` as the contributor
  landing/default branch; production publishing and installed update channels
  still default to `master`. Verify the public interface after changing visibility.
  Contributions target
  `development`; maintainer promotion PRs go from `development` to `uat` for user
  acceptance testing, then from `uat` to `master`. See the
  [branching strategy](../CONTRIBUTING.md#branching-strategy). Tagged production
  releases come from `master` by default.

## Before inviting beta adopters

- [ ] Select a `uat` candidate through the documented promotion PR and record its
  full commit ID. Run the [beta test checklist](BETA-TESTING.md#test-checklist) on
  a fresh installation with synthetic data. Publish the tested PHP/database
  versions and known limitations; do not imply every documented engine passed.
- [ ] Review open authentication, consent, disclosure, installer, packaging, and
  data-loss issues. Resolve known blockers and record any deferred operational
  limitations before inviting live-traffic pilots. Document how first-run setup
  is restricted to the operator, following [deployment guidance](../DEPLOYMENT.md#security).
- [ ] Verify the documented setup works with the dashboard enabled and disabled,
  and that a routine BI account can query approved views without reading private
  input tables. Rehearse the candidate's backup/restore and manual update path in
  a disposable deployment before suggesting an upgrade to existing adopters.
- [ ] Run current dependency advisories, review findings, and record the date and
  results. Successful unit tests are not a dependency audit or an independent
  security/privacy review.
- [ ] Check the [beta feedback form](../.github/ISSUE_TEMPLATE/beta_feedback.yml)
  and assign maintainers to triage installation failures, confusing docs, database
  compatibility, and accessibility reports. Invite reproducible reports rather
  than requesting production logs or event exports.

## Current files and Git history

IDE metadata is excluded from the current source. The obsolete
`Subschema_ Aggregate.pdf` architecture draft is also excluded; current behavior
is documented in the Markdown guides. Its local copy may be retained privately.

Untracking a file does not remove it from earlier commits, other branches, tags,
forks, or cached pull-request views. Review the full history, including previous
`.env` and `config/aggregate.yaml` versions, before publication. Rotate any value
that was used as a real credential or secret; deleting its history does not revoke
it. A local username or machine path alone is not a credential, so decide whether
to remove that historical metadata based on the intended public record.

Use a full clone containing the refs intended for publication, not a shallow CI
checkout. Inspect the tracked file inventory and historical paths and run a
maintained secret scanner across the full history. Review scanner findings
privately and manually inspect deployment configs, uploaded documents, and IDE
metadata that automated rules may miss. Record the scanned refs, tool/version,
date, and disposition of findings without copying secret values into an issue.
A clean current tree or empty scanner result alone does not establish that the
historical repository is safe to publish.

If history must be scrubbed, first commit the current cleanup and pause merges.
Back up all refs, then use `git filter-repo` in a separate fresh mirror to remove
the agreed paths from every branch and tag. Inspect the resulting trees and ref
changes, rerun tests from a fresh checkout, and coordinate the replacement with
every contributor and deployment before pushing it. Commit IDs and affected tags
will change; signed tags need re-signing. Force-pushing and GitHub-side cleanup of
cached refs require a separate, deliberate maintenance step. Keep the original
backup private and use fresh checkouts afterward.

## Before publishing a signed package

- [ ] Follow the [signing-key setup](RELEASES.md#one-time-maintainer-setup): commit
  only the public key and put the matching private key in the GitHub Actions
  secret. Establish an independent way for adopters to trust that public key.
- [ ] Follow the `development` → `uat` → `master` promotion path and publish from
  the configured release branch. Current package tooling requires
  [calendar version](RELEASES.md#version-numbers) tags such as `v2026.09.01`; installation checks ignore drafts and prereleases. Source beta
  candidates use a recorded commit, not an invented package update channel.
- [ ] Review the draft release, verify its package, and smoke-test the extracted
  ZIP on a clean target without Git, Composer, or Node. Confirm it includes
  production dependencies, built assets, metadata, and license notices, and
  excludes operator configuration, secrets, runtime data, and IDE files.
- [ ] Publish release notes with tested environments, known issues, operator
  actions, and upgrade/recovery instructions (`app:updates:apply`, `--resume`,
  `app:updates:rollback`, and the manual steps for installations that predate the
  updater). The release workflow does not make the repository public automatically.

## Follow-up improvements

- Review the weekly [Dependabot configuration](../.github/dependabot.yml) for
  Composer, npm, and GitHub Actions PRs targeting `development`. These version
  updates do not enable GitHub dependency alerts or security updates; configure
  those repository settings separately. CI and release workflows pin third-party
  Actions to immutable commits; review their update PRs through CI.
- Add disposable database integration jobs for the engines the project supports,
  particularly migrations, reporting views, and transactional behavior.
- Extend the PHP matrix as newer runtimes are verified, and test a first-time
  installation and an upgrade from a published release.
- Keep release notes tied to actual releases, including operator actions and
  migration or configuration changes. The [roadmap](../ROADMAP.md) describes
  planned work without promising release dates.
