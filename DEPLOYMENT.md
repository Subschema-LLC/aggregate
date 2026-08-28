# Deployment Guide

This guide covers both Docker and native (non-Docker) deployment methods for Aggregate Analytics.

## Table of Contents

- [Docker Deployment](#docker-deployment)
- [Native Deployment](#native-deployment)
- [Web Server Configuration](#web-server-configuration)
- [Worker Process Setup](#worker-process-setup)
- [Production Considerations](#production-considerations)

---

## Docker Deployment

### Quick Start

```bash
# Clone repository
git clone <repo-url>
cd aggregate-sy

# Copy app configuration (privacy controls, rate limit, JS namespace)
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml

# Start services
make start-mysql

# Run migrations
make migrate-mysql
```

Optional dashboard setup: open `http://localhost/install` to create an admin user. After install, admins can add users and reset passwords from Dashboard Settings.

If you use generic profile commands (`make start DOCKER_PROFILE=...`), set `DOCKER_DATABASE_URL` to a matching DSN.
Wrapper targets (`make start-postgres`, `make start-mariadb`) set sensible defaults automatically.

To enable optional coarse geography in Docker, obtain and update a GeoLite2-Country-compatible MMDB yourself and mount it read-only into the `php` service, for example in `compose.override.yaml`:

```yaml
services:
  php:
    volumes:
      - /srv/geoip/GeoLite2-Country.mmdb:/var/lib/GeoIP/GeoLite2-Country.mmdb:ro
```

Then set `anonymous_geo_enabled: true` and `anonymous_geo_database_path: /var/lib/GeoIP/GeoLite2-Country.mmdb` in `config/aggregate.yaml`. Aggregate does not download the database or use a hosted lookup API. A missing/unreadable database leaves geography empty and does not stop ingestion.

### Docker Architecture

- **php**: FrankenPHP web server
- **worker**: Background job processor
- **database**: selected profile (`mysql`, `postgres`, or `mariadb`)
- **asset-compile**: Frontend asset compilation

### Docker Commands

All `make` commands work automatically in Docker mode:
- `make start-mysql` - Start containers with MySQL profile
- `make start-postgres` - Start containers with PostgreSQL profile
- `make start-mariadb` - Start containers with MariaDB profile
- `make stop` - Stop containers
- `make logs` - View logs
- `make migrate-mysql` - Run migrations with MySQL profile
- `make migrate-postgres` - Run migrations with PostgreSQL profile
- `make migrate-mariadb` - Run migrations with MariaDB profile
- `make create-website` - Create tracking website

---

## Native Deployment

Native deployment runs directly on your server without Docker. Ideal for shared hosting, VPS, or when you want full control.

### Requirements

- PHP 8.2+ with extensions: `ctype`, `iconv`, `pdo`, `mbstring`, `xml`, `curl`, `intl`
- PostgreSQL 13+, MySQL 8.0+, MariaDB 10.6+, Microsoft SQL Server 2017+, or SQLite 3.25+
- Composer
- Web server (Nginx, Apache, or FrankenPHP)
- Process supervisor (systemd, Supervisor, or cron)

### Quick Install

```bash
# Clone repository
git clone <repo-url>
cd aggregate-sy

# Run interactive installer
chmod +x install.sh
./install.sh
```

The installer will:
1. Check PHP version and extensions
2. Install Composer dependencies
3. Generate secure secrets
4. Configure database
5. Run migrations
6. Configure ingestion mode (`sync://` quick mode or async queue mode)
7. Create first website

### Manual Installation

```bash
# 1. Install dependencies
composer install --no-dev --optimize-autoloader

# 2. Create .env in project root (never committed to git)
#    This is your server's environment file.
cat > .env <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-me-use-openssl-rand-hex-32
DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN=sync://   # default quick mode (no worker required)
MAILER_DSN=null://null
EOF

# Async scale-up mode (worker required):
# MESSENGER_TRANSPORT_DSN=doctrine://default

# 3. Copy app configuration
cp config/aggregate.yaml.example config/aggregate.yaml
# Review rate_limit_per_minute, app_host, js_namespace, dashboard_enabled,
# anonymous_tracking_enabled, anonymous_excluded_paths, and the optional
# anonymous_geo_* coarse-geography controls
# Optional API-only mode: set DASHBOARD_ENABLED=0 in .env and run php bin/console cache:clear

# 4. Run migrations
php bin/console doctrine:migrations:migrate -n

# 5. Compile assets
php bin/console asset-map:compile

# 6. Set permissions
chmod -R 775 var/
chown -R www-data:www-data var/ public/

# 7. Optional: open https://your-domain.com/install to create
#    a dashboard admin user and update dashboard settings.
#    After install, manage additional users/passwords in Dashboard Settings.
#    CLI alternative: php bin/console app:install
```

For optional coarse geography on a native deployment, place a current GeoLite2-Country-compatible MMDB on the local filesystem outside `public/`, make it read-only and readable by the PHP ingestion process, then configure its absolute path. Direct UNC/network-share and Windows device paths are rejected. Do not give the application write access to the database file.

When a reverse proxy sits in front of PHP, list only its exact IP addresses or narrow CIDRs in the comma-separated `TRUSTED_PROXIES` environment variable, for example `TRUSTED_PROXIES=127.0.0.1,10.20.30.0/24`. The proxy must strip or overwrite client-supplied `X-Forwarded-*` headers before setting its own. Leave the variable blank for direct deployments. Never use `0.0.0.0/0`, `::/0`, or the Symfony `REMOTE_ADDR` shortcut: an overly broad or unsanitized trust boundary lets clients spoof forwarding headers. Without correct proxy trust, coarse geography will normally describe the proxy or remain empty rather than the visitor.

---

## Web Server Configuration

### Nginx

**1. Copy configuration:**
```bash
sudo cp docs/nginx/aggregate-analytics.conf /etc/nginx/sites-available/analytics
sudo ln -s /etc/nginx/sites-available/analytics /etc/nginx/sites-enabled/
```

**2. Edit configuration:**
```nginx
server {
    listen 80;
    server_name analytics.example.com;  # Change this
    root /var/www/aggregate-sy/public;  # Change this

    # ... rest of config
}
```

**3. Test and reload:**
```bash
sudo nginx -t
sudo systemctl reload nginx
```

**Important settings:**
- `server_name`: Your domain
- `root`: Path to `public/` directory
- `fastcgi_pass`: PHP-FPM socket (usually `/var/run/php/php8.2-fpm.sock`)

### Apache

**1. Copy configuration:**
```bash
sudo cp docs/apache/aggregate-analytics.conf /etc/apache2/sites-available/analytics.conf
sudo a2ensite analytics
sudo a2enmod rewrite headers
```

**2. Edit configuration:**
```apache
<VirtualHost *:80>
    ServerName analytics.example.com    # Change this
    DocumentRoot /var/www/aggregate-sy/public  # Change this

    # ... rest of config
</VirtualHost>
```

**3. Reload:**
```bash
sudo systemctl reload apache2
```

### FrankenPHP (Standalone)

For native FrankenPHP without Docker:

```bash
# Download
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o frankenphp
chmod +x frankenphp

# Run
./frankenphp php-server --root public/
```

---

## Worker Process Setup

The worker process handles background analytics event processing.
It is required only when `MESSENGER_TRANSPORT_DSN` is async (for example `doctrine://default`, AMQP, Redis).
If you use `MESSENGER_TRANSPORT_DSN=sync://`, no worker is needed.

### Option 1: systemd (Recommended)

**1. Copy service file:**
```bash
sudo cp docs/systemd/aggregate-worker.service /etc/systemd/system/
```

**2. Edit service file:**
```ini
[Service]
User=www-data              # Change if needed
WorkingDirectory=/var/www/aggregate-sy  # Change to your path
ExecStart=/usr/bin/php /var/www/aggregate-sy/bin/console messenger:consume async --time-limit=3600
```

**3. Enable and start:**
```bash
sudo systemctl daemon-reload
sudo systemctl enable aggregate-worker
sudo systemctl start aggregate-worker
sudo systemctl status aggregate-worker
```

**Manage service:**
```bash
sudo systemctl stop aggregate-worker
sudo systemctl restart aggregate-worker
sudo journalctl -u aggregate-worker -f  # View logs
```

### Option 2: Supervisor

**1. Install Supervisor:**
```bash
# Debian/Ubuntu
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor
```

**2. Copy configuration:**
```bash
sudo cp docs/supervisor/aggregate-worker.conf /etc/supervisor/conf.d/
```

**3. Edit configuration:**
```ini
[program:aggregate-worker]
command=/usr/bin/php /var/www/aggregate-sy/bin/console messenger:consume async
user=www-data
directory=/var/www/aggregate-sy
```

**4. Reload Supervisor:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start aggregate-worker:*
```

**Manage workers:**
```bash
sudo supervisorctl status
sudo supervisorctl stop aggregate-worker:*
sudo supervisorctl restart aggregate-worker:*
sudo supervisorctl tail -f aggregate-worker
```

### Option 3: Cron (Simple but Less Reliable)

Add to crontab (`crontab -e`):

```cron
* * * * * cd /var/www/aggregate-sy && php bin/console messenger:consume async --time-limit=60 >> var/log/worker.log 2>&1
```

**Note:** This runs every minute for 60 seconds, which may lead to overlapping processes or gaps in processing.

---

## Production Considerations

### Security

**1. Use HTTPS:**
Configure SSL/TLS certificates (Let's Encrypt recommended):
```bash
# For Nginx with Certbot
sudo apt-get install certbot python3-certbot-nginx
sudo certbot --nginx -d analytics.example.com
```

**2. Secure secrets:**
- Never commit `.env` (server-specific env file) to version control — it is gitignored by default
- `config/aggregate.yaml` should not contain production secrets if committed; use the `environments:` structure and keep the example file committed only
- Use a unique, strong `APP_SECRET` in the server environment
- Review anonymous path exclusions and configure both database-backed BI minimum-cell thresholds in the admin dashboard before collection (`5` hourly; `25` daily geography defaults)
- Review every code in `config/goals.yaml`; keep values fixed and non-identifying, and set `anonymous: false` where enhanced consent is appropriate
- Leave coarse geography disabled unless its purpose, legal basis, notice, traffic volume, and local MMDB lifecycle have been reviewed
- Rotate secrets periodically

**3. Database access:**
- Use strong database passwords
- Restrict database access to localhost if possible
- On PostgreSQL/MySQL/MariaDB/SQL Server, grant routine BI users access only to the approved `bi_anonymous_events_v1`, `bi_anonymous_goals_v1`, and, when needed, `bi_anonymous_geo_events_v1` views—not the private raw `events` table
- SQLite cannot enforce view-only grants; never distribute its database file to routine BI users—export approved view results or use a server database for direct BI access
- Regular backups

**4. File permissions:**
```bash
# Application files: read-only for web server
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Writable directories
chmod -R 775 var/
chown -R www-data:www-data var/ public/
```

### Performance

**1. Use production environment:**
```bash
# In .env (your server's environment file)
APP_ENV=prod
APP_DEBUG=0
```

**2. Enable OPcache:**
```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0  ; Disable for production
```

**3. Use PostgreSQL or MySQL:**
SQLite is fine for small deployments, but use PostgreSQL or MySQL for production.

**4. Use Redis or RabbitMQ for messaging:**
```bash
# In .env
MESSENGER_TRANSPORT_DSN="amqp://user:pass@rabbitmq:5672/%2f/messages"
# or
MESSENGER_TRANSPORT_DSN="redis://localhost:6379/messages"
```

**5. Scale workers:**
Run multiple worker processes (with Supervisor or systemd):
```ini
; Supervisor: set numprocs
numprocs=4

; systemd: create multiple service instances
sudo systemctl start aggregate-worker@1
sudo systemctl start aggregate-worker@2
```

### Monitoring

**1. Health check:**
```bash
curl https://analytics.example.com/api/health
```

**2. Worker monitoring:**
```bash
# systemd
sudo systemctl status aggregate-worker

# Supervisor
sudo supervisorctl status

# Check message queue
php bin/console messenger:stats
```

**3. Logs:**
```bash
# Application logs (prod uses php://stderr by default)
tail -f /var/www/vhosts/your-domain.com/logs/error_log
tail -f /var/www/vhosts/your-domain.com/logs/proxy_error_log

# Worker logs
tail -f var/log/worker.log

# Web server logs
tail -f /var/log/nginx/aggregate_error.log
```

**4. Database monitoring:**
```sql
-- Use the grouped/suppressed BI contract for anonymous-mode monitoring
SELECT * FROM bi_anonymous_events_v1 ORDER BY event_hour DESC;

-- Use the completed-day, suppressed conversion contract
SELECT * FROM bi_anonymous_goals_v1 ORDER BY event_day DESC, goal_event;

-- Use the lower-dimensional, daily suppressed geography contract
SELECT * FROM bi_anonymous_geo_events_v1 ORDER BY event_day DESC;

-- MySQL/MariaDB example: recent enhanced events (private table)
SELECT COUNT(*) FROM events
WHERE privacy_mode = 'enhanced' AND created_at > NOW() - INTERVAL 1 HOUR;

-- Website registration is stored in config/websites.yaml, not a database table.
```

### Backup

**1. Database backups:**
```bash
# PostgreSQL
pg_dump -U dbuser dbname > backup_$(date +%Y%m%d).sql

# MySQL
mysqldump -u dbuser -p dbname > backup_$(date +%Y%m%d).sql
```

**2. Application backups:**
```bash
# Backup config (include deployment-specific settings — keep secure!)
tar -czf config_backup_$(date +%Y%m%d).tar.gz .env config/aggregate.yaml config/goals.yaml

# Full backup (exclude vendor and cache)
tar -czf app_backup_$(date +%Y%m%d).tar.gz \
    --exclude='var/cache' \
    --exclude='var/log' \
    --exclude='vendor' \
    .
```

**3. Automated backups:**
Add to crontab:
```cron
# Daily database backup at 2 AM
0 2 * * * /path/to/backup-script.sh
```

### Updates

For upgrades that include the `Version20260724*` privacy migrations, pause `/api/receive` and stop all async workers before the steps below. The migrations permanently remove daily IP hashes, legacy non-granted event rows, and matching Doctrine-queue tracker envelopes. Inspect failed, external, and encoded/base64 queue transports separately before resuming ingestion.

```bash
# 1. Backup first!

# 2. Stop async workers (skip this command when using sync://) and pause the collection endpoint at the proxy
sudo systemctl stop aggregate-worker

# 3. Pull latest code
git pull

# 4. Install dependencies
composer install --no-dev --optimize-autoloader

# 5. Run migrations
php bin/console doctrine:migrations:migrate -n

# 6. Clear cache
php bin/console cache:clear --env=prod

# 7. Compile assets
php bin/console asset-map:compile

# 8. Restart the worker when using async mode, then resume the collection endpoint
sudo systemctl restart aggregate-worker
```

---

## Troubleshooting

### Worker not processing events

```bash
# Check worker is running
sudo systemctl status aggregate-worker  # systemd
sudo supervisorctl status               # Supervisor

# Check message queue
php bin/console messenger:stats

# Manually consume messages
php bin/console messenger:consume async -vv
```

### Permission errors

```bash
chmod -R 775 var/
chown -R www-data:www-data var/ public/
```

### Database connection errors

- Verify `DATABASE_URL` in your `.env` file
- Check database is running
- Test connection: `php bin/console doctrine:query:sql "SELECT 1"`
- Note: Special characters in passwords must be URL-encoded (`%` → `%25`, `@` → `%40`)

### 500 errors

```bash
# Check logs
tail -f /var/www/vhosts/your-domain.com/logs/error_log
tail -f /var/www/vhosts/your-domain.com/logs/proxy_error_log

# Check web server logs
tail -f /var/log/nginx/error.log

# Clear cache
php bin/console cache:clear
```

---

## Support

- Documentation: See `README.md` for full documentation
- Configuration examples: See `docs/` directory
- Issues: GitHub issues page
