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
4. Configure environment variables (compose, .env, or server env):
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
