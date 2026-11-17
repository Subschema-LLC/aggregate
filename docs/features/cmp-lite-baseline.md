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
---
# Consent manager lite updates from the merged CMP Lite spec

## Problem
Consent manager lite (public/consent.js, docs/CONSENT-MANAGER.md) does not implement Google Consent Mode, detect a visitor's region, give testers one consent state object to read, or report scripts and cookies that slip past it. The spec docs/specs/CMP-Lite-Updates, merged in PR #486 with no code yet, lists failures on two 2026 client sites, Fortis and DRYMedic. Examples: a US grant hidden in a GTM template, a GPC visitor tracked on the first visit, a dead Do Not Sell link, and a consent update that never reached gtag. Site operators and their testers need these failures to be impossible by design, not fixed with a workaround for each client.

## For
Website operators who use Consent manager lite with or without a tag manager, and the people who test their consent setup.

## Done when
- Each acceptance case T1 to T18 in docs/specs/CMP-Lite-Updates is an automated test that passes.
- No consent default reads granted. With the consent script blocked or its config failing, no gated script runs and no grant is pushed (T11, T12).
- One page-level state object shows region, mode, source, categories, signals and the saved choice. Each consent event fires at most once per page load, even with two copies of the script on the page (T17).
- Any element with data-cmp-optout opens the opt-out without a magic id or a dashboard switch, and a warning is logged when an implied region's page has no such element.
- The Google Consent Mode adapter pushes a denied default and then updates using the gtag array form. The result can be read back from dataLayer.
- A tester can force any case with a test mode in one browser and get a printed report. The browser traps listed in the spec are in the tester guide.
- Existing YAML for consent_manager, the existing AggregateConsent integration and its tests in tests/JavaScript/consent-manager.test.js keep working, or a documented migration covers them.

## Constraints
- Start from docs/specs/CMP-Lite-Updates (PR #486). Do not write a new spec, and check overlap with the open thread JOIN-63 (cmp lite updates) before splitting work.
- New settings live in YAML in the consent_manager mapping of config/tag-manager/sites/<site-id>.yaml. Each must also be changeable on the Consent manager lite page and from app:config, using the same validation as SiteScriptConfig.
- Privacy rules in AGENTS.md apply. Region comes only from request headers or local MMDB lookups and never from an external geolocation service. Only an explicit grant counts, outside the implicit_us default-on categories. Invalid config fails closed.
- Collecting cookie names and pre-consent script reports adds server data, while today the banner sends nothing to the server. It needs an approved bounded storage design under the simple-tables rule, and the docs must describe it honestly.
- The spec's explicit and implied modes must map to the existing explicit_eu, explicit_us and implicit_us consent types and the respect_gpc setting. Do not add a second vocabulary.
- Keep dependencies modest. The spec's headless Chromium runner for cmp-test must not become a requirement for installing a release ZIP.
- New code goes in the FIDES zones (docs/FIDES-MIGRATION.md), and php tools/arch-check.php must pass.

## Out of scope
- IAB TCF v2 strings and vendor-list purposes.
- Blocking inline scripts that a theme injects. Lite detects and reports them only.
- Server-side consent enforcement through a proxy.
- Consent logs kept as legal evidence.
- A multi-language banner copy editor.
- Changes to the standalone MicroConsent banner in micro-consent-dropins.
- In-app analytics reports or charts about consent.

## Decisions
- JOIN-64, 2026-11-17: region fallback: known location with no rule gets the strictest region and no selector
