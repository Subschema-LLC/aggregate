# Headless Privacy Analytics

An open-source, self-hosted, privacy-first analytics system with a two-tier tracking approach and queue-backed ingestion API.

## Key Features

- **Cookieless default tracking (Tier 1)** with server-side anonymization (daily salted IP hash + generalized User-Agent)
- **Consent-based tracking (Tier 2)** with optional visitorId/sessionId when consent is granted
- **Privacy-compliant session cookies**: Only set with consent, 30-minute expiry, SameSite=Lax, Secure
- **GDPR, CCPA, ePrivacy compliant**: Full compliance guide included (see [docs/PRIVACY-COMPLIANCE.md](docs/PRIVACY-COMPLIANCE.md))
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
  - MySQL 5.7+ or 8.0+
  - MariaDB 10.6+ or 11.x
  - Microsoft SQL Server 2017+ (requires `pdo_sqlsrv`)
  - SQLite 3 (development/small sites)
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

# Copy and edit app configuration (salt, rate limit, js namespace)
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

Configuration is split between two places:

- **`.env`** (server-level, never committed to git): Symfony infrastructure — database connection, message queue, app secret.
- **`config/aggregate.yaml`** (app-level, example committed): Analytics-specific settings — daily salt, rate limit, JS namespace, dashboard toggle.

The web installer at `/install` is optional and only needed when you want dashboard-based setup.

### Environment File (`.env`)

Create `.env` in the project root (copy from `.env.dev` or `.env.prod` as a starting point):

```bash
APP_ENV=prod
APP_SECRET=generate-with-openssl-rand-hex-32
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://
MAILER_DSN=null://null
```

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
- `daily_salt_secret`: Auto-generated by the web installer if not set. Can also be generated with `openssl rand -base64 32`.
- `rate_limit_per_minute`: API requests per IP per minute (default: `100`)
- `app_host`: Public hostname, used in dashboard integration snippets
- `js_namespace`: JavaScript global variable name (default: `Aggregate`)
- `dashboard_enabled`: Enable/disable dashboard/login/install behavior (default: `true`)
- `DASHBOARD_ENABLED` (env var): Boot-time dashboard feature boundary for loading dashboard routes/services. Set `0` for API-only deploys.
- After changing dashboard feature settings (`dashboard_enabled` or `DASHBOARD_ENABLED`) in production, run `php bin/console cache:clear`.

### Customizing the JavaScript Namespace

Set `js_namespace` in `config/aggregate.yaml` (or via the web installer):

```yaml
js_namespace: "Company1Analytics"
```

Or override per-script tag:

```html
<script src="https://your-host/aggregate.js" data-namespace="Company1Analytics"></script>
```

Then use your custom namespace:
```javascript
window.Company1Analytics.track('signup', {plan: 'pro'});
```

### Environment Variable Override

App-specific settings from `aggregate.yaml` can be overridden with environment variables:

```bash
export DAILY_SALT_SECRET="override-value"
export JS_NAMESPACE="MyCustomAnalytics"
export DASHBOARD_ENABLED="0"
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
<script src="https://your-host/aggregate.js" async></script>
```
Page views are tracked automatically when the script loads. You only call `emit(...)` for custom events/goals.

### Custom Event Tracking

