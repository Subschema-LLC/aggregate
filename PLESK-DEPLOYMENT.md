# Plesk Deployment Guide

This guide walks you through deploying Aggregate Analytics on a Plesk hosting environment.

## Prerequisites

- Plesk Obsidian 18.0.35+ or later
- PHP 8.2 or higher
- MySQL 8.0+ or PostgreSQL 13+
- SSH access to your server
- Composer installed

## Quick Start

```bash
# 1. Upload or git clone the project to your Plesk domain directory
cd ~/your-domain.com
git clone <repo-url> analytics
cd analytics

# 2. Run the setup script
./plesk-setup.sh

# 3. Configure Plesk domain settings (see below)

# 4. Create your first website
php bin/console app:create-website
```

## Detailed Setup

### 1. Domain and PHP Configuration

#### Set Document Root
1. Log into Plesk
2. Go to **Domains** > **your-domain.com** > **Apache & nginx Settings**
3. Set **Document root** to: `/var/www/vhosts/your-domain.com/analytics/public`
4. Click **OK**

#### Configure PHP Version
1. Go to **Domains** > **your-domain.com** > **PHP Settings**
2. Select **PHP 8.2** or higher
3. Ensure these extensions are enabled:
   - `ctype`
   - `iconv`
   - `pdo_mysql` or `pdo_pgsql`
   - `mbstring`
   - `xml`
   - `curl`
   - `intl`

### 2. Database Setup

#### Option A: Create via Plesk Panel
1. Go to **Databases** > **Add Database**
2. Database name: `analytics`
3. Create a database user with full privileges
4. Note the credentials

#### Option B: Use existing database
Use your existing Plesk database credentials.

#### Configure Database Connection
Edit `config/aggregate.yaml`:

```yaml
database_url: "mysql://username:password@localhost:3306/analytics?serverVersion=8.0"
# Or for PostgreSQL:
# database_url: "postgresql://username:password@localhost:5432/analytics?serverVersion=16"
```

### 3. Configuration

Copy and edit the configuration file:

```bash
cp config/aggregate.yaml.example config/aggregate.yaml
nano config/aggregate.yaml
```

#### Required Settings

**1. Generate a secure secret:**
```bash
openssl rand -base64 32
```
Add to `config/aggregate.yaml`:
```yaml
daily_salt_secret: "your-generated-secret-here"
```

**2. Database URL** (see step 2 above)

**3. Messenger Transport:**
For simple Plesk setup, use database-backed queue:
```yaml
messenger_transport_dsn: "doctrine://default?auto_setup=0"
```

#### Optional Settings

```yaml
rate_limit_per_minute: 100
app_host: "https://analytics.your-domain.com"
js_namespace: "Aggregate"
```

### 4. Run Setup Script

```bash
chmod +x plesk-setup.sh
./plesk-setup.sh
```

The script will:
- Check PHP version and dependencies
- Install Composer packages
- Create environment configuration
- Run database migrations
- Set up file permissions
- Compile frontend assets

### 5. Background Worker Setup

The analytics system requires a background worker to process events. Choose one option:

#### Option A: Using Plesk Scheduled Tasks (Simple)

1. Go to **Tools & Settings** > **Scheduled Tasks**
2. Add new task:
   - **Command**: `cd /var/www/vhosts/your-domain.com/analytics && php bin/console messenger:consume async --time-limit=3600`
   - **Run**: Every hour (or more frequently)
   - **Run as**: Your domain user

**Note**: This runs the worker for 1 hour, then restarts it. Not ideal for high traffic but works for small/medium sites.

#### Option B: Using systemd (Recommended for Production)

Create a systemd service file:

```bash
sudo nano /etc/systemd/system/analytics-worker.service
```

Content:
```ini
[Unit]
Description=Aggregate Analytics Worker
After=network.target

[Service]
Type=simple
User=your-plesk-user
WorkingDirectory=/var/www/vhosts/your-domain.com/analytics
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable analytics-worker
sudo systemctl start analytics-worker
sudo systemctl status analytics-worker
```

#### Option C: Using Supervisor (Alternative)

Install supervisor:
```bash
sudo apt-get install supervisor
```

Create configuration:
```bash
sudo nano /etc/supervisor/conf.d/analytics-worker.conf
```

Content:
```ini
[program:analytics-worker]
command=php /var/www/vhosts/your-domain.com/analytics/bin/console messenger:consume async --time-limit=3600
user=your-plesk-user
numprocs=1
autostart=true
autorestart=true
stderr_logfile=/var/www/vhosts/your-domain.com/analytics/var/log/worker.err.log
stdout_logfile=/var/www/vhosts/your-domain.com/analytics/var/log/worker.out.log
```

