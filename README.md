# Headless Privacy Analytics

An open-source, self-hosted analytics system with privacy-minimized, hour-bucketed anonymous-mode events, consent-based enhanced analytics, and a queue-backed ingestion API.

## Key Features

- **Privacy-minimized events by default**: individual page views and safe named interactions with UTC hour buckets, sanitized paths, coarse dimensions, and no visitor/session identifiers
- **Consent-based enhanced analytics**: visitor/session IDs, custom properties, goals, and exact dimensions only after consent is granted
- **Short-lived session cookies**: Only set with enhanced consent, 30-minute expiry, SameSite=Lax, Secure on HTTPS
- **Privacy controls**: administrative kill switch, sensitive-path exclusions, and low-volume BI cell suppression
- **Deployment guidance** for consent, disclosure, retention, and data-subject workflows (see [docs/PRIVACY-COMPLIANCE.md](docs/PRIVACY-COMPLIANCE.md))
- **Fast ingestion** via Symfony Messenger (`sync://` quick mode or async queue mode)
- **Domain whitelisting** and per-IP rate limiting
- **Multi-database support**: PostgreSQL, MySQL, MariaDB, MS SQL Server, and SQLite
- **Self-hosted**: Full control over your data
- **Easy setup**: One-command installation with Docker or native

## Requirements

**Choose your deployment method:**

### Option A: Docker (Recommended for Development)
- Docker and Docker Compose
- (Optional) Make for simplified commands

### Option B: Native Installation (Production/Shared Hosting)
- PHP 8.2+ with extensions: `ctype`, `iconv`, `pdo`, `mbstring`, `xml`, `curl`, `intl`
- **Database** (choose one):
  - PostgreSQL 13+ (recommended for production)
  - MySQL 8.0+
  - MariaDB 10.6+ or 11.x
  - Microsoft SQL Server 2017+ (requires `pdo_sqlsrv`)
  - SQLite 3.25+ (development/small sites)
- Composer
- Supervisor or systemd (only if using async queue mode)
- Web server (Nginx, Apache, or FrankenPHP)

## Quick Start

### Option A: Docker Setup (Recommended for Development)

**Using Make (Recommended):**

```bash
# Clone the repository
git clone <your-repo-url>
cd aggregate-sy

# Copy and edit app configuration (privacy controls, rate limit, JS namespace)
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# Start services (MySQL default profile)
make start-mysql

# Run migrations
make migrate-mysql

# Check system status
make status
```

If you want the dashboard UI, open `http://localhost/install` after migrations.
For API-only mode, set `dashboard_enabled: false` in `config/aggregate.yaml` and `DASHBOARD_ENABLED=0` in `.env`, then run `php bin/console cache:clear`.

**Manual Docker Setup:**

```bash
# 1. Clone and configure
git clone <your-repo-url>
cd aggregate-sy

# 2. Copy app configuration
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# 3. Start services with a DB profile
docker compose --profile mysql up -d

# 4. Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate -n

# 5. Optional: open http://localhost/install to create dashboard admin
```

For PostgreSQL or MariaDB profiles, set `DOCKER_DATABASE_URL` before starting containers. Example:

```bash
export DOCKER_DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/aggregate_analytics?serverVersion=16"
docker compose --profile postgres up -d
```

### Option B: Native Installation (Production/Shared Hosting)

**Manual Native Setup:**

```bash
# 1. Clone and install dependencies
git clone <your-repo-url>
cd aggregate-sy
composer install --no-dev --optimize-autoloader

# 2. Create .env in the project root (not committed to git)
cat > .env <<'EOF'
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
EOF

# Async scale-up mode (worker required):
# MESSENGER_TRANSPORT_DSN=doctrine://default

# 3. Copy app configuration
cp config/aggregate.yaml.example config/aggregate.yaml

# 4. Run migrations
php bin/console doctrine:migrations:migrate -n

# 5. Compile assets
php bin/console asset-map:compile

# 6. Set permissions
chmod -R 775 var/
chown -R www-data:www-data var/ public/  # Adjust user as needed

# 7. Optional (dashboard only): open https://your-domain.com/install
#    to create the admin user and save dashboard settings.
#    CLI alternative: php bin/console app:install

# 8. If using async queue mode, set up the worker (see Worker Setup below)
```

