# Page speed

[Tracking](TRACKING.md) · [JavaScript build](JS-BUILD.md) · [Deployment](../DEPLOYMENT.md)

Aggregate's tracker, and the tag manager and consent banner when you use them,
load on every page you track. These page speed savers keep what visitors
download small and keep it out of the way of your pages. Most work without any
setup; compression is a web server setting, which the supplied configurations
turn on.

**General settings → Page speed** in the dashboard lists each saver with its
status on this installation, the size of the tracker your visitors download,
and whether your web server compresses it.

| Saver | What it saves | How to use it |
| --- | --- | --- |
| [Leave out unused tracker features](#leave-out-unused-tracker-features) | A sixth of the tracker while page depth is off; half of it with the strict profile | On by default. General settings → Page speed, or YAML `tracker_omit_unused_features` |
| [Shorter internal names](#shorter-internal-names) | About 4% more | Included in the build that release packages ship |
| [Minified scripts](#minified-scripts) | Half of each script | Automatic for URLs with `?min=1`, as in the install snippets |
| [Compression](#turn-on-compression) | Two thirds of the remaining bytes | Your web server; the supplied nginx, Apache and FrankenPHP configurations do it |
| [Browser caching](#browser-caching) | The whole download on repeat page views | Automatic: five minutes, then a short `304 Not Modified` |
| [Loads without blocking](#load-scripts-without-blocking) | Time before the page appears | The install snippets use `defer` or `async` |

## What visitors download

The tracker as served with default settings, in kilobytes (1,024 bytes). Gzip
at level 6 and Brotli at quality 5, typical web server settings:

| Tracker | As sent | Gzip | Brotli |
| --- | --- | --- | --- |
| Readable source (`/aggregate.js`) | 52.1 KB | 13.9 KB | 13.2 KB |
| Full build (`?min=1`) | 22.0 KB | 7.5 KB | 7.1 KB |
| Without page depth (`?min=1`, page depth off) | 17.7 KB | 6.2 KB | 5.9 KB |
| Strict profile (`?min=1`, strict profile on) | 9.7 KB | 3.8 KB | 3.6 KB |

Before these savers, the full build was 7.7 KB with gzip. Without a current
build, as on a server updated from Git without Node, the server compacts the
same three trackers itself: 8.9 KB, 7.4 KB and 4.6 KB with gzip. With gzip,
the tag manager is about 4.8 KB before the tags you configure, and the consent
banner's script about 2.9 KB before its styles and text.

## Leave out unused tracker features

The tracker carries code for every feature, but your settings can rule some of
them out for every page. With **Leave out unused tracker features** on, the
configured tracker (`/aggregate.js?min=1`) is the smallest build that behaves
exactly like the full one for the settings it is served with:

- **Without page depth**, while [page depth](DATA-MODEL.md) is off. The served
  settings say so, and a page cannot turn page depth back on. The code that
  removes a count left by an earlier visit stays, so turning page depth off
  still cleans up. About a sixth smaller.
- **Strict profile**, while the [strict collection profile](TRACKING.md#strict-collection-profile)
  is on, which a page cannot relax. It keeps only what a strict page view or
  event needs: no custom data, identifiers, consent storage, organization
  marker, referrer or device code. About half the size.
- **Full**, otherwise.

Nothing a visitor or page can observe changes: the same requests, the same use
of cookies and storage, the same events and warnings. The test suite runs a
scripted visit through every build and compares all of these with the readable
source, for the Terser builds and for the ones the server compacts itself. Only
one thing is absent: a build without page depth adds no click listener for
page-depth links, since it would never act.

The setting is on by default. To send the full tracker to everyone, clear it on
**General settings → Page speed**, set `tracker_omit_unused_features: false` in
`config/aggregate.yaml`, or set the `TRACKER_OMIT_UNUSED_FEATURES` environment
variable, which takes precedence over both. The readable `/aggregate.js` and
static copies such as `public/aggregate.min.js` always keep every feature,
since they cannot know your settings. The `X-Aggregate-Build` response header
names the build sent: `full`, `without-page-depth` or `strict`.

When you turn page depth on or switch from the strict profile, new visitors get
the full tracker at once. Browsers that already hold the smaller one get it
within five minutes, as with any [saved setting](#browser-caching).

## Shorter internal names

The build gives the tracker's internal functions and state short names, in
every build. Names that pages, payloads or other scripts use, such as
`Aggregate.emit` or the settings a page can pass, keep their names; the build
checks each name before shortening it. It comes with the
[Terser build](JS-BUILD.md), which prepared release packages include, or which
**Setup → Install scripts → Build browser scripts** and
`php bin/console app:assets:build-js` create on a host with Node. A server that
compacts scripts itself keeps the names.

## Minified scripts

URLs with `?min=1`, which the install snippets use, receive the Terser build
when it matches the current source. Otherwise the server compacts the readable
source itself and caches the result, so scripts stay small on a server updated
from Git without Node. The `X-Aggregate-Script` header reports `minified`,
`compact` or `source`. See [JavaScript build](JS-BUILD.md#select-the-configured-tracker).

## Turn on compression

Compression is the largest saver: browsers download about a third of the bytes.
It is a web server setting. The supplied configurations compress scripts,
styles and SVG images, including the tracker, tag manager and consent scripts
the application builds:

- **nginx**: [`docs/nginx/aggregate-analytics.conf`](nginx/aggregate-analytics.conf)
  uses gzip, which nginx includes. Brotli needs the `ngx_brotli` module; the file
  shows the lines to uncomment.
- **Apache**: [`public/.htaccess`](../public/.htaccess) uses Brotli when
  `mod_brotli` is loaded and the browser accepts it, and gzip (`mod_deflate`)
  otherwise. Enable the modules with `sudo a2enmod brotli deflate filter` if
  your server does not load them.
- **FrankenPHP** (the Docker image): [`frankenphp/Caddyfile`](../frankenphp/Caddyfile)
  uses Zstandard or gzip.

For another web server, CDN or hosting panel, turn on gzip or Brotli for
`application/javascript`, `text/javascript` and `text/css`.

The supplied configurations do not compress HTML pages. Dashboard pages carry
form tokens, and compressing them would let an attacker who can watch response
sizes probe those tokens (the BREACH attack). Scripts carry no secrets, so they
are safe to compress.

Compression changes a script's `ETag`: nginx marks it weak and Apache adds
`-gzip` or `-br`. Aggregate accepts both when a browser checks whether its copy
is current, so [browser caching](#browser-caching) keeps working.

To check, open **General settings → Page speed**, which requests the tracker the
way a browser does, or run:

```bash
curl -sI -H 'Accept-Encoding: br, gzip' 'https://analytics.example.com/aggregate.js?min=1' | grep -i content-encoding
```

## Browser caching

The configured tracker, tag manager and consent scripts are sent with
`Cache-Control: public, max-age=300` and an `ETag`. Browsers and CDNs reuse a
script for five minutes without asking, and after that an unchanged script is
confirmed with a `304 Not Modified` of a few hundred bytes. Saved settings reach
a returning visitor within five minutes and a new visitor at once. See
[JavaScript build](JS-BUILD.md#browser-caching).

## Load scripts without blocking

The install snippets load every script with `defer` or `async`, so the browser
shows the page before running them, and the tracker sends its page view once the
page is ready. Keep these attributes when you copy a snippet into your own
templates or a tag manager. See [setup](SETUP.md).
