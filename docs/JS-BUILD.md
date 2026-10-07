# Optional JavaScript minification

[Tracking](TRACKING.md) · [Data model](DATA-MODEL.md) · [Deployment](../DEPLOYMENT.md)

The readable JavaScript files remain the source of truth. An optional Node build produces smaller tracker, organization-marker, consent, and tag-manager scripts. PHP serves prebuilt files; Node and Terser are needed only where you explicitly run a build. [Page speed](PAGE-SPEED.md) lists every saver, including web server compression.

UI pages and reusable components use Symfony's standard Stimulus and AssetMapper
integration, described in the [template guide](../templates/README.md). Page
controllers live under `assets/controllers/pages/` and component controllers
under `assets/controllers/components/`, with CSS in the matching
`assets/styles/pages/` and `assets/styles/components/` directories. Their
JavaScript and CSS are loaded through `importmap('app')` and compiled with
`asset-map:compile`; this optional browser-script minifier does not process UI
controllers. Turbo Drive remains disabled; UI forms use normal page navigation.

## Build

Use Node.js 18 or newer. Install the development dependency and build:

```bash
npm ci --ignore-scripts
npm run build:js
```

Administrators can also select **Setup → Install scripts → Build browser scripts**
or run `php bin/console app:assets:build-js`, including with the dashboard disabled.
These use the same builder, require Node and the pinned dependency on that host,
and never install dependencies themselves. The web action requires administrator
access and a valid CSRF token. Failed builds leave readable scripts available.
Prepared release installations can use their included builds without Node; to
rebuild elsewhere, copy the generated files together as described below.

The build process needs write access to the generated paths below. On deployments
where PHP cannot write browser assets, run the CLI as the deployment user or
build elsewhere. Keep application source and the front controller read-only.

Terser is pinned to an exact version in [`package.json`](../package.json), with resolved dependencies in [`package-lock.json`](../package-lock.json). The builder reads that pin and rejects a different installed version, so dependency updates do not require a separate version change in the build script. It also accepts the pinned version installed globally on `PATH`, so an existing installation can run `node scripts/build-js.cjs` without a local dependency install. `make build-js` runs the same builder.

Rebuild generated files after editing source or pulling updates, and copy them into your deployment if builds run elsewhere:

| Source | Generated output |
| --- | --- |
| `public/aggregate.js` | `public/aggregate.min.js` with static defaults |
| `public/aggregate.js` | `var/browser/aggregate.template.min.js`, `var/browser/aggregate-without-page-depth.template.min.js`, `var/browser/aggregate-strict.template.min.js` and `var/browser/manifest.json` for configured responses |
| `templates/internal_traffic/marker.js.twig` | `public/internal-traffic-marker.min.js` |
| `public/consent.js` and `public/consent.css` | `public/consent.min.js`, `var/browser/consent.template.min.js`, `var/browser/consent-manifest.json` |
| `public/tag-manager.js` | `public/tag-manager.min.js`, `var/browser/tag-manager.template.min.js`, `var/browser/tag-manager-manifest.json` |
| `micro-consent-dropins/js/consent-ui.js`, `micro-consent-dropins/js/aggregate-consent.js`, and `micro-consent-dropins/css/consent-ui.css` | `var/browser/standalone-consent.template.min.js`, `var/browser/standalone-consent-manifest.json` for configured standalone responses/downloads |
| `micro-consent-dropins/js/*.js` | Matching `*.min.js` files alongside each source; independent core and optional adapters |

Every tracker build gives the tracker's internal functions and state short names; names that pages, payloads or other scripts use are kept, and the build checks each name before shortening it. Besides the full tracker, the build makes two smaller ones for settings that turn features off: without page depth, and for the strict profile (see [Leave out unused tracker features](PAGE-SPEED.md#leave-out-unused-tracker-features)). `public/aggregate.js` marks that code with two build switches, `withPageDepth` and `withStandardProfile`; the build and the server's compactor remove the code a switch guards.

The tracker retains its full BSD-3-Clause license notice. Other generated first-party scripts include an AGPL-3.0-only notice; existing license and preservation comments are retained. Keep the corresponding source and license files when distributing these assets.

## Select the configured tracker

For installations using the supplied server routing, add `min=1` to the existing tracker URL:

```html
<script src="https://analytics.example.com/aggregate.js?min=1" async referrerpolicy="no-referrer"></script>
```

Use `&min=1` if the URL already has query parameters. The endpoint injects the current public namespace, organization-marker settings, query mappings, and consent-free property list into the minified template. YAML/UI changes apply without rebuilding. Private configuration and sharing tokens are never included.

The server verifies SHA-256 hashes for both source and compiled template. When the build files are missing, stale, incomplete or corrupt, as on a server updated from Git without Node, the server compacts the current source itself instead: it parses the script with the PHP JavaScript parser it already uses for custom tags and prints it again without comments or formatting, keeping names, braces and the license notice. Compacted scripts are close to the Terser build once compressed (see below) and are cached under `var/cache/<environment>/browser-scripts`, so this happens once per release. Source the parser cannot read is served as it is. The response header `X-Aggregate-Script` reports `minified`, `compact` or `source`. Deploy the private `var/browser` files together, and keep that directory outside the web root.

| Script | Source, gzip | Compacted on the server, gzip | Terser build, gzip |
| --- | --- | --- | --- |
| Tracker (full build) | 14.1 KB | 9.1 KB | 7.6 KB |
| Tag manager | 7.7 KB | 6.0 KB | 4.8 KB |
| Consent banner | 4.3 KB | 3.4 KB | 2.9 KB |