### Privacy migration warning for existing installations

Before applying the `Version20260724*` privacy migrations, back up the database, pause `/api/receive`, and stop every async worker. These migrations intentionally and irreversibly remove `daily_ip_hash`, delete legacy enhanced rows that do not contain `consent_state = 'granted'`, and delete Doctrine-transport `TrackEventMessage` envelopes so older payloads cannot be processed by the new code.

The queue cleanup can only match envelopes visible as plain text in the Doctrine transport. Inspect and purge tracker messages from failed, externally hosted, or encoded/base64 transports before resuming ingestion. Legacy rows already stored as `granted` are retained, but older releases could infer that value solely from a session ID; audit or purge those rows if you cannot establish their consent provenance. Resume ingestion and workers only after the migrations succeed.

**Worker Setup (only required for async mode):**

Choose one method:

**A. Using systemd (Recommended for Linux servers):**

```bash
# Create service file
sudo nano /etc/systemd/system/aggregate-worker.service

# Add content from docs/systemd/aggregate-worker.service
# Then enable and start:
sudo systemctl enable aggregate-worker
sudo systemctl start aggregate-worker
sudo systemctl status aggregate-worker
```

**B. Using Supervisor:**

```bash
# Install supervisor
sudo apt-get install supervisor  # Debian/Ubuntu
# or
sudo yum install supervisor      # CentOS/RHEL

# Create config
sudo nano /etc/supervisor/conf.d/aggregate-worker.conf

# Add content from docs/supervisor/aggregate-worker.conf
# Then reload:
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start aggregate-worker:*
```

**C. Using cron (Simple but less reliable):**

```bash
# Add to crontab
crontab -e

# Add this line (runs every minute):
* * * * * cd /path/to/aggregate-sy && php bin/console messenger:consume async --time-limit=60 >> var/log/worker.log 2>&1
```

## Configuration

Configuration is split between three places:

- **`.env` or `.env.local`** (server-level, never committed to git): Symfony infrastructure — database connection, message queue, app secret, and explicit proxy trust.
- **`config/aggregate.yaml`** (app-level, example committed): Analytics-specific settings — privacy measurement controls, rate limit, JS namespace, dashboard toggle.
- **`config/navigation.yaml`** (app-level, committed): Main navigation labels, icons, and link targets.

The web installer at `/install` is optional and only needed when you want dashboard-based setup.

### Environment File (`.env` or `.env.local`)

Create at least one env file in the project root:
- `.env` (recommended baseline)
- `.env.local` (server-only override)

Use `.env.prod.example` or `.env.local.example` as your template.  
`*.env.prod` by itself is not auto-loaded by Symfony runtime.

```bash
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=generate-with-openssl-rand-hex-32
TRUSTED_PROXIES=""
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
```

If neither `.env` nor `.env.local` exists, set these as real server environment variables instead.

For async scale-up mode, switch to:

```bash
MESSENGER_TRANSPORT_DSN=doctrine://default
```

Connection string formats for `DATABASE_URL` (see [Database Guide](docs/DATABASE.md)):
- PostgreSQL: `postgresql://user:pass@host:5432/dbname?serverVersion=16`
- MySQL: `mysql://user:pass@host:3306/dbname?serverVersion=8.0`
- MariaDB: `mysql://user:pass@host:3306/dbname?serverVersion=mariadb-11.4`
- SQL Server: `sqlsrv://user:pass@host:1433/dbname?serverVersion=2022`
- SQLite: `sqlite:///%kernel.project_dir%/var/data.db`

### App Configuration (`config/aggregate.yaml`)

Copy the example and adjust as needed:

```bash
cp config/aggregate.yaml.example config/aggregate.yaml
```

