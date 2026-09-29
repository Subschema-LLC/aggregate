# Feature flags

[Configuration](CONFIGURATION.md) · [Contributing](../CONTRIBUTING.md) · [Roadmap](../ROADMAP.md)

Feature flags control registered capabilities across the deployment. Administrators
manage them at **Feature flags** (`/dashboard/feature-flags`), or operators edit
the active aggregate YAML. Both use the same validation and configuration source.
Website and administrator targeting remain planned.

## Configure a feature

Add this mapping to `config/aggregate.yaml`, its active `environments` entry, or
the environment-specific `config/aggregate_<environment>.yaml` file:

```yaml
feature_flags:
  updates:
    enabled: false
    hide_from_navigation: true
```

The `updates` flag covers the Updates page, Git and release version checks, Git
source pulls, release package verification, and installing updates with
`app:updates:apply` or the dashboard **Install update** button. Disabled commands
fail without contacting GitHub, reading release packages, or changing the
installation. The page, refresh and install endpoints return 404 to an
authenticated administrator when disabled. Existing authorization still applies.

| `enabled` | `hide_from_navigation` | Behavior |
| --- | --- | --- |
| `true` | `false` | Available; navigation links are shown to authorized users. |
| `true` | `true` | Available through direct routes/commands; navigation entries are hidden. |
| `false` | `false` | Unavailable; navigation shows an inactive item without a link. |
| `false` | `true` | Unavailable; navigation entries are hidden. |

Omitted options use registered defaults. Updates defaults to enabled and visible,
preserving installations without a `feature_flags` mapping. Use real YAML booleans
(`true` / `false`); quoted strings, numbers, null values, unknown feature names,
and unknown options are rejected. Invalid flag configuration makes flagged
features unavailable and hides their navigation entries. Correct invalid YAML
before saving in the UI; invalid configuration is not replaced with defaults.

The normal [configuration precedence](CONFIGURATION.md#application-settings)
applies. An environment-specific file takes precedence over `aggregate.yaml`.
Otherwise the active environment's entire `feature_flags` mapping replaces the
shared mapping; individual entries are not inherited across these two mappings.
Omitted entries then use registered defaults. There is no uppercase
environment-variable override for feature flags.

UI saves affect the active environment and preserve unrelated settings. The PHP
process needs write permission to the active YAML file. Synchronize that file
across replicas. Runtime flag changes do not require rebuilding the container.
Long-running consumers must reset/reload configuration between jobs, as Symfony
Messenger does for resettable services. With the dashboard disabled, edit YAML
to manage flags; enabled update commands remain available headlessly.

## Add a feature as a developer

1. Register a stable lowercase snake-case key in
   [`FeatureFlags::DEFINITIONS`](../src/Service/FeatureFlags.php). Supply a label,
   description, and boolean defaults. Start new beta capabilities disabled and
   hidden unless the proposal justifies different defaults:

   ```php
   'example_export' => [
       'label' => 'Example export',
       'description' => 'Download the configured example event structure.',
       'enabled' => false,
       'hide_from_navigation' => true,
   ],
   ```

   This example is not currently registered. The admin UI lists registered
   definitions automatically. Document new defaults in the configuration example
   and the feature's guide.

2. Inject `App\Service\FeatureFlags` into the shared implementation service.
   Call `$features->assertEnabled('example_export')` before network, filesystem,
   database, queue, or other feature work. It throws `RuntimeException` when
   disabled or invalid. CLI commands should report failure and return nonzero.
   Status-returning services may instead use `isEnabled()` to return an explicit
   unavailable state; callers must treat that state as failure.

3. Guard HTTP entry points too. Preserve authorization and dashboard checks,
   then use `isEnabled()` to return 404 when disabled. Keep service enforcement
   so commands, workers, and other callers cannot bypass the flag. Flags never
   grant permissions, replace CSRF checks, weaken consent, or bypass signatures.
   Do not expose the full flag configuration to trackers.

4. Associate **every navigation reference** with the feature in
   `config/navigation.yaml`:

   ```yaml
   - label: 'Example export'
     route: 'app_example_export'
     role: 'ROLE_ADMIN'
     feature: example_export
   ```

   The `feature` property also works with literal `url` entries, brand links,
   and account links. Roles still apply. The shared template handles hiding and
   inactive items. Existing Updates route names and local `/dashboard/updates`
   URLs are also recognized for older navigation configurations. Absolute URLs,
   custom URL prefixes, and all new features must use explicit `feature` metadata.

   Grouped `children` entries support the same metadata on both the group and
   each child. Hidden/inaccessible children are filtered before empty groups
   disappear. The shared `navigation_menu(main_navigation)` helper normalizes
   and filters the complete menu; see [submenu configuration](CONFIGURATION.md#main-navigation).

   Custom navigation templates should call `navigation_feature_state(item)` and
   check its `hidden` / `enabled` values before generating links. Twig also
   provides `feature_enabled(name)` and `feature_hidden_from_navigation(name)`.
   Template checks supplement server enforcement.

5. Define disabling and recovery behavior. Stop new work when disabled and
   provide a deliberate recovery path for interrupted operations. Flags cannot
   undo migrations or stored data. Disabling a rollout must not remove a stricter
   privacy rule or broaden collection. Keep installation and migrations
   consistent regardless of whether a beta capability is enabled.

6. Cover enabled/disabled service behavior, direct HTTP requests, CLI/headless
   use, authorization/CSRF, malformed configuration, preserved settings, and all
   four navigation combinations. Assert that disabled paths perform no feature
   work. Also test the capability's own privacy, data, or operational risks.

Working examples are in
[`UpdatesController`](../src/Controller/UpdatesController.php),
[`ApplicationUpdateService`](../src/Service/ApplicationUpdateService.php), and
[`UpdateFeatureFlagsTest`](../tests/Service/UpdateFeatureFlagsTest.php).

## Propose flags in contributions

Consider a flag for a beta capability, optional external integration, or
operational action that benefits from staged rollout. In the issue or PR, explain
the scope, availability/visibility defaults, affected entry points, headless
configuration, disabling/recovery behavior, and graduation or retirement criteria.
Keep routine fixes and mandatory privacy/security protections effective for everyone.

Discuss website or administrator targeting separately: current flags apply to
the installation and do not provide tenant isolation. Do not introduce visitor
identifiers to target anonymous collection experiments. When retiring a flag,
document removal of its YAML key and navigation references; unknown keys are
rejected, so operators need an explicit configuration migration.
