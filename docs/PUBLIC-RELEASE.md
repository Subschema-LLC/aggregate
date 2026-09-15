# Preparing the public repository

[Release packaging](RELEASES.md) · [Contribution guide](../CONTRIBUTING.md) · [Roadmap](../ROADMAP.md)

These are maintainer actions to complete before changing repository visibility or
publishing the first signed package. Repository files cannot enable GitHub account
settings or establish that a reporting inbox is monitored.

## Repository and reporting setup

- Verify that the private reporting inbox listed in [SECURITY.md](../SECURITY.md)
  and [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md) is monitored and receives mail.
  Keep those policies current if the security and conduct contacts change.
- Enable GitHub private vulnerability reporting as an additional private channel,
  and enable secret scanning and push protection where available. Review findings
  before changing visibility.
- Run [CI](../.github/workflows/ci.yml) on GitHub, then require its final **CI** check
  on `development`, `uat`, and `master`, and require pull requests for changes.
  Protect the branches against accidental force pushes and deletion. CI covers
  PHP 8.2/8.3, browser assets, and release tooling; it uses disposable fixtures
  and does not prove every supported database engine.
- Verify the issue forms, PR template, repository description, license notices,
  and funding link once the public interface is available. Contributions target
  `development`; maintainer promotion PRs go from `development` to `uat` for user
  acceptance testing, then from `uat` to `master`. See the
  [branching strategy](../CONTRIBUTING.md#branching-strategy). Tagged production
  releases come from `master` by default.

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

If history must be scrubbed, first commit the current cleanup and pause merges.
Back up all refs, then use `git filter-repo` in a separate fresh mirror to remove
the agreed paths from every branch and tag. Inspect the resulting trees and ref
changes, rerun tests from a fresh checkout, and coordinate the replacement with
every contributor and deployment before pushing it. Commit IDs and affected tags
will change; signed tags need re-signing. Force-pushing and GitHub-side cleanup of
cached refs require a separate, deliberate maintenance step. Keep the original
backup private and use fresh checkouts afterward.

## Package publication

Follow the [signing-key setup](RELEASES.md#one-time-maintainer-setup): commit only
the public key and put the matching private key in the GitHub Actions secret.
Use stable tags in `vX.Y.Z` form. Review the draft release and its verified package
before publishing; the workflow does not make the repository public automatically.

## Follow-up improvements

- Configure dependency-update PRs for Composer, npm, and GitHub Actions; review
  updates through CI. Consider pinning third-party Actions to immutable commits.
- Add disposable database integration jobs for the engines the project supports,
  particularly migrations, reporting views, and transactional behavior.
- Extend the PHP matrix as newer runtimes are verified, and test a first-time
  installation and an upgrade from a published release.
- Keep release notes tied to actual releases, including operator actions and
  migration or configuration changes. The [roadmap](../ROADMAP.md) describes
  planned work without promising release dates.
