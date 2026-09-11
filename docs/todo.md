# To Do Items

- [x] Add YAML + UI configuration for an internal-traffic cookie or local storage entry for Power BI or Tableau reporting.
  - [x] Configure the marker name and value in YAML and the UI, with buttons to mark or unmark the current browser.
  - [x] Document the default `orgInternalTraffic=true` and how to override and share it with team members.
  - [x] Add a token-protected public page and downloadable marker page, with noindex/nofollow instructions and the token stored in YAML.
  - [x] Store the flag under the configured cookie/local storage name in the existing event JSON (default `orgInternalTraffic: true`), with no migration.
  - [x] Distinguish organization traffic from the site's `internal` referrer category in the UI.
  - [x] Generate a random default sharing token during web, CLI, and shell installation, preserving an existing token.

Filtering uses retained raw-event JSON. Existing grouped BI views and archives do not retain this flag; see [README](../README.md#organization-traffic).

- [ ] Add a UI and CLI command to add custom_data JSON key value pairs as columns in existing relevant views
    - [ ] There should be a UI section where users can regenerate views to add new custom data columns based on what properties are showing up in the events table.
    - [ ] If there is a better way like to have a data model section where users can define their data model and share it with analytics implementation devs and this is used for view regeneration, then let's explore that.
