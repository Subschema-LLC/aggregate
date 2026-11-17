<!--
Read CONTRIBUTING.md before opening a PR. Create working branches from development
and target development. Maintainer promotion PRs go development -> uat -> master.
Use synthetic examples and redact credentials, tokens, raw analytics events,
personal data, and screenshots.
For suspected vulnerabilities, follow SECURITY.md before opening a public PR.
-->

## Problem and resulting behavior

<!-- Explain the concrete problem and what this change does. Link related issues. -->

## Validation

<!-- List relevant checks and results, including checks you could not run.
For SQL/migrations, name the database engines actually exercised.
For UI changes, describe how you checked the interaction and keyboard use. -->

## Code structure (FIDES)

<!-- Name the zones and folders this change adds or touches, and the older area
it ports, if any. A port changes structure, not behavior: list every intended
difference. If the change touches more than its feature folder, the entities it
names, one integration and the wiring, explain why.
See docs/FIDES-MIGRATION.md. -->

## Privacy and operational impact

<!-- Include only the points that apply; write "None" if none apply.
- Collection/reporting: what reaches the browser payload, queue, event row, and
  reporting views, including anonymous mode and withdrawn consent.
- Configuration/data model: YAML/headless support and changed defaults.
- Optional/beta capabilities: consider a feature flag; describe its default,
  navigation visibility, server enforcement, and disabling/recovery behavior.
  See docs/FEATURE-FLAGS.md for registration and testing guidance.
- Deployment: migrations, compatibility, or operator action required.
- Documentation: updated guides or examples.
-->

- [ ] New PHP code is in the FIDES zones, the area this change touches is ported (or an issue is linked), and `php tools/arch-check.php` passes without new baseline entries.
- [ ] I reviewed the complete diff for secrets, personal data, and unintended or generated files.
- [ ] I preserved the project's license notices and considered any added dependencies' licenses.
- [ ] Each commit is signed off (`git commit -s`) under the [Developer Certificate of Origin](https://github.com/Subschema-LLC/aggregate/blob/development/CONTRIBUTING.md#sign-off-your-commits-dco).
