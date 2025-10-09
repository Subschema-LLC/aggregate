# Headless Privacy Analytics

An open-source, self-hosted, privacy-first analytics system with a two-tier tracking approach and queue-backed ingestion API.

## Key Features

- **Cookieless default tracking (Tier 1)** with server-side anonymization (daily salted IP hash + generalized User-Agent)
- **Consent-based tracking (Tier 2)** with optional visitorId/sessionId when consent is granted
- **Fast ingestion** via Symfony Messenger and background worker
- **Domain whitelisting** and per-IP rate limiting
- **Self-hosted**: Full control over your data
- **Easy setup**: One-command installation with Docker

## Requirements

- Docker and Docker Compose
- (Optional) Make for simplified commands
- (Optional) PHP 8.2+ for local development without Docker

## Quick Start

### Using Make (Recommended)

```bash
# Clone the repository
git clone <your-repo-url>
cd aggregate-sy

# Copy and edit configuration
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# Generate a secure secret (use this for daily_salt_secret)
openssl rand -base64 32

# Start services
make start

# Run migrations
make migrate

# Create your first website
make create-website

# Check system status
make status
```

### Manual Setup

```bash
# 1. Clone and configure
git clone <your-repo-url>
cd aggregate-sy

# 2. Copy configuration file
cp config/aggregate.yaml.example config/aggregate.yaml

# 3. Edit config/aggregate.yaml
nano config/aggregate.yaml

# Set at minimum:
#   - daily_salt_secret (generate with: openssl rand -base64 32)
#   - database_url
#   - messenger_transport_dsn

# 4. Start services
docker compose up -d

# 5. Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate -n

# 6. Create a website
docker compose exec -T database psql -U app -d app -c \
  "INSERT INTO websites (name, domain, public_token) VALUES ('My Site', 'example.com', '$(openssl rand -hex 16)');"
```

## Configuration

All configuration is managed through `config/aggregate.yaml`. This file supports:

1. **Environment-specific configuration** (recommended for multiple environments)
2. **Flat configuration** (simple single-environment setup)
3. **Separate files** per environment (`aggregate_dev.yaml`, `aggregate_prod.yaml`, etc.)

### Quick Setup

Copy the example file and edit it:

```bash
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml
```

### Configuration Options

**Required Settings:**
- `daily_salt_secret`: Secure random string for IP hashing (minimum 16 characters)
  - Generate with: `openssl rand -base64 32`
  - **Critical**: Must be kept secret and unique per installation
- `database_url`: Database connection string
  - PostgreSQL: `postgresql://user:pass@host:5432/dbname?serverVersion=16`
  - MySQL: `mysql://user:pass@host:3306/dbname?serverVersion=8.0`
- `messenger_transport_dsn`: Message queue configuration
  - Simple: `doctrine://default` (database-backed)
  - Production: `amqp://guest:guest@rabbitmq:5672/%2f/messages` (RabbitMQ)

**Optional Settings:**
- `rate_limit_per_minute`: API requests per IP (default: 100)
- `app_host`: Public hostname for documentation
- `js_namespace`: JavaScript global variable name (default: `MyAnalytics`)

### Environment-Specific Configuration

Use the `environments` section in `aggregate.yaml`:

```yaml
environments:
  dev:
    daily_salt_secret: "dev-secret-CHANGE-IN-PROD"
    database_url: "postgresql://app:password@localhost:5432/app_dev"
    
  prod:
    daily_salt_secret: "SECURE-RANDOM-STRING-HERE"
    database_url: "postgresql://app:password@db-server:5432/app"
    rate_limit_per_minute: 100
```

### Customizing the JavaScript Namespace

Set `js_namespace` in `config/aggregate.yaml`:

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

Any setting in `aggregate.yaml` can be overridden with environment variables (uppercase with underscores):

```bash
export DAILY_SALT_SECRET="override-value"
export JS_NAMESPACE="MyCustomAnalytics"
```