```javascript
// emit(eventName, eventData, goalEvent)
window.Aggregate.emit('signup-click', { plan_type: 'pro' }, 'trial_signup');

// Enable consent-based tracking (Tier 2)
window.Aggregate.setConsent(true);
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
    "url":"https://example.com/pricing",
    "referrer":"https://google.com/",
    "screenWidth":1920,
    "eventName":"signup-click",
    "websiteToken":"your-token-here",
    "eventData": {"plan_type":"pro"},
    "goalEvent":"trial_signup"
  }'
```

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
- `make generate-salt` - Generate a random salt
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
<script src="https://your-host/aggregate.js" async></script>
```
- It auto-sends a page view on load (no `emit(...)` call needed for page views).
- For consent-based tracking (Tier 2), call:
```js
window.Aggregate.setConsent(true);
```
- Emit custom events:
```js
window.Aggregate.emit('signup-click', { plan_type: 'pro' }, 'trial_signup');
```

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
   <script src="https://your-analytics-host.com/aggregate.js" async></script>
   ```

   **Option B: Data Attributes (No inline JS)**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     async>
   </script>
   ```

   **Option C: URL Parameters**
   ```html
   <script src="https://your-analytics-host.com/aggregate.js?endpoint=https%3A%2F%2Fyour-analytics-host.com%2Fapi%2Freceive&token=your-website-token-here" async></script>
   ```

3. **Set the Trigger**
   - Click **Triggering** → **Choose a trigger**
   - Select **All Pages** (for view tracking on every page)
   - Or create a custom trigger for specific pages

4. **Save and Publish**
   - Click **Save**
   - Submit changes and publish your GTM container

#### Step 2: Track Custom Events from GTM

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

5. Trigger this tag using **Custom Events** or **Click Triggers**

#### Step 3: GDPR/Consent Management Integration

If you need to respect user consent before enabling Tier 2 tracking:

1. **Create a Tag for Consent Opt-in**
   - Tag Type: **Custom HTML**
   - Name: "Analytics Enable Consent"
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

2. **Optional: Pre-enable Consent via Data Attribute**
   ```html
   <script
     src="https://your-analytics-host.com/aggregate.js"
     data-endpoint="https://your-analytics-host.com/api/receive"
     data-website-token="your-website-token-here"
     data-consent="1"
     async>
   </script>
   ```

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
    button_url: {{Click URL}},
    page_url: {{Page URL}}
  });
</script>
```
- **Trigger**: Click - All Elements, filter by Click Classes/IDs

**Track Scroll Depth**
```html
<script>
  window.Aggregate.emit('scroll_depth', {
    depth_percentage: {{Scroll Depth Threshold}},
    page_url: {{Page URL}}
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
Simple per-IP rate limiting (default: 100 requests/minute) prevents abuse. Stored in `var/rate_limit/` directory.

### Privacy Features

**Two-Tier Tracking System:**

**Tier 1 (Default - No Consent Required):**
- ✅ **Cookieless tracking**: No cookies or persistent identifiers
- ✅ **Daily IP hashing**: IPs are immediately hashed with current UTC date + `DAILY_SALT_SECRET`, making cross-day tracking impossible
- ✅ **No PII storage**: Raw IP addresses are never stored
- ✅ **User-Agent generalization**: Full User-Agent strings are generalized to basic categories (e.g., "Chrome on Desktop")
- ✅ **Anonymous by design**: Cannot identify individual users
- ✅ **GDPR Article 6(1)(f)**: Legitimate interest basis

**Tier 2 (Requires Explicit Consent):**
- 📝 **Session cookie**: `aggregate_session` (30-minute expiry, SameSite=Lax, Secure on HTTPS)
- 📝 **Visitor ID**: Stored in localStorage for cross-session tracking
- 📝 **Session ID**: Stored in sessionStorage and session cookie
- 📝 **Consent-based**: Only activated when `window.Aggregate.setConsent(true)` is called
- 📝 **Revocable**: Call `window.Aggregate.setConsent(false)` to withdraw consent and delete all data

**Privacy Compliance:**
- ✅ **GDPR compliant** (EU): Consent-based processing, data minimization, storage limitation
- ✅ **CCPA compliant** (California): No sale of data, user control, disclosure requirements
- ✅ **ePrivacy Directive** (EU): Prior consent for cookies, information requirements
- ✅ **PECR compliant** (UK): Soft opt-in, clear information, easy rejection

📖 **Full compliance guide**: [docs/PRIVACY-COMPLIANCE.md](docs/PRIVACY-COMPLIANCE.md)

## Data Model

**events** (single table — page views are events with `event_name = 'view'`)
- `id`, `website_token`, `event_name`, `url`, `referrer`, `daily_ip_hash`, `generalized_user_agent`, `screen_width`, `session_id`, `custom_data` (JSON), `goal_event`, `created_at`

Website registry is stored in `config/websites.yaml` (name/domain/token), not in relational tables.

Query examples for BI tools (PowerBI, Looker, Tableau) — no joins needed:
```sql
-- Page views only
SELECT * FROM events WHERE event_name = 'view';

-- Custom events only
SELECT * FROM events WHERE event_name != 'view';

-- All events with context
SELECT * FROM events;
```

## Production Considerations

- Replace the file-based rate limiter with Redis-backed solution for multi-server deployments
- Use RabbitMQ or Redis instead of `doctrine://default` for high-volume message queues
- Configure proper database backups
- Set up monitoring and alerting
- Use HTTPS in production (configured via Caddy/FrankenPHP)
- Consider implementing IP anonymization at the network level for additional privacy

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