**Settings:**
- `rate_limit_per_minute`: API requests per IP per minute (default: `100`)
- `app_host`: Public hostname, used in dashboard integration snippets
- `js_namespace`: JavaScript global variable name (default: `Aggregate`)
- `dashboard_enabled`: Enable/disable dashboard/login/install behavior (default: `true`)
- `anonymous_tracking_enabled`: Administrative collection kill switch; `false` rejects anonymous and enhanced events (default: `true`)
- `anonymous_excluded_paths`: Paths/globs excluded from anonymous and enhanced collection (default: `[]`)
- `anonymous_geo_enabled`: Enable transient, local-IP-to-area lookup for accepted events (default: `false`)
- `anonymous_geo_level`: Store `macro_region` (continent-level, recommended) or `country` (default: `macro_region`)
- `anonymous_geo_database_path`: Local GeoLite2-Country-compatible `.mmdb` path (absolute recommended, or contained by the project root); URI/UNC/network-share/device paths are rejected and no database is downloaded automatically
- `DASHBOARD_ENABLED` (env var): Boot-time dashboard feature boundary for loading dashboard routes/services. Set `0` for API-only deploys.
- After changing dashboard feature settings (`dashboard_enabled` or `DASHBOARD_ENABLED`) in production, run `php bin/console cache:clear`.
- Malformed YAML or invalid collection-kill-switch/path settings fail closed: ingestion stops and `/api/health` reports a generic configuration error. Invalid optional GeoIP lookup settings instead produce no `geo_area`.

The two BI disclosure thresholds are configured separately in the admin dashboard and stored directly in the singleton `analytics_privacy_settings` database row:

- `anonymous_min_cell_count`: completed hourly cells in `bi_anonymous_events_v1` (default `5`, range `2`–`1000`)
- `anonymous_geo_min_cell_count`: completed daily cells in `bi_anonymous_geo_events_v1` (default `25`, range `10`–`1000`)

Dashboard changes take effect immediately because both BI views read this row directly. The migrations create it with safe defaults; there is no YAML copy or synchronization command. For an API-only deployment, update the singleton row through controlled database administration and keep routine BI roles read-only.

When upgrading from a version that mirrored these values, the existing database row keeps the last applied thresholds. Remove stale `anonymous_min_cell_count` and `anonymous_geo_min_cell_count` YAML/environment settings after every application instance is upgraded; the new code ignores them.

### Main Navigation (`config/navigation.yaml`)

Edit the `brand`, `items`, and `account` entries to manage the authenticated navbar. Each link defines exactly one Symfony `route` name (for example, `app_how_it_works`) or literal `url`; `icon` and `route_parameters` are optional. The `items` list may be empty. After changing navigation in production, clear the production cache so the container and Twig globals are rebuilt.

#### Optional coarse geography

Install or regularly update a GeoLite2 Country (or compatible country-level) MMDB yourself, mount it read-only on the local filesystem, and point `anonymous_geo_database_path` at its absolute path. Direct UNC/network-share and Windows device paths are rejected. The ingestion process must be able to read the file. Aggregate never downloads the database and never calls a GeoIP web service. No MMDB is bundled; follow the provider's license, attribution, and update terms.

```yaml
anonymous_geo_enabled: true
anonymous_geo_level: macro_region
anonymous_geo_database_path: /var/lib/GeoIP/GeoLite2-Country.mmdb
```

For each accepted event, the server validates the source as a public IP, performs the lookup locally, keeps only `continent:XX` or `country:XX`, and does not add the IP or detailed MMDB record to the event or queue. Invalid/private addresses, an unreadable database, or lookup errors leave `geo_area` empty and do not interrupt event ingestion. If the app is behind a proxy, set `TRUSTED_PROXIES` to only its exact IPs or narrow CIDRs so Symfony can use its forwarded address, and require that proxy to strip or overwrite client-supplied forwarding headers. Never use `0.0.0.0/0`, `::/0`, or `REMOTE_ADDR`, and never trust forwarding headers from arbitrary clients. With no trusted-proxy configuration, geography will normally describe the proxy or remain empty.

IP-derived geography describes an approximate network exit. VPNs, mobile carriers, corporate gateways, and geolocation-database errors can place it in the wrong area. Never represent `geo_area` as a visitor's precise residence or physical location.

Prefer `macro_region`. Country mode should be enabled only when traffic and risk assessment support it. IP lookup is personal-data processing in many jurisdictions, and a stored area can still contribute to singling out a person. Review the compliance guide and provide appropriate notice before enabling it.

