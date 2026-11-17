---
files:
  - docs/specs/CMP-Lite-Updates
  - docs/CONSENT-MANAGER.md
  - public/consent.js
  - src/Service/SiteScriptConfig.php
  - src/Controller/ConsentManagerController.php
  - tests/JavaScript/consent-manager.test.js
  - docs/TAG-MANAGER.md
  - docs/PRIVACY-COMPLIANCE.md
  - docs/CONSENT-REGIONS.md
  - docs/DESIGN-DECISIONS.md
  - docs/EMAIL.md
---
# Consent manager lite updates from the merged CMP Lite spec

## Problem
Consent manager lite (public/consent.js, docs/CONSENT-MANAGER.md) does not implement Google Consent Mode, detect a visitor's region, give testers one consent state object to read, or report scripts and cookies that slip past it. The spec docs/specs/CMP-Lite-Updates, merged in PR #486 with no code yet, lists failures on two 2026 client sites, Fortis and DRYMedic. Examples: a US grant hidden in a GTM template, a GPC visitor tracked on the first visit, a dead Do Not Sell link, and a consent update that never reached gtag. Site operators and their testers need these failures to be impossible by design, not fixed with a workaround for each client.

Visitors also have no way to see which trackers and cookies a site uses, or to send a data request (such as a CPRA request), from the banner. Operators who do not run Aggregate cannot use the banner at all.

## For
Website operators who use Consent manager lite with or without a tag manager, or as a standalone script without an Aggregate server, and the people who test their consent setup.

## Done when
Each acceptance case below is an automated test that passes. T1 to T18 are the cases in docs/specs/CMP-Lite-Updates. Each slice is done when its own cases pass.

- **Slice 1, client core:** T1 to T14 and T17, with the region forced by test mode.
  - No consent default reads granted. With the consent script blocked or its config failing, no gated script runs and no grant is pushed (T11, T12).
  - One page-level state object shows region, mode, source, categories, signals and the saved choice. Each consent event fires at most once per page load, even with two copies of the script on the page (T17).
  - Any element with data-cmp-optout opens the opt-out without a magic id or a dashboard switch, and a warning is logged when an implied region's page has no such element.
  - The Google Consent Mode adapter pushes a denied default and then updates using the gtag array form. The result can be read back from dataLayer.
  - A tester can force any case with a test mode in one browser and get a printed report. The browser traps listed in the spec are in the tester guide.
- **Slice 2, server region:** T18 to T21.
  - T18: a region override on the URL wins over the detected location.
  - T19 and T20: when the location cannot be detected (no trusted header, no local MMDB, or the address is not in it), the strictest region applies and the region selector is shown.
  - T21: a detected location that no region lists gets the strictest region and no selector. State shows that location and the strictest region's mode with source default. Only cmp_ready fires, the gated script does not run, and Consent Mode stays denied with no update.
  - A Sec-GPC request header is treated like navigator.globalPrivacyControl.
- **Slice 3, reports:** T15 and T16, after the storage design is approved (see Constraints).
- **Slice 4, banner layouts, details and standalone:**
  - The banner layout is card, bar or modal. The bar sits at the top (default) or the bottom. A region can set its own layout.
  - In the modal, the page behind cannot be reached by keyboard, screen reader or tap. Esc does exactly what the close button does and never grants.
  - A visitor opted out by GPC, or returning with a saved choice, never gets a modal takeover.
  - A trackers and cookies view lists the declaration grouped by category, attributed to the site's own domain, and shows whether each category is allowed or blocked now. The status changes after a choice without a reload.
  - A data request view offers one of two options, set per site: an embed (an iframe URL or a template already on the page) or a built-in form sent through the transactional mailer to one operator address set in YAML. The form is not offered when email is not configured.
  - Submitting a do-not-sell or do-not-share request also opts the visitor out: cmp_change and cmp_optout fire and the denied update is pushed.
  - In standalone mode, consent.js runs from configuration on the page with no Aggregate server and makes no requests to one. It runs the same grant sequence, with the location treated as not detected (T19, T20 behavior). Missing or invalid configuration fires cmp_failed and grants nothing. Server features (Sec-GPC, reports, the built-in form) are unavailable, and the docs say so.
- **All slices:** existing YAML for consent_manager, the existing AggregateConsent integration and its tests in tests/JavaScript/consent-manager.test.js keep working, or a documented migration covers them.

## Constraints
- Start from docs/specs/CMP-Lite-Updates (PR #486). Do not write a new spec. JOIN-64 is the only thread for this work; JOIN-63 closes once the spec is saved.
- New settings live in YAML in the consent_manager mapping of config/tag-manager/sites/<site-id>.yaml. Each must also be changeable on the Consent manager lite page and from app:config, using the same validation as SiteScriptConfig, with SettingsParityTest passing. This includes layout, bar position, the per-region layout and the data request options.
- Privacy rules in AGENTS.md apply. Region comes only from request headers or local MMDB lookups and never from an external geolocation service or a guess in the browser. Only an explicit grant counts, outside the implicit_us default-on categories. Invalid config fails closed.
- Collecting cookie names and pre-consent script reports adds server data, while today the banner sends nothing to the server. It needs an approved bounded storage design under the simple-tables rule, and the docs must describe it honestly.
- The built-in data request form is the first time the banner sends a visitor's personal data (an email address, request text) through Aggregate. Aggregate stores nothing about the request beyond the mailer's existing send record without the recipient, never logs the content, sends only to the operator address in YAML (never to an address the visitor typed), and limits the rate of submissions. The docs say what is sent and where.
- The spec's explicit and implied modes must map to the existing explicit_eu, explicit_us and implicit_us consent types and the respect_gpc setting. Do not add a second vocabulary.
- Keep dependencies modest. Standalone mode is the same consent.js, not a second banner.
- New code goes in the FIDES zones (docs/FIDES-MIGRATION.md), and php tools/arch-check.php must pass.

## Out of scope
- IAB TCF v2 strings and vendor-list purposes.
- Blocking inline scripts that a theme injects. Lite detects and reports them only.
- Server-side consent enforcement through a proxy.
- Consent logs kept as legal evidence.
- Handling data requests: verifying identity, tracking deadlines or fulfilling them. The banner only delivers the request.
- A multi-language banner copy editor.
- Changes to the standalone MicroConsent banner in micro-consent-dropins.
- In-app analytics reports or charts about consent.
- Later, in ROADMAP.md: the cmp-test headless Chromium CLI, the Microsoft UET and Meta adapters, and the GTM community template.

## Decisions
- JOIN-64, 2026-11-17: region fallback: known location with no rule gets the strictest region and no selector
- JOIN-64, 2026-11-17: all approve for spec JOIN-64
