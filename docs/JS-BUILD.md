# Optional JavaScript minification

[Tracking](TRACKING.md) · [Data model](DATA-MODEL.md) · [Deployment](../DEPLOYMENT.md)

The readable JavaScript files remain the source of truth. An optional Node build produces smaller tracker, organization-marker, and consent drop-in scripts. PHP serves prebuilt files; Node and Terser are needed only on the build machine.

## Build

Use Node.js 18 or newer. Install the development dependency and build:

```bash
npm ci --ignore-scripts
npm run build:js
```

Terser is pinned to an exact version in [`package.json`](../package.json), with resolved dependencies in [`package-lock.json`](../package-lock.json). The builder reads that pin and rejects a different installed version, so dependency updates do not require a separate version change in the build script. It also accepts the pinned version installed globally on `PATH`, so an existing installation can run `node scripts/build-js.cjs` without a local dependency install. `make build-js` runs the same builder.

Generated files are ignored by Git. Rebuild after editing source or pulling updates, and copy the generated assets into your deployment if builds run elsewhere:

| Source | Generated output |
| --- | --- |
| `public/aggregate.js` | `public/aggregate.min.js` with static defaults |
| `public/aggregate.js` | `var/browser/aggregate.template.min.js` and `var/browser/manifest.json` for configured responses |
| `templates/internal_traffic/marker.js.twig` | `public/internal-traffic-marker.min.js` |
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

The marker source is the plain JavaScript in `templates/internal_traffic/marker.js.twig`. The generated `public/internal-traffic-marker.min.js` can replace that script in a custom hosted marker page containing the existing marker controls and `internal-traffic-config` JSON element. It must run after that markup is available. Built-in marker pages and downloaded HTML continue to embed the current source, keeping downloads self-contained and avoiding stale compiled code.

For consent drop-ins, customize the readable source first, then build and use the corresponding `.min.js` file in the same load order documented in [the drop-in guide](../micro-consent-dropins/README.md). In particular, replace `YOUR_FORM_ID` in `consent-ui.js` before building. `gtm-consent-mode.js` is currently empty; its generated file contains only a license banner and provides no consent integration behavior.

## Verify generated assets

```bash
npm run check:js
node tests/JavaScript/javascript-build.test.js
AGGREGATE_SDK_SOURCE=public/aggregate.min.js node tests/JavaScript/aggregate-consent.test.js
AGGREGATE_MARKER_SOURCE=public/internal-traffic-marker.min.js node tests/JavaScript/internal-traffic-marker.test.js
```

`make check-js` is equivalent to `npm run check:js`. The check rebuilds in memory and fails if an expected output is missing or differs from current source. The SDK and marker commands run the same behavioral tests against the minified files. PHP's `ScriptControllerTest` covers current public-setting injection and fallback for invalid or stale builds.