### Customizing the JavaScript Namespace

Set `js_namespace` in `config/aggregate.yaml` (or via the web installer):

```yaml
js_namespace: "Company1Analytics"
```

Or override per-script tag:

```html
<script src="https://your-host/aggregate.js" data-namespace="Company1Analytics" referrerpolicy="no-referrer"></script>
```

Then use your custom namespace:
```javascript
window.Company1Analytics.setConsent(true);
window.Company1Analytics.emit('signup', {plan: 'pro'});
```

### Environment Variable Override

App-specific settings from `aggregate.yaml` can be overridden with environment variables:

```bash
export JS_NAMESPACE="MyCustomAnalytics"
export DASHBOARD_ENABLED="0"
export ANONYMOUS_TRACKING_ENABLED="0"
export ANONYMOUS_GEO_ENABLED="0"
```

## Architecture

The system includes these services:
- **php**: FrankenPHP web server with Symfony application
- **worker**: Background job processor for analytics events
- **database**: one selected Docker profile (`mysql`, `postgres`, or `mariadb`)
- **asset-compile**: Compiles frontend assets on startup

## Usage

### Health Check

```bash
curl http://localhost/api/health
```

Or with Make:
```bash
make status
```

### JavaScript Integration

Add to your website:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'your-website-token'
  };
</script>
<script src="https://your-host/aggregate.js" async referrerpolicy="no-referrer"></script>
```
An anonymous-mode page-view row is recorded automatically when the script loads. Queries, fragments, raw referrers, cookies, visitor IDs, and session IDs are not sent in anonymous mode. You may also call `emit(...)`: before enhanced consent, each safe event name and its coarse context are retained, while properties and goals are omitted.

### Custom Event Tracking

Use a fixed event taxonomy. Names must match `[A-Za-z][A-Za-z0-9_.:-]{0,99}` and must not contain user-entered or identifier-like values.
Even with enhanced consent, keep event properties purpose-limited and avoid emails, account IDs, form contents, search terms, or other free text.

```javascript
// Anonymous mode records this safe event name with coarse context. The property
// and goal arguments are omitted until enhanced analytics consent is granted.
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'trial_signup');

// Accept enhanced analytics to include properties, goals and identifiers.
window.Aggregate.setConsent(true);
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'trial_signup');

// Reject or withdraw enhanced analytics. Coarse named-event rows continue.
window.Aggregate.setConsent(false);
```

### Testing

Send a test event:
```bash
make test-tracking
```

Or manually:
```bash
curl -i -X POST http://localhost/api/receive \
  -H "Origin: http://example.com" \
  -H "Content-Type: application/json" \
  -d '{
    "pagePath":"/pricing",
    "referrerChannel":"search",
    "deviceClass":"desktop",
    "viewportBucket":"large",
    "eventName":"button_click",
    "websiteToken":"your-token-here",
    "consentState":"unknown"
  }'
```

The server stores this as an individual `privacy_mode = 'anonymous'` row with a UTC-hour timestamp and no properties, goal, identifier, or exact dimension.

## Make Commands

- `make help` - Show all available commands
- `make install` - Complete installation and setup
- `make start` - Start all services (defaults to `DOCKER_PROFILE=mysql`)
- `make stop` - Stop all services
- `make restart` - Restart all services
- `make logs` - View logs from all services
- `make logs-worker` - View worker logs only (async mode)
- `make migrate` - Run database migrations (use `DOCKER_PROFILE` for non-MySQL)
- `make start-mysql` - Start Docker with MySQL profile + DSN
- `make start-postgres` - Start Docker with PostgreSQL profile + DSN
- `make start-mariadb` - Start Docker with MariaDB profile + DSN
- `make migrate-mysql` - Run migrations for MySQL profile
- `make migrate-postgres` - Run migrations for PostgreSQL profile
- `make migrate-mariadb` - Run migrations for MariaDB profile
- `make create-website` - Create a new website (interactive)
- `make status` - Check service status and health
- `make test-tracking` - Send a test tracking event
- `make clean` - Clean up containers and volumes

Docker profile examples:
- `make start-postgres`
- `make migrate-postgres`
- `make start-mariadb`
- `make migrate-mariadb`

## JavaScript Snippet (aggregate.js)
Serve the public file at `/aggregate.js` and embed it on your site:
```html
<script>
  window.Aggregate = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'abc-123-def-456'
  };
