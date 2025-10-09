# Headless Privacy Analytics (POC)

This repository contains a Symfony-based proof of concept for a two-tier, privacy-first analytics system with a queue-backed ingestion API and an embeddable JavaScript snippet.

Key features:
- Cookieless default tracking (Tier 1) with server-side anonymization (daily salted IP hash + generalized User-Agent)
- Consent-based tracking (Tier 2) with optional visitorId/sessionId when consent is granted
- Fast ingestion via Symfony Messenger and background worker
- Domain whitelisting and simple per-IP rate limiting

This will not have a UI to start, but will use YAML config files to set up.

## Requirements
- Docker and Docker Compose (recommended)
- PHP 8.2+ if running locally without Docker

## Quick Start (Docker)
1. Start services:
```bash
docker compose up -d
```
2. Run database migrations:
```bash
docker compose exec php php bin/console doctrine:migrations:migrate -n
```
3. Create a Website record (replace values as needed):
```sql
INSERT INTO websites (name, domain, public_token) VALUES ('My Site', 'example.com', 'abc-123-def-456');
```
4. Configure using a single YAML file (recommended for on‑prem):
- Copy `config/aggregate.yaml.example` to `config/aggregate.yaml` and fill in your values.
- Values from `aggregate.yaml` are exported as environment variables at boot unless already set.

Example `config/aggregate.yaml`:
```yaml
database_url: "postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16"
messenger_transport_dsn: "doctrine://default"
daily_salt_secret: "change-me-to-a-random-string"
rate_limit_per_minute: 100
app_host: "https://analytics.example.com"
```

Alternatively, you can still use environment variables (compose, .env, or server env):
- DAILY_SALT_SECRET: a random string (required for anonymized IP hashing)
- MESSENGER_TRANSPORT_DSN: e.g. `doctrine://default` for DB-backed queue, or RabbitMQ/Redis DSN
- RATE_LIMIT_PER_MINUTE: defaults to 100 if not set

Example (Docker):
```bash
docker compose exec php bash -lc "export DAILY_SALT_SECRET=\"change-me\" && export MESSENGER_TRANSPORT_DSN=\"doctrine://default\" && php bin/console cache:clear"
```

5. Start the worker to process queued messages:
```bash
docker compose exec php php -d variables_order=EGPCS bin/console messenger:consume -vv
```

6. Test the API:
```bash
curl -i -X POST http://localhost/api/receive \
  -H "Origin: https://example.com" \
  -H "Content-Type: application/json" \
  -d '{
    "url":"https://example.com/pricing",
    "referrer":"https://google.com/",
    "screenWidth":1920,
    "eventName":"signup-click",
    "websiteToken":"abc-123-def-456",
    "eventData": {"plan_type":"pro","button_color":"blue"}
  }'
```
The endpoint responds immediately with 202 Accepted and the worker stores data.

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

## Security & Throttling
- Domain whitelisting: `/api/receive` checks the Origin/Referer against the `websites.domain` (subdomains allowed)
- Rate limiting: simple per-IP counter (100/min default) stored under `var/rate_limit/` (POC-friendly, replace with Symfony RateLimiter in production)

## Data Model (Simplified)
- websites: id, name, domain, public_token
- page_views: id, website_id, url, referrer, daily_ip_hash, generalized_user_agent, screen_width, created_at
- events: id, website_id, page_view_id, event_name, custom_data (JSON), created_at

## Notes
- IP hashing uses the current UTC date and `DAILY_SALT_SECRET` so raw IPs are never stored and hashes cannot be linked across days.
- The full User-Agent is never stored; only a generalized string like "Chrome on Desktop".
- Replace the naive file-based rate limiter and ensure a robust queue (RabbitMQ/Redis) for production.
