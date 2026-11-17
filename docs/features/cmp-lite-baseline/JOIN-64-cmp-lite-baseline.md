# CMP Lite updates: use docs/specs/CMP-Lite-Updates (merged in PR #486) as the starting spec

_Draft for JOIN-64, written by the team in Slack: each section names who wrote it. JOIN keeps it until an approver saves it to the repository._

## Product Owner

The spec in docs/specs/CMP-Lite-Updates (PR #486, no code yet) describes a new CMP, but public/consent.js already does part of it. It has script gating (`script[type=text/plain][data-consent-tag]`), GPC, the implicit_us default-on grants, stored choices, a dataLayer push (`aggregate_consent_update`) and per-region banners. What it lacks: it picks the region from the visitor's own selection or a default instead of detecting it, it never pushes a Consent Mode default or update, it has no state object, no cmp_* events and no test mode. I propose scoping JOIN-64 as a delta on consent.js in three slices, and parking the parts that create server-side data until there is a storage decision.

**Approach**
- Slice 1, client core (in scope): add the state object, the five events with a once-per-page guard, the fixed grant order, the Google Consent Mode adapter (array form), data-cmp-optout with a warning when an implicit_us page has none, and test mode with a printed report. Files: public/consent.js, consent.min.js, tests/JavaScript/consent-manager.test.js. Acceptance: T1–T14, T17 and T18 pass as Node tests in the existing test file. T11 is defined as 'no grant and no gated script', and the inline default is an optional snippet that is documented.
- Map the spec's terms onto ours instead of adding a second vocabulary: explicit means explicit_eu/explicit_us, implied means implicit_us, the GPC opt-out follows respect_gpc. The spec's four categories map onto the existing configurable `categories` (analytics plus custom). AggregateConsent and aggregate_consent_update stay as aliases with a migration note in docs/CONSENT-MANAGER.md.
- Slice 2, server region: DropInScripts.php already writes the config into the script it serves. Add the region from a trusted header or the local MMDB, plus Sec-GPC, through SiteScriptConfig.php. The setting goes in YAML, on the Consent manager lite page (ConsentManagerController.php) and in app:config, with SettingsParityTest covering it. Docs: docs/CONSENT-REGIONS.md.
- Slice 3, observation (deferred until an approved storage design exists): posting cookie names and pre-consent scripts, the vendor table, the rendered declaration, and T15/T16. This is the first time the banner sends anything to the server, so it needs its own decision under the simple-tables rule and processing_tasks.
- Out of scope for JOIN-64 (to be added to the anchor): the Microsoft/Meta adapters, the GTM community template (it lives outside this repo), and the headless Chromium `cmp-test` CLI. These go in ROADMAP.md.
- Revise the anchor's 'Done when' list so it names the T cases per slice instead of 'T1 to T18'.

**Risks**
- Overriding the region with `?cmp_region` lets anyone in a browser switch a page from explicit to implied, which grants consent. That conflicts with 'browser overrides cannot expand server permissions'. Allow it only in test mode, and only towards a stricter region, unless the operator enables it in YAML.
- A per-request region and Sec-GPC make the served consent.js vary by visitor. CDN or proxy caching could then serve one visitor's region to another, so the response needs Vary or no-store headers, or a separate small config fetch.
- The spec stores a GPC opt-out as a saved choice. Today GPC only withholds defaults and stores nothing, so storing it changes behaviour for return visitors and needs a DESIGN-DECISIONS.md entry.
- Deleting cookies on opt-out relies on a declaration that slice 3 builds. Until then T10 can only run the onOptOut hooks.
- Renaming events or the global breaks existing GTM triggers on aggregate_consent_update.

**Open questions**
- Does JOIN-63 (cmp lite updates) cover the same spec? If so, which thread keeps it, and should the other close?
- Should the old names (AggregateConsent, aggregate_consent_update) stay forever as aliases, or be removed after one release?
- Should the Fortis/DRYMedic names stay in a public AGPL spec, or be anonymised?
- Is slice 3 (server-side collection of cookies and scripts) wanted in Lite at all, given the 'banner sends nothing' privacy position in docs/PRIVACY-COMPLIANCE.md?

_By -Product Owner-._

## UX/UI Designer

The Product Owner covered the behaviour. I add the screens, wording and accessibility the spec implies, and I name three conflicts that need decisions. (1) The spec's "blocking until a choice" banner collides with today's non-modal one. (2) Detected region collides with the visitor's region dropdown in public/consent.js. (3) Opt-out has no screen yet. I rely on docs/CONSENT-MANAGER.md, public/consent.js (role=dialog at line 530, Escape at line 619, focus only in open()), templates/consent_manager/index.html.twig and docs/specs/CMP-Lite-Updates.

**Approach**
- Banner states, each with its own wording and focus rule. Explicit first visit. Implied first visit: a non-blocking bar that does not take focus, and the existing sessionStorage dismissal stops it returning on every page. GPC opted-out. Saved choice: only the reopen button shows. Config failed (`cmp_failed`): the visitor sees nothing, since nothing is granted and they have nothing to act on. The reason goes to the console and the test report.
- Treat "blocking" as scripts blocked, not a modal overlay. Keep the card or bar as a non-modal dialog. A true modal needs a focus trap, an inert page and a new Escape rule, which is a big accessibility change.
- Opt-out screen, opened by `data-cmp-optout` or `openOptOut()`. It reuses the panel: a heading, one plain sentence on what opting out does, a "Do not sell or share my data" button and a Cancel button. Focus goes to the heading on open. On close, focus returns to the element that opened it, because the reopen button may be hidden. The result is announced through the existing `status` live region.
- New wording keys in the Wording panel, with defaults: `optout_title`, `optout_confirm`, `status_opted_out`, `gpc_notice`. Example `gpc_notice`: "Your browser's Global Privacy Control is on, so optional tracking is off. Use Privacy choices to change this." This gives GPC visitors a visible way back in.
- Region and the dropdown. When region is detected, hide the banner's region dropdown. Today a visitor can pick "United States" in an implicit region and give themselves default-on categories. On the Regional consent rules panel, replace "visitors pick their own region" with a choice: **Detect from location** or **Let visitors choose**. Add an "Applies to these locations" field per region (for example `US-CA, US-TX`), because the region ID pattern `[a-z0-9_-]` cannot hold `US-CA`. An unresolved location gets the strictest region.
- Dashboard changes go into the existing Consent behavior and Regional panels, with no new menu entry (AGENTS.md). Add a Google Consent Mode checkbox, default off. Add a wait-for-update field, default 2000 ms, with its unit and range in the help text. Add a read-only line, "Region detection: header seen / local database / none". Replace the Reopen help text that tells operators to call `AggregateConsent.open()` with a copyable `<a href="#" data-cmp-optout>Do Not Sell or Share My Personal Information</a>` snippet, using Ui:CopyButton.
- Preview: add a radio group for three states (EU first visit, US first visit, GPC opted-out). The preview is `inert`, so the radios are how operators check each wording set.
- Test mode shows a visible "Test mode: <case>" label. The report in `<pre data-cmp-report>` has a heading and a Copy report button. Each row says PASS or FAIL in words, so colour is not the only signal. docs/CONSENT-MANAGER.md gets the browser traps as an "If you use X, do Y" table in plain language.

**Risks**
- A half-built modal banner would trap keyboard users and break Escape-to-hide. It could also fail WCAG 2.2 focus rules.
- A GPC opt-out stored as a saved choice persists after the visitor turns GPC off. Without `gpc_notice` and a visible way back, they stay opted out and can't tell why.
- `data-cmp-optout` bound only on `cmp_ready` misses links added later, such as a lazy-loaded footer or theme menu. Those links silently do nothing, which is the dead-link failure F6 again. Bind by delegation instead.
- The missing-opt-out-link warning goes to the console, which most operators never open. The dashboard snippet and guide are the only visible prompt, so they must be prominent.
- Hiding the dropdown when a region is detected leaves a visitor stuck if detection is wrong, for example behind a VPN. They need a visible way to say so that can only make the region stricter.

**Open questions**
- Is "explicit" a modal overlay that blocks the page, or a non-modal banner that only blocks scripts? I recommend the second.
- Should a visitor be able to move from a detected region to a less strict one? I recommend no. They may only move to a stricter one.
- Should the opt-out link wording follow the region: "Do Not Sell or Share" for CCPA, and neutral wording elsewhere?

_By -UX Designer-._

## To do

_The work this spec plans, as `- [ ] …` items, each naming the role that does it. Whoever finishes an item checks it off in the same commit as the work: `- [x] … (done in JOIN-41)`._