</script>
<script src="https://your-host/aggregate.js" async referrerpolicy="no-referrer"></script>
```
- It auto-sends a privacy-minimized `view` event on load (no `emit(...)` call needed for page views).
- Named `emit(...)` events are retained as individual anonymous-mode rows before consent; their properties and goals are omitted.
- To enable identifiers, event properties, goals, and exact dimensions after consent, call:
```js
window.Aggregate.setConsent(true);
```
- Emit custom events:
```js
window.Aggregate.emit('signup-click', { plan_type: 'pro' }, 'trial_signup');
```
- To reject or withdraw enhanced tracking, call `window.Aggregate.setConsent(false)`. This clears browser identifiers and returns to coarse, hour-bucketed anonymous-mode event rows; it does not delete previously collected server data.

### Google Tag Manager (GTM) Integration

Complete setup guide for integrating with Google Tag Manager for both pixel tracking and custom event tracking.

#### Step 1: Install the Tracking Pixel in GTM

1. **Create a Custom HTML Tag**
   - In GTM, go to **Tags** → **New**
   - Click **Tag Configuration** → **Custom HTML**
   - Name it: "Analytics Tracking Pixel"

2. **Add the Tracking Script**

   Choose one of these configuration methods:

   **Option A: Inline Configuration (Recommended)**
   ```html
   <script>
     window.Aggregate = {
       endpoint: 'https://your-analytics-host.com/api/receive',
       websiteToken: 'your-website-token-here'
     };
   </script>
   <script src="https://your-analytics-host.com/aggregate.js" async referrerpolicy="no-referrer"></script>
   ```

   **Option B: Data Attributes (No inline JS)**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     referrerpolicy="no-referrer"
     async>
   </script>
   ```

   **Option C: URL Parameters**
   ```html
   <script src="https://your-analytics-host.com/aggregate.js?endpoint=https%3A%2F%2Fyour-analytics-host.com%2Fapi%2Freceive&token=your-website-token-here" async referrerpolicy="no-referrer"></script>
   ```

3. **Set the Trigger**
   - Click **Triggering** → **Choose a trigger**
   - Select **All Pages** (for view tracking on every page)
   - Or create a custom trigger for specific pages

4. **Save and Publish**
   - Click **Save**
   - Submit changes and publish your GTM container

#### Step 2: Track Custom Events from GTM

Named custom events may be stored in anonymous mode. Before `setConsent(true)`, the SDK sends only a syntax-restricted event name and coarse context; GTM variables supplied as event properties or goals are omitted.

**Method A: Using GTM's Custom HTML Tag for Specific Events**

1. Create a new **Custom HTML Tag**
2. Name it based on the event (e.g., "Track Button Click - CTA")
3. Add this code:
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.emit) {
       window.Aggregate.emit('cta_click', {
         button_text: 'Get Started',
         location: 'homepage_hero'
       }, 'signup_goal');
     }
   </script>
   ```
4. Set a trigger (e.g., click on specific button/element)

**Method B: Using GTM Variables for Dynamic Event Tracking**

1. Create a **Custom HTML Tag**
2. Name it: "Analytics Custom Event - Generic"
3. Add this code:
   ```html
   <script>
     (function() {
       if (window.Aggregate && window.Aggregate.emit) {
         var eventName = {{Event Name Variable}};
         var eventData = {
           category: {{Event Category}},
           label: {{Event Label}},
           value: {{Event Value}}
         };
         var goalEvent = {{Goal Event Variable}};
         window.Aggregate.emit(eventName, eventData, goalEvent);
       }
     })();
   </script>
   ```
4. Create corresponding **User-Defined Variables** in GTM:
   - `Event Name Variable` (e.g., Data Layer Variable: `eventName`)
   - `Event Category`, `Event Label`, `Event Value`
   - `Goal Event Variable` (optional; e.g., Data Layer Variable: `goalEvent`)

   Map `Event Name Variable` to an approved fixed taxonomy; never populate it from click text, URLs, form fields, or other user-provided values.

5. Trigger this tag using **Custom Events** or **Click Triggers**

#### Step 3: Consent Management Integration

Use your consent manager to control enhanced analytics. Coarse page-view and named-event rows continue after rejection unless an administrator disables collection or excludes the current path.

1. **Create a Tag for Consent Opt-in**
   - Tag Type: **Custom HTML**
   - Name: "Analytics Accept Enhanced Consent"
   - Code:
     ```html
     <script>
       if (window.Aggregate && window.Aggregate.setConsent) {
         window.Aggregate.setConsent(true);
       }
     </script>
     ```
   - **Trigger**: Fire when user accepts cookies/consent
     - Example: `consentGranted` Custom Event
     - Or use your CMP's (Consent Management Platform) built-in triggers

2. **If consent was already granted, initialize it via a data attribute**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     data-consent="1"
     referrerpolicy="no-referrer"
     async>
   </script>
   ```