Sizes are in KB of 1,024 bytes at gzip level 6. The smaller tracker builds and
Brotli sizes are listed under [Page speed](PAGE-SPEED.md#what-visitors-download).

### Browser caching

The configured tracker, tag manager and consent scripts are sent with
`Cache-Control: public, max-age=300` and an `ETag`. A browser, or a CDN in front
of Aggregate, reuses a script for five minutes without asking. After that, an
unchanged script is confirmed with a `304 Not Modified` reply of a few hundred
bytes instead of being downloaded again. Saved changes, such as a new tag or new
banner wording, therefore reach a returning visitor within five minutes and a new
visitor at once. Collection rules such as the kill switch, excluded paths and the
data model are also enforced by the server, so they apply to every event
immediately, whatever copy of the tracker a browser holds. Error responses are
never cached.

`public/aggregate.min.js` is the standalone static variant. It uses source defaults and does not receive server YAML injection. When hosting it on a static server or CDN, provide matching inline `window.Aggregate` settings as described in [Tracking](TRACKING.md#utm-and-custom-data-collection). Rebuild and refresh the CDN copy after source changes.

## Marker pages and consent drop-ins

The **Setup** page copies or downloads configured installation snippets, the
self-contained consent UI, and a tag-manager loader. Use
`/cmp-lite/sites/<site-id>/consent.js?min=1` and
`/tms-lite/sites/<site-id>/lib.js?min=1` for hosted scripts. These routes have no
static files, so the front controller supplies current per-site settings. Both
endpoints verify build hashes and otherwise compact the current source. The consent
endpoint injects the website name, categories, instance ID, and tracker namespace;
the tag endpoint injects current enabled tags and their variable definitions.
Saving YAML does not require rebuilding. Static `consent.min.js` and
`tag-manager.min.js` use source defaults; prefer the configured endpoints or the
UI downloads. See [setup](SETUP.md) and [tag manager](TAG-MANAGER.md).

Consent styles are maintained in [`public/consent.css`](../public/consent.css).
Configured CMP responses and downloads bundle this CSS into the script so a
single-file installation remains portable. Static `consent.js` and
`consent.min.js` load the adjacent `consent.css`; copy that stylesheet alongside
either script when hosting it elsewhere. The consent build manifest hashes the
stylesheet as well as the JavaScript. A CSS change makes an old build fall back
to current source and styles until the next build.

The optional shared configuration uses `/consent-manager.js?min=1` and
`/lib.js?min=1`. A single build supplies templates for every website; saving
per-site YAML does not require generating separate static files.

The marker source is the plain JavaScript in `templates/internal_traffic/marker.js.twig`. The generated `public/internal-traffic-marker.min.js` can replace that script in a custom hosted marker page containing the existing marker controls and `internal-traffic-config` JSON element. It must run after that markup is available. Built-in marker pages and downloaded HTML continue to embed the current source, keeping downloads self-contained and avoiding stale compiled code.

Marker page styles live in
[`assets/styles/internal-traffic.css`](../assets/styles/internal-traffic.css).
Hosted marker pages link the AssetMapper stylesheet; downloaded HTML bundles the
same CSS source. Dashboard styles also live under `assets/styles/`. Run
`php bin/console asset-map:compile` when preparing production assets after CSS
changes; prepared release ZIPs already include compiled assets.

The maintained independent option in `micro-consent-dropins/` has its own UI,
stylesheet and optional adapters. Configure it with `window.MicroConsentConfig`;
there is no source-code Formspree placeholder to replace. Its static
`consent-ui.js` / `.min.js` loads `../css/consent-ui.css`; preserve that relative
layout when hosting it elsewhere. The optional `aggregate-consent.js` connects
the tracker/tag manager, while `gtm-consent-mode.js` only sends Google consent
signals and never loads Google. Load that signal adapter synchronously before
any separately installed Google/GTM loader.

The configured `/standalone-cmp/sites/<site-id>/consent.js?min=1` response and
Setup download bundle the independent UI, CSS and Aggregate adapter. Their
separate manifest hashes the core, adapter, stylesheet and compiled template.
Missing, stale or corrupt builds fall back to current configured source; a
per-site YAML edit does not require rebuilding. Deploy both private build files
together, retain the readable sources, and download static snapshots again after
changing site settings. The original built-in CMP keeps its separate files and
behavior. See the [standalone guide](../micro-consent-dropins/README.md) and
[regional examples](CONSENT-REGIONS.md).

## Verify generated assets

```bash
npm run check:js
node tests/JavaScript/javascript-build.test.js
node tests/JavaScript/tracker-builds.test.js
AGGREGATE_SDK_SOURCE=public/aggregate.min.js node tests/JavaScript/aggregate-consent.test.js
AGGREGATE_SDK_SOURCE=public/aggregate.min.js node tests/JavaScript/aggregate-org-traffic.test.js
AGGREGATE_MARKER_SOURCE=public/internal-traffic-marker.min.js node tests/JavaScript/internal-traffic-marker.test.js
```

`make check-js` is equivalent to `npm run check:js`. The check rebuilds in memory and fails if an expected output is missing or differs from current source. The SDK and marker commands run the same behavioral tests against the minified files. `tracker-builds.test.js` runs one scripted visit through every tracker build and compares everything a page could observe with the readable source; PHP's `TrackerBuildsCompactTest` runs it against the builds the server compacts. PHP's `ScriptControllerTest` covers current public-setting injection, build selection and fallback for invalid or stale builds.
