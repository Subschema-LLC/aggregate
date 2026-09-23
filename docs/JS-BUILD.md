# Optional JavaScript minification

[Tracking](TRACKING.md) · [Data model](DATA-MODEL.md) · [Deployment](../DEPLOYMENT.md)

The readable JavaScript files remain the source of truth. An optional Node build produces smaller tracker, organization-marker, consent, and tag-manager scripts. PHP serves prebuilt files; Node and Terser are needed only where you explicitly run a build.

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
| `public/aggregate.js` | `var/browser/aggregate.template.min.js` and `var/browser/manifest.json` for configured responses |
| `templates/internal_traffic/marker.js.twig` | `public/internal-traffic-marker.min.js` |
| `public/consent.js` and `public/consent.css` | `public/consent.min.js`, `var/browser/consent.template.min.js`, `var/browser/consent-manifest.json` |
| `public/tag-manager.js` | `public/tag-manager.min.js`, `var/browser/tag-manager.template.min.js`, `var/browser/tag-manager-manifest.json` |
| `micro-consent-dropins/js/*.js` | Matching `*.min.js` files alongside each source |

The tracker retains its full BSD-3-Clause license notice. Other generated first-party scripts include an AGPL-3.0-only notice; existing license and preservation comments are retained. Keep the corresponding source and license files when distributing these assets.

## Select the configured tracker

For installations using the supplied server routing, add `min=1` to the existing tracker URL:

```html
<script src="https://analytics.example.com/aggregate.js?min=1" async referrerpolicy="no-referrer"></script>
```

Use `&min=1` if the URL already has query parameters. The endpoint injects the current public namespace, organization-marker settings, query mappings, and consent-free property list into the minified template. YAML/UI changes apply without rebuilding. Private configuration and sharing tokens are never included.

The server verifies SHA-256 hashes for both source and compiled template. Missing, stale, incomplete, or corrupt build files fall back to the current configured source. The response header `X-Aggregate-Script` reports `minified` or `source`; both variants revalidate on each page load. Deploy the private `var/browser` files together, and keep that directory outside the web root.

`public/aggregate.min.js` is the standalone static variant. It uses source defaults and does not receive server YAML injection. When hosting it on a static server or CDN, provide matching inline `window.Aggregate` settings as described in [Tracking](TRACKING.md#utm-and-custom-data-collection). Rebuild and refresh the CDN copy after source changes.

## Marker pages and consent drop-ins

The **Setup** page copies or downloads configured installation snippets, the
self-contained consent UI, and a tag-manager loader. Use
`/cmp-lite/sites/<site-id>/consent.js?min=1` and
`/tms-lite/sites/<site-id>/lib.js?min=1` for hosted scripts. These routes have no
static files, so the front controller supplies current per-site settings. Both
endpoints verify build hashes and fall back to current source. The consent
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

The older experimental files in `micro-consent-dropins/` remain separate from the
new self-contained CMP. Customize those sources before building as described in
[their guide](../micro-consent-dropins/README.md). In particular, replace
`YOUR_FORM_ID` in `consent-ui.js`. `gtm-consent-mode.js` is empty; its generated
file contains only a license banner and provides no consent integration behavior.

## Verify generated assets

```bash
npm run check:js
node tests/JavaScript/javascript-build.test.js
AGGREGATE_SDK_SOURCE=public/aggregate.min.js node tests/JavaScript/aggregate-consent.test.js
AGGREGATE_MARKER_SOURCE=public/internal-traffic-marker.min.js node tests/JavaScript/internal-traffic-marker.test.js
```

`make check-js` is equivalent to `npm run check:js`. The check rebuilds in memory and fails if an expected output is missing or differs from current source. The SDK and marker commands run the same behavioral tests against the minified files. PHP's `ScriptControllerTest` covers current public-setting injection and fallback for invalid or stale builds.