3. **Handle rejection or withdrawal**
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.setConsent) {
       window.Aggregate.setConsent(false);
     }
   </script>
   ```
   This removes the SDK's visitor/session identifiers and stops sending custom properties, goals, and exact dimensions. Safe event names continue as individual anonymous-mode rows. It does not erase data already held by the server; handle deletion requests through your documented data-subject process.

#### Step 4: Common Event Tracking Examples

**Track Form Submissions**
```html
<script>
  window.Aggregate.emit('form_submit', {
    form_name: {{Form Name}},
    form_id: {{Form ID}}
  }, 'lead_submit');
</script>
```
- **Trigger**: Form Submission trigger for your target form

**Track Button Clicks**
```html
<script>
window.Aggregate.emit('button_click', {
  button_text: {{Click Text}},
  button_id: {{Click ID}},
  page_path: {{Page Path}}
});
</script>
```
- **Trigger**: Click - All Elements, filter by Click Classes/IDs

**Track Scroll Depth**
```html
<script>
window.Aggregate.emit('scroll_depth', {
  depth_percentage: {{Scroll Depth Threshold}},
  page_path: {{Page Path}}
});
</script>
```
- **Trigger**: Scroll Depth (e.g., 25%, 50%, 75%, 100%)

**Track Video Views**
```html
<script>
  window.Aggregate.emit('video_interaction', {
    video_title: {{Video Title}},
    video_action: {{Video Status}},  // 'start', 'pause', 'complete'
    video_duration: {{Video Duration}},
    video_percent: {{Video Percent}}
  });
</script>
```
- **Trigger**: YouTube Video or Video trigger in GTM

**Track E-commerce Events**
```html
<script>
  window.Aggregate.emit('add_to_cart', {
    product_id: {{Product ID}},
    product_name: {{Product Name}},
    product_price: {{Product Price}},
    quantity: {{Product Quantity}}
  }, 'add_to_cart_goal');
</script>
```
- **Trigger**: Custom Event `addToCart` from Data Layer

#### Step 5: Testing Your GTM Setup

1. **Enable GTM Preview Mode**
   - In GTM, click **Preview**
   - Enter your website URL

2. **Check Tag Firing**
   - Verify "Analytics Tracking Pixel" fires on page load
   - Verify custom event tags fire when triggered

3. **Monitor Network Requests**
   - Open browser DevTools → Network tab
   - Look for POST requests to `/api/receive`
   - Verify 202 Accepted response

4. **Check Analytics Backend**
   ```bash
   # Query database for recent events
   make logs-worker
   # Or check your database directly
   ```

#### Troubleshooting

**Pixel not loading:**
- Check GTM Preview mode to see if tag fires
- Verify `https://your-analytics-host.com/aggregate.js` is accessible
- Check browser console for errors

**Events not tracking:**
- Verify `window.Aggregate.emit` is available in browser console
- Ensure tracking pixel loaded before custom event tags fire
- Add tag sequencing: Make custom event tags wait for pixel tag