Reload supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start analytics-worker
```

### 6. Website Management

#### Create a Website
```bash
php bin/console app:create-website
```

Follow the prompts to enter:
- Website name
- Domain (e.g., `example.com`)

The command will output a public token. Save this token.

#### List Websites
```bash
php bin/console app:list-websites
```

### 7. Frontend Integration

Add to your website's HTML:

```html
<script>
  window.Aggregate = {
    endpoint: 'https://analytics.your-domain.com/api/receive',
    websiteToken: 'your-token-from-step-6'
  };
</script>
<script src="https://analytics.your-domain.com/aggregate.js" async></script>
```

### 8. Testing

#### Health Check
```bash
curl https://analytics.your-domain.com/api/health
```

Expected response:
```json
{"status":"ok","timestamp":"2024-01-15T10:30:00+00:00"}
```

#### Send Test Event
```bash
curl -X POST https://analytics.your-domain.com/api/receive \
  -H "Origin: https://example.com" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com/test",
    "referrer": "",
    "screenWidth": 1920,
    "eventName": "view",
    "websiteToken": "your-token-here"
  }'
```

#### Check Worker Logs
```bash
tail -f var/log/prod.log
```

## SSL/HTTPS Setup

### Option 1: Let's Encrypt (Recommended)
1. Go to **Domains** > **your-domain.com** > **SSL/TLS Certificates**
2. Click **Install** next to Let's Encrypt
3. Follow the wizard

### Option 2: Custom Certificate
Upload your SSL certificate through Plesk's SSL/TLS Certificates section.

## File Permissions

Ensure proper permissions:
```bash
cd /var/www/vhosts/your-domain.com/analytics
chmod -R 775 var/cache var/log
chown -R your-user:psacln var/cache var/log
```

## Maintenance

### Update Application
```bash
cd ~/your-domain.com/analytics
git pull
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
```

### Clear Cache
```bash
php bin/console cache:clear --env=prod
```

### Database Backup
Use Plesk's built-in database backup:
1. Go to **Databases** > **your-database**
2. Click **Export Dump**

Or via command line:
```bash
# MySQL
mysqldump -u username -p analytics > backup.sql

# PostgreSQL
pg_dump -U username analytics > backup.sql
```

## Troubleshooting

### 500 Internal Server Error
1. Check file permissions on `var/cache` and `var/log`
2. Check PHP error log: `/var/www/vhosts/your-domain.com/logs/error_log`
3. Check application log: `var/log/prod.log`

### Events not being processed
1. Check worker is running:
   ```bash
   # If using systemd:
   sudo systemctl status analytics-worker

   # If using supervisor:
   sudo supervisorctl status analytics-worker
   ```
2. Check worker logs: `var/log/prod.log`
3. Manually process queue:
   ```bash
   php bin/console messenger:consume async -vv
   ```

### Database connection errors
1. Verify credentials in `config/aggregate.yaml`
2. Test connection:
   ```bash
   php bin/console dbal:run-sql "SELECT 1"
   ```

### CORS issues
Ensure the domain in your website record matches the actual domain sending requests. Subdomains are automatically allowed.

## Performance Optimization

### OpCache
Enable in Plesk > PHP Settings:
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
```

After enabling, restart PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

### Database Indexing
Migrations include necessary indexes. For high-traffic sites, consider:
- Regular `ANALYZE` on tables
- Partitioning `page_views` table by date

## Security Checklist

- [ ] Use HTTPS (SSL certificate installed)
- [ ] Strong `daily_salt_secret` (32+ characters)
- [ ] Strong `APP_SECRET` in `.env.local`
- [ ] Database user has minimal required privileges
- [ ] `APP_DEBUG=0` in production
- [ ] File permissions properly set (no 777 except temporarily)
- [ ] Regular backups configured
- [ ] Worker process running as non-root user

## Support

For issues or questions:
- Check logs: `var/log/prod.log`
- GitHub Issues: [repository URL]
- Documentation: README.md

## Production Checklist

Before going live:

- [ ] SSL certificate installed and working
- [ ] Domain configured correctly in Plesk
- [ ] PHP 8.2+ with required extensions
- [ ] Database created and migrated
- [ ] `config/aggregate.yaml` fully configured
- [ ] `.env.local` created with `APP_ENV=prod`
- [ ] Background worker running
- [ ] First website created
- [ ] Test event successfully tracked
- [ ] Health endpoint responding
- [ ] File permissions correct
- [ ] Backups configured
- [ ] Error logs monitored