## Architecture

The system includes these services:
- **php**: FrankenPHP web server with Symfony application
- **worker**: Background job processor for analytics events
- **database**: PostgreSQL database
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
  window.MyAnalytics = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'your-website-token'
  };
</script>
<script src="https://your-host/aggregate.js" async></script>
```

### Custom Event Tracking

```javascript
// Track custom events
window.MyAnalytics.track('signup-click', { plan_type: 'pro' });

// Enable consent-based tracking (Tier 2)
window.MyAnalytics.setConsent(true);
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
    "eventData": {"plan_type":"pro"}
  }'
```

## Make Commands

- `make help` - Show all available commands
- `make install` - Complete installation and setup
- `make start` - Start all services
- `make stop` - Stop all services
- `make restart` - Restart all services
- `make logs` - View logs from all services
- `make logs-worker` - View worker logs only
- `make migrate` - Run database migrations
- `make create-website` - Create a new website (interactive)
- `make status` - Check service status and health
- `make test-tracking` - Send a test tracking event
- `make generate-salt` - Generate a random salt
- `make clean` - Clean up containers and volumes

## JavaScript Snippet (aggregate.js)
Serve the public file at `/aggregate.js` and embed it on your site:
```html
<script>
  window.MyAnalytics = {
    endpoint: 'https://your-host/api/receive',
    websiteToken: 'abc-123-def-456'
  };
</script>
<script src="https://your-host/aggregate.js" async></script>
```
- It auto-sends a pageview on load.
- For consent-based tracking (Tier 2), call:
```js
window.MyAnalytics.setConsent(true);
```
- Track custom events:
```js
window.MyAnalytics.track('signup-click', { plan_type: 'pro' });
```

### Tag Manager Integration
You can include the script via a tag manager (e.g., Google Tag Manager) using a Custom HTML tag. Two options:

1) Inline config + external script:
```html
<script>
  window.MyAnalytics = { endpoint: 'https://your-host/api/receive', websiteToken: 'abc-123-def-456' };
</script>
<script src="https://your-host/aggregate.js" async></script>
```

2) Configure via URL parameters or data-attributes (no inline JS needed):
```html
<!-- URL params -->
<script src="https://your-host/aggregate.js?endpoint=https%3A%2F%2Fyour-host%2Fapi%2Freceive&token=abc-123-def-456" async></script>

<!-- Or data-attributes -->
<script src="https://your-host/aggregate.js" data-endpoint="https://your-host/api/receive" data-website-token="abc-123-def-456" async></script>
```
You can also pre-set consent via `consent=1` query param or `data-consent="1"`. The snippet exposes:
- `window.MyAnalytics.setConsent(true)` to switch to Tier 2 IDs
- `window.MyAnalytics.track(name, data)` to send custom events

## Security & Privacy

### Domain Whitelisting
The `/api/receive` endpoint checks the `Origin` or `Referer` header against the registered domain for each website. Subdomains are automatically allowed.

### Rate Limiting
Simple per-IP rate limiting (default: 100 requests/minute) prevents abuse. Stored in `var/rate_limit/` directory.

### Privacy Features
- **Daily IP hashing**: IPs are immediately hashed with current UTC date + `DAILY_SALT_SECRET`, making cross-day tracking impossible
- **No PII storage**: Raw IP addresses are never stored
- **User-Agent generalization**: Full User-Agent strings are generalized to basic categories (e.g., "Chrome on Desktop")
- **Cookieless by default**: No cookies or client-side storage unless user provides consent

## Data Model

**websites**
- `id`, `name`, `domain`, `public_token`

**page_views**
- `id`, `website_id`, `url`, `referrer`, `daily_ip_hash`, `generalized_user_agent`, `screen_width`, `created_at`

**events**
- `id`, `website_id`, `page_view_id`, `event_name`, `custom_data` (JSON), `created_at`

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