**403 Forbidden errors:**
- Verify your domain is correctly set in `config/websites.yaml`
- Check `Origin` header is being sent (subdomains are auto-allowed)

**429 Too Many Requests:**
- Increase `rate_limit_per_minute` in `config/aggregate.yaml` (or via dashboard settings)
- Check for infinite loops in your event tracking code

#### Advanced: Using dataLayer for Event Tracking

Push events to GTM's dataLayer, then capture with a single generic tag:

```javascript
// On your website
window.dataLayer = window.dataLayer || [];
dataLayer.push({
  'event': 'customAnalyticsEvent',
  'eventName': 'signup_click',
  'goalEvent': 'trial_signup',
  'eventData': {
    'plan': 'pro',
    'source': 'pricing_page'
  }
});
```

**GTM Tag Configuration:**
1. Create trigger: Custom Event = `customAnalyticsEvent`
2. Create tag:
   ```html
   <script>
     if (window.Aggregate && window.Aggregate.emit) {
       window.Aggregate.emit(
         {{DLV - eventName}},
         {{DLV - eventData}},
         {{DLV - goalEvent}}
       );
     }
   </script>
   ```
3. Create Data Layer Variables:
   - `DLV - eventName` → Data Layer Variable Name: `eventName`
   - `DLV - eventData` → Data Layer Variable Name: `eventData`
   - `DLV - goalEvent` → Data Layer Variable Name: `goalEvent` (optional)

## Security & Privacy

### Domain Whitelisting
The `/api/receive` endpoint checks the `Origin` or `Referer` header against the registered domain for each website. Subdomains are automatically allowed.

### Rate Limiting
Simple per-IP rate limiting (default: 100 requests/minute) prevents abuse. It stores only rotating, keyed minute-bucket files in `var/rate_limit/`, not raw addresses; use an upstream limiter with a short TTL for larger deployments.

### Privacy Features

**Anonymous-mode events (default):**

- The SDK sends a sanitized path without query strings or fragments.
- Page views use the event name `view`; `emit(...)` accepts fixed names matching `[A-Za-z][A-Za-z0-9_.:-]{0,99}`, such as `button_click`, `form_submit`, or `ui:menu_open`. Identifier-like names are rejected.
- Obvious email addresses, UUIDs, numeric route IDs, and opaque tokens are redacted from path segments.
- Referrers become a coarse channel such as `direct`, `internal`, `search`, `social`, `email`, or `referral`; the raw referrer is not sent.
- Device and viewport values are coarse buckets. No visitor ID, session ID, cookie, custom properties, goal, exact screen width, raw IP, or full User-Agent is retained in an anonymous-mode row.
- Optional local geolocation retains only a continent-level or country code in `geo_area`; the request IP and detailed lookup result are not put in analytics storage or the queue.
- Each event is stored as an individual row using a server-generated UTC hour bucket rather than an exact timestamp. The BI view groups these rows and suppresses cells below `anonymous_min_cell_count`.
- Hour bucketing and removal of identifiers reduce risk but do not guarantee that a row is legally anonymous; paths, event names, small populations, and outside information can still make data personal in context.
- Administrators can disable all collection globally or exclude sensitive path globs from both measurement modes.

**Enhanced analytics (requires consent):**

- `window.Aggregate.setConsent(true)` enables the visitor ID, session ID, session cookie, exact screen width, custom properties, and goals.
- `window.Aggregate.setConsent(false)` means reject or withdraw **enhanced analytics**. It removes SDK identifiers and strips enhanced event details, while coarse anonymous-mode page-view and named-event rows continue.
- Consent withdrawal is prospective. It does not claim to delete previously collected server data; operators must provide and follow an appropriate data-subject request workflow.

These controls help reduce privacy risk but do not make a deployment automatically compliant with any law. The operator remains responsible for its legal basis, notices, consent-manager behavior, retention, access controls, vendor relationships, and rights-request procedures.

📖 **Full compliance guide**: [docs/PRIVACY-COMPLIANCE.md](docs/PRIVACY-COMPLIANCE.md)

## Data Model

**`events`** is the unified private storage table. `privacy_mode` separates `anonymous` and `enhanced` rows; page views use `event_name = 'view'`.

