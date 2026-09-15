# To Do Items

- [x] Add YAML + UI configuration for an internal-traffic cookie or local storage entry for Power BI or Tableau reporting.
  - [x] Configure the marker name and value in YAML and the UI, with buttons to mark or unmark the current browser.
  - [x] Document the default `orgInternalTraffic=true` and how to override and share it with team members.
  - [x] Add a token-protected public page and downloadable marker page, with noindex/nofollow instructions and the token stored in YAML.
  - [x] Store the flag under the configured cookie/local storage name in the existing event JSON (default `orgInternalTraffic: true`), with no migration.
  - [x] Distinguish organization traffic from the site's `internal` referrer category in the UI.
  - [x] Generate a random default sharing token during web, CLI, and shell installation, preserving an existing token.

Filtering uses retained raw-event JSON. Existing grouped BI views and archives do not retain this flag; see the [compliance guide](PRIVACY-COMPLIANCE.md#organization-traffic).

- [x] Add UI and CLI regeneration of custom_data JSON properties as reporting columns.
    - [x] Discover observed keys and types from retained events, with explicit column selection and SQL preview.
    - [x] Add a Data model page and downloadable YAML contract for analytics implementation developers.
    - [x] Use separate private `analytics_custom_*` views to preserve the aggregation, suppression, and archive contracts of existing BI views. See the [data model guide](DATA-MODEL.md).

- [x] Add a new feature to be able to pull updates from GitHub and show that an update is available
    - [x] Use public [Subschema-LLC/aggregate](https://github.com/Subschema-LLC/aggregate) as the official source, with YAML `updates_branch` defaulting to `master`.
    - [x] Add an admin Updates page with cached status and a manual refresh, plus `app:updates:check` for headless use.
    - [x] Add `app:updates:pull` for clean, fast-forward source updates, preserving local configuration and reporting the remaining [deployment steps](../DEPLOYMENT.md#updates).

- [x] Add standard UTM handling and YAML/UI query-parameter mappings, including multiple parameters mapped to one custom JSON key.
- [x] Add YAML/UI per-property whitelisting for anonymous collection. Recommend no more granular than `utm_medium`; allow overrides with UI and documented warnings.
- [x] Add optional minification for the tracking library, organization marker, and drop-in scripts, with configured tracker delivery and stale-build fallback. See [JavaScript build](JS-BUILD.md).

- [x] Prepare signed GitHub Release packages and updates without a Git checkout.
    - [x] Build versioned production ZIPs with dependencies, compiled assets, and embedded version metadata.
    - [x] Verify release-tag ancestry against the publishing branch in YAML (default `master`), and create draft GitHub Releases through CI.
    - [x] Generate Ed25519 signing keys, sign manifests, and verify package signatures/checksums/compatibility through `app:updates:verify-package`.
    - [x] Discover stable release updates for ZIP installations in the dashboard and CLI. See [release setup and publishing](RELEASES.md).
- [ ] Add automatic package application: resumable update job, maintenance/worker coordination, configuration/data preservation, migrations, activation, health checks, and separate file/database recovery. Release packaging and verification are ready as its foundation.
- [ ] Add website-specific configuration and data model settings