- Shared dimensions include `website_token`, `event_name`, sanitized path in `url`, coarse channel in `referrer`, `device_class`, `viewport_bucket`, optional `geo_area`, `privacy_mode`, and `created_at`.
- Anonymous-mode rows are individual events whose server-generated `created_at` is truncated to a UTC hour. Enhanced-only identifier, property, goal, exact-dimension, and generalized User-Agent columns remain null.
- Enhanced rows may include `screen_width`, `visitor_id`, `session_id`, `consent_state`, `custom_data`, `goal_event`, `generalized_user_agent`, and an exact server timestamp.

Do not grant routine BI users access to raw `events`. Hour bucketing and missing IDs reduce risk, but anonymous-mode rows can still be personal data in context.

**`bi_anonymous_events_v1`** is the supported Tableau/Power BI contract for anonymous-mode measurement:

- `website_token`, `event_hour`, `event_name`, `page_path`, `referrer_channel`, `device_class`, `viewport_bucket`, `event_count`
- It groups anonymous rows into hourly cells, withholds the current UTC hour, and exposes a completed cell only when `event_count` reaches `anonymous_min_cell_count` (default `5`, allowed range `2`–`1000`).

**`bi_anonymous_geo_events_v1`** is the separate, lower-dimensional geography contract:

- `website_token`, `event_day`, `event_name`, `geo_area`, `event_count`
- It groups anonymous rows by UTC day, withholds the current day, and exposes a cell only when `event_count` reaches `anonymous_geo_min_cell_count` (default `25`, allowed range `10`–`1000`).
- It intentionally omits page path, referrer, device, viewport, and identifiers. Geography is not joined into `bi_anonymous_events_v1`.
- Low-volume areas are pooled into `country:other` or `continent:other` only when the pool itself meets the threshold. When exactly one area is below the threshold, the smallest otherwise-visible area is also pooled as secondary suppression to make direct subtraction harder.

`analytics_privacy_settings` is the database source of truth for the two thresholds used directly by the BI views. Administrators configure them in the dashboard; they are not copied from YAML or environment variables.

Suppression counts events, not distinct people: anonymous rows deliberately have no stable person identifier. One person can therefore contribute several events to a released cell. Secondary suppression reduces simple differencing but cannot prevent inference across every extract, time period, or outside data source. Neither view establishes k-anonymity or guarantees that its output is legally anonymous; use higher thresholds, access controls, retention limits, and disclosure review where warranted.

Website registry is stored in `config/websites.yaml` (name/domain/token), not in relational tables.

Query examples for BI tools (Power BI, Looker, Tableau):
```sql
-- Grouped anonymous page views and named events, with low-volume cells suppressed
SELECT * FROM bi_anonymous_events_v1;

-- Daily coarse geography with a higher threshold and fewer dimensions
SELECT * FROM bi_anonymous_geo_events_v1;

-- Enhanced page views (only if your BI policy permits raw enhanced data)
SELECT * FROM events WHERE privacy_mode = 'enhanced' AND event_name = 'view';

-- Enhanced custom events
SELECT * FROM events WHERE privacy_mode = 'enhanced' AND event_name != 'view';
```

On PostgreSQL, MySQL, MariaDB, or SQL Server, use a SELECT-only BI database role and grant it access only to approved, versioned views and any enhanced tables your policy permits. SQLite has no table/view privilege system: do not give routine BI users or desktop tools the database file, because they can query raw `events`. Export only approved view results through a controlled process, or use a server database when direct BI connectivity is required.

## Production Considerations

- Replace the file-based rate limiter with Redis-backed solution for multi-server deployments
- Use RabbitMQ or Redis instead of `doctrine://default` for high-volume message queues
- Configure proper database backups
- Set up monitoring and alerting
- Use HTTPS in production (configured via Caddy/FrankenPHP)
- Disable or redact IP addresses, User-Agent strings, request bodies, and referrers in proxy/CDN/application access logs for the collection endpoint
- When coarse geography is enabled, keep the local MMDB current and read-only; do not replace it with a third-party lookup service without a separate privacy/vendor review

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
