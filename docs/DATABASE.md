# Database Configuration Guide

Aggregate Analytics supports **PostgreSQL**, **MySQL**, **MariaDB**, **Microsoft SQL Server**, and **SQLite**.

## Table of Contents

- [Quick Reference](#quick-reference)
- [PostgreSQL](#postgresql)
- [MySQL](#mysql)
- [MariaDB](#mariadb)
- [Microsoft SQL Server](#microsoft-sql-server)
- [SQLite](#sqlite)
- [Migration and Compatibility](#migration-and-compatibility)
- [Upgrade to Doctrine DBAL 4](#upgrade-to-doctrine-dbal-4)
- [BI glossary metadata](#bi-glossary-metadata)
- [Custom data reporting views](#custom-data-reporting-views)
- [Analytics Archive and Maintenance](#analytics-archive-and-maintenance)

---

## Quick Reference

Set `DATABASE_URL` in your `.env` or `.env.local` file (project root, not committed to git):

```dotenv
DATABASE_URL="DATABASE_CONNECTION_STRING_HERE"
```

### Connection String Format

| Database | Connection String |
|----------|------------------|
| **PostgreSQL** | `postgresql://user:pass@host:5432/dbname?serverVersion=16` |
| **MySQL** | `mysql://user:pass@host:3306/dbname?serverVersion=8.0.0` |
| **MariaDB** | `mysql://user:pass@host:3306/dbname?serverVersion=11.4.0-MariaDB` |
| **SQL Server** | `sqlsrv://user:pass@host:1433/dbname?serverVersion=2022` |
| **SQLite** | `sqlite:///%kernel.project_dir%/var/data.db` |

---

## PostgreSQL

**Recommended for production deployments.**

### Prerequisites

**PHP Extension:**
```bash
# Debian/Ubuntu
sudo apt-get install php8.2-pgsql

# CentOS/RHEL
sudo yum install php82-pgsql
```

**PostgreSQL Server:**
```bash
# Debian/Ubuntu
sudo apt-get install postgresql postgresql-contrib

# CentOS/RHEL
sudo yum install postgresql-server postgresql-contrib
```

### Setup

**1. Create database and user:**
```sql
-- Connect as postgres user
sudo -u postgres psql

-- Create database
CREATE DATABASE analytics;

-- Create user
CREATE USER analytics_user WITH PASSWORD 'your_secure_password';

-- Grant privileges
GRANT ALL PRIVILEGES ON DATABASE analytics TO analytics_user;

-- PostgreSQL 15+ requires additional grant
\c analytics
GRANT ALL ON SCHEMA public TO analytics_user;
```

**2. Configure connection in `.env`:**
```dotenv
DATABASE_URL="postgresql://analytics_user:your_secure_password@localhost:5432/analytics?serverVersion=16"
```

**3. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync
```

### Version Support

| PostgreSQL Version | serverVersion Parameter |
|-------------------|------------------------|
| PostgreSQL 16 | `?serverVersion=16` |
| PostgreSQL 15 | `?serverVersion=15` |
| PostgreSQL 14 | `?serverVersion=14` |
| PostgreSQL 13 | `?serverVersion=13` |

### Performance Tuning

**Indexes (automatically created by migrations):**
- `events.session_id`
- `events.visitor_id`
- `events.consent_state`
- `events (privacy_mode, website_token, created_at)`

**Recommended settings for analytics workload:**
```ini
# postgresql.conf
shared_buffers = 256MB
work_mem = 16MB
maintenance_work_mem = 128MB
effective_cache_size = 1GB
random_page_cost = 1.1  # For SSD
```

---

## MySQL

**Widely supported, good for most deployments.**

### Prerequisites

**PHP Extension:**
```bash
# Debian/Ubuntu
sudo apt-get install php8.2-mysql

# CentOS/RHEL
sudo yum install php82-mysqlnd
```

**MySQL Server:**
```bash
# Debian/Ubuntu
sudo apt-get install mysql-server

# CentOS/RHEL
sudo yum install mysql-server
```

### Setup

**1. Create database and user:**
```sql
-- Connect as root
mysql -u root -p

-- Create database
CREATE DATABASE analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user
CREATE USER 'analytics_user'@'localhost' IDENTIFIED BY 'your_secure_password';

-- Grant privileges
GRANT ALL PRIVILEGES ON analytics.* TO 'analytics_user'@'localhost';
FLUSH PRIVILEGES;
```

**2. Configure connection in `.env`:**
```dotenv
DATABASE_URL="mysql://analytics_user:your_secure_password@localhost:3306/analytics?serverVersion=8.0.0"
```

**3. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync
```

### Version Support

| MySQL Version | serverVersion Parameter |
|--------------|------------------------|
| MySQL 8.4 | `?serverVersion=8.4.0` |
| MySQL 8.0 | `?serverVersion=8.0.0` |

Include the patch component, preferably the installed version returned by
`SELECT VERSION()`. For example, use `8.0.43` rather than `8.0`; Doctrine DBAL 4
compares the complete version when selecting MySQL's SQL platform.

### Important Notes

- **Character Set:** Use `utf8mb4` for full Unicode support
- **InnoDB Engine:** Required for foreign key constraints (default in MySQL 8+)
- **Connection Limits:** Adjust `max_connections` if running multiple workers

---

## MariaDB

**Drop-in MySQL replacement with enhanced features.**

### Prerequisites

**PHP Extension:**
```bash
# Same as MySQL
sudo apt-get install php8.2-mysql
```

**MariaDB Server:**
```bash
# Debian/Ubuntu
sudo apt-get install mariadb-server

# CentOS/RHEL
sudo yum install mariadb-server
```

### Setup

**1. Create database and user:**
```sql
-- Same as MySQL
mysql -u root -p

CREATE DATABASE analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'analytics_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON analytics.* TO 'analytics_user'@'localhost';
FLUSH PRIVILEGES;
```

**2. Configure connection in `.env`:**

**Important:** The `serverVersion` must identify MariaDB rather than MySQL.
Prefer the version format reported by `SELECT VERSION()`, retaining the
`MariaDB` marker. Do not use a MySQL value such as `8.0` for a MariaDB server.

```dotenv
DATABASE_URL="mysql://analytics_user:your_secure_password@localhost:3306/analytics?serverVersion=11.4.0-MariaDB"
```

**3. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync
```

### Version Support

| MariaDB Version | serverVersion Parameter |
|----------------|------------------------|
| MariaDB 11.4 | `?serverVersion=11.4.0-MariaDB` |
| MariaDB 11.0 | `?serverVersion=11.0.0-MariaDB` |
| MariaDB 10.11 | `?serverVersion=10.11.0-MariaDB` |
| MariaDB 10.6 | `?serverVersion=10.6.0-MariaDB` |

Use the installed patch version when known. For example, a server reporting
`10.11.14-MariaDB-0+deb12u2` should use
`?serverVersion=10.11.14-MariaDB`.

If a migration fails near `CHARACTER SET utf8mb4 DEFAULT 'NULL' COLLATE
utf8mb4_bin`, Doctrine has usually selected its MySQL platform for a MariaDB
server. Correct `DATABASE_URL`, clear the production cache, and rerun the
migration. The failed migration is not recorded as complete.

### Differences from MySQL

- **JSON support:** MariaDB uses `LONGTEXT` for JSON columns (functionally identical)
- **Sequences:** MariaDB has native sequence support (not used by this app)
- **Performance:** Often faster for read-heavy workloads

---

## Microsoft SQL Server

**Enterprise-grade database for Windows environments.**

### Prerequisites

**PHP Extension:**

```bash
# Install Microsoft ODBC Driver
# Ubuntu/Debian
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 mssql-tools18

# Install PHP drivers
sudo pecl install sqlsrv pdo_sqlsrv

# Enable extensions
echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/8.2/mods-available/pdo_sqlsrv.ini
echo "extension=sqlsrv.so" | sudo tee /etc/php/8.2/mods-available/sqlsrv.ini
sudo phpenmod pdo_sqlsrv sqlsrv
```

**SQL Server:**
- SQL Server 2019+
- Or Azure SQL Database
- Or SQL Server on Linux

### Setup

**1. Create database and user:**
```sql
-- Connect with SQL Server Management Studio or sqlcmd
sqlcmd -S localhost -U sa -P YourPassword

-- Create database
CREATE DATABASE analytics;
GO

-- Create user
USE analytics;
GO
CREATE LOGIN analytics_user WITH PASSWORD = 'YourSecurePassword123!';
CREATE USER analytics_user FOR LOGIN analytics_user;
ALTER ROLE db_owner ADD MEMBER analytics_user;
GO
```

**2. Configure connection in `.env`:**
```dotenv
DATABASE_URL="sqlsrv://analytics_user:YourSecurePassword123!@localhost:1433/analytics?serverVersion=2022"
```

**3. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync
```

### Version Support

| SQL Server Version | serverVersion Parameter |
|-------------------|------------------------|
| SQL Server 2022 | `?serverVersion=2022` |
| SQL Server 2019 | `?serverVersion=2019` |
| SQL Server 2017 | `?serverVersion=2017` |
| Azure SQL | `?serverVersion=2019` (or latest) |

### Connection String Options

**Named Instance:**
```dotenv
DATABASE_URL="sqlsrv://user:pass@localhost\\INSTANCENAME:1433/dbname?serverVersion=2022"
```

**Integrated Authentication (Windows):**
```dotenv
DATABASE_URL="sqlsrv://localhost:1433/analytics?serverVersion=2022&TrustServerCertificate=yes"
```

**Azure SQL Database:**
```dotenv
DATABASE_URL="sqlsrv://user@server:pass@servername.database.windows.net:1433/analytics?serverVersion=2019&Encrypt=yes"
```

### Troubleshooting

**Common Issues:**

1. **Driver not found:**
   ```bash
   php -m | grep -i sqlsrv
   # Should show: pdo_sqlsrv, sqlsrv
   ```

2. **SSL/TLS errors:**
   Add `TrustServerCertificate=yes` to connection string

3. **Connection timeout:**
   Add `ConnectionTimeout=30` to connection string

---

## SQLite

**Simple file-based database for development and small deployments.**

SQLite 3.25 or newer is required. The anonymous geography BI view uses common
table expressions and window functions.

### Prerequisites

**PHP Extension (usually included):**
```bash
# Verify
php -m | grep -i sqlite

# If missing (Debian/Ubuntu)
sudo apt-get install php8.2-sqlite3
```

### Setup

**1. Configure connection in `.env`:**
```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

**2. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync
```

The database file will be created automatically at `var/data.db`.

### Advantages

- ✅ No server setup required
- ✅ Zero configuration
- ✅ Perfect for development
- ✅ Great for small sites (<100k views/day)

### Limitations

- ❌ No concurrent writes (single writer)
- ❌ Limited for high-traffic sites
- ❌ No network access (file-based only)
- ❌ No SQL users or table/view grants; anyone with the file can query raw `events`
- ❌ Weaker type system

### When to Use

- Development environments
- Testing
- Small personal sites
- Prototyping

### When NOT to Use

- Production sites with >10k daily views
- Multiple worker processes
- High concurrency requirements

---

## Migration and Compatibility

### Upgrade to Doctrine DBAL 4

Before installing a release that uses Doctrine DBAL 4, check the `serverVersion`
in your active `DATABASE_URL`, including server environment variables and local
environment overrides. Preserve the connection's credentials, host, database,
and other options; update only the version hint when needed:

| Existing hint | Replacement example |
| --- | --- |
| MySQL `8.0` | `8.0.0`, or the installed full patch version |
| MySQL `8.4` | `8.4.0`, or the installed full patch version |
| MariaDB `mariadb-11.4` | `11.4.0-MariaDB`, or the installed full version with its `MariaDB` marker |

Read the actual server version with `SELECT VERSION()` when possible. Do not
label a MariaDB connection as MySQL. A short MySQL version can select the wrong
platform and cause the geographic/archive migrations to reject the connection;
a MariaDB version without its patch component cannot be parsed. PostgreSQL,
SQL Server, and SQLite connection examples above continue to apply.

After updating the active environment configuration, clear the application
cache before running migrations:

```bash
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --env=prod -n
php bin/console app:analytics:glossary:sync --env=prod
```

For Compose, `MYSQL_VERSION` selects the image tag (default `8.0`), while
`MYSQL_SERVER_VERSION` supplies the full Doctrine version hint (default
`8.0.0`). Set both when changing the MySQL version, or provide the complete
`DOCKER_DATABASE_URL`. The Makefile profile helpers use their own
`MYSQL_DOCKER_DSN` / `MARIADB_DOCKER_DSN` overrides; keep their full version hints
aligned with the selected server image.

### Cross-Database Migrations

Table and column migrations use Doctrine DBAL's schema API. BI-view migrations select explicit UTC/date SQL for each supported database platform and fail rather than silently creating an unsafe view on an unsupported platform or version.

Current migrations:

- `migrations/Version20260330000000.php` (baseline)
- `migrations/Version20260330010000.php` (schema-neutral compatibility marker)
- `migrations/Version20260529000000.php` (canonical `events.consent_state` migration)
- `migrations/Version20260724000000.php` (unified anonymous/enhanced event privacy modes and permanent removal of daily IP hashes)
- `migrations/Version20260724000250.php` (anonymous fail-safe default for newly inserted rows)
- `migrations/Version20260724000500.php` (purge legacy non-granted enhanced rows and queued Doctrine tracker envelopes)
- `migrations/Version20260724001000.php` (hourly grouped and threshold-filtered anonymous BI view)
- `migrations/Version20260724001500.php` (optional coarse `events.geo_area` and geographic suppression setting)
- `migrations/Version20260724002000.php` (daily, threshold-filtered anonymous geography BI view)
- `migrations/Version20260828000000.php` (completed-day, threshold-filtered anonymous goal BI view)
- `migrations/Version20260901000000.php` (private archives, lifecycle maintenance, and combined live/archive BI views)
- `migrations/Version20260928000000.php` (declared BI glossary table and eight fixed metadata views)

The current schema contains:
- `events` (private individual rows for both `anonymous` and `enhanced` privacy modes, with optional coarse `geo_area` and allowlisted `goal_event`)
- `analytics_privacy_settings` (database source of truth for hourly event, daily goal, and geographic BI suppression thresholds)
- `analytics_glossary` (private synchronized metadata table; never granted to routine BI users)
- `bi_dim_event_name_v1`, `bi_dim_goal_event_v1`, `bi_dim_referrer_channel_v1`, `bi_dim_device_class_v1`, `bi_dim_viewport_bucket_v1`, `bi_dim_geo_area_v1`, `bi_glossary_values_v1`, and `bi_glossary_columns_v1` (approved declared-metadata views)
- `bi_anonymous_events_v1` (supported grouped anonymous-mode BI contract)
- `bi_anonymous_goals_v1` (supported completed-day anonymous goal-count BI contract)
- `bi_anonymous_geo_events_v1` (supported daily, lower-dimensional anonymous geography BI contract)
- `analytics_custom_events_v1`, `analytics_custom_pageviews_v1`, and `analytics_custom_goals_v1` (optional, generated private views over retained raw rows)
- `users` (dashboard auth, optional in API-only mode)
- `messenger_messages` (used only in async queue mode)

For `privacy_mode = 'anonymous'`, `created_at` is a server-generated UTC hour boundary, not an exact event time. Identifiers, exact dimensions, and generalized User-Agent values are null. The existing `custom_data` JSON may contain properties explicitly configured with `consent_required: false` and the organization marker under its configured cookie/local storage name (default `{"orgInternalTraffic": true}`); a missing marker key means unmarked traffic. Enhanced rows may retain other scalar properties alongside this marker. Collection requires no schema migration. `goal_event` may contain only an enabled code from `config/goals.yaml` whose definition permits anonymous use; rejected goals are stored as null without rejecting the underlying event. These are still individual rows and may be personal data in context, so keep `events` private. Routine BI users should query `bi_anonymous_events_v1`, which groups hourly cells and suppresses counts below `anonymous_min_cell_count`.

Existing `bi_anonymous_*` views and archive tables omit `custom_data`, combine internal and unmarked traffic, and cannot filter this flag. For internal-traffic filtering, model the marker as a column in the separate custom reporting views or prepare an approved export from retained raw JSON before aggregation and disclosure checks. The flag and other custom properties are lost once raw rows are deleted. See [organization traffic in the compliance guide](PRIVACY-COMPLIANCE.md#organization-traffic) for browser setup and SQL expressions for each database.

Routine anonymous conversion reporting should use `bi_anonymous_goals_v1`: `website_token`, UTC `event_day`, `goal_event`, and `event_count`. It includes only anonymous rows with a retained goal, excludes the current UTC day, and reuses `anonymous_min_cell_count` (default `5`, range `2`–`1000`). It deliberately omits event name, path, referrer, device, viewport, geography, and identifiers. `event_count` counts goal occurrences rather than unique people or unique converters, and goal labels remain presentation metadata in `config/goals.yaml`. Do not join this completed-day view to the hourly or geography views to recover more detail.

When optional coarse geography is enabled, `events.geo_area` contains a normalized `continent:XX` or `country:XX` value for accepted anonymous and enhanced events. It never contains city, subdivision, postcode, or coordinates. Null means geography was disabled or unavailable. The source IP and full local-MMDB result are not stored or queued.

Routine anonymous geography reporting should use `bi_anonymous_geo_events_v1`: `website_token`, UTC `event_day`, `event_name`, `geo_area`, and `event_count`. It excludes the current UTC day and applies `anonymous_geo_min_cell_count` (default `25`, range `10`–`1000`). It has no path, referrer, device, viewport, or identifier dimensions. Low-volume areas are combined into a thresholded `<level>:other` pool; when only one area is below threshold, the smallest visible area joins that pool as secondary suppression. The threshold counts events rather than distinct people, so the view reduces disclosure and direct-subtraction risk but does not establish legal anonymity or k-anonymity.

For an upgrade from an older release, pause ingestion and stop async workers before running the `Version20260724*` migrations. The daily hashes, legacy non-granted rows, and matching Doctrine queue envelopes cannot be restored. Plain-text `LIKE` cleanup cannot guarantee removal from failed, external, or encoded/base64 queue transports; inspect and purge those separately. Rows already marked `granted` remain, including any rows for which an older release inferred consent from a session ID, so audit their provenance and purge them when it cannot be established.

```php
$events = $schema->createTable('events');
$events->addColumn('website_token', 'string', ['length' => 191]);
$events->addColumn('privacy_mode', 'string', ['length' => 20, 'default' => 'anonymous']);
$events->addColumn('device_class', 'string', ['length' => 20, 'default' => 'unknown']);
$events->addColumn('viewport_bucket', 'string', ['length' => 20, 'default' => 'unknown']);
$events->addColumn('geo_area', 'string', ['length' => 16, 'notnull' => false]);
$events->addColumn('consent_state', 'string', ['length' => 20, 'notnull' => false]);
$events->addColumn('custom_data', 'json', ['notnull' => false]);
$events->addColumn('goal_event', 'string', ['length' => 191, 'notnull' => false]);
// ... works across supported databases
```

### BI glossary metadata

The [BI glossary](BI-GLOSSARY.md) supplies declared value labels and column
explanations through eight fixed views. Migration `Version20260928000000` creates
one backing table with a composite `(entry_type, subject, code, locale)` primary
key and an `(entry_type, subject, is_default_locale)` index. Case-sensitive key
collations preserve distinct declared codes on MySQL/MariaDB and SQL Server.
Its remaining fields are `label`, `label_locale`, `group_label`, `description`,
`description_locale`, `sort_order`, `source`, and `synced_at`.

Run `php bin/console app:analytics:glossary:sync` after migrations and declared
metadata changes. Sync resolves all published locales from YAML, configured
goals, the saved custom model, and the built-in catalog. It never queries events,
archives, or reporting facts. The initial migration leaves views empty. Changed
rows are replaced in one DML transaction on all supported engines; unchanged
syncs leave timestamps alone. Sync needs no view-creation privileges.

The six `bi_dim_*_v1` views have one row per code in the default locale. The two
`bi_glossary_*_v1` views expose localized value and column metadata. Grant those
views, never `analytics_glossary`; see the complete
[view-only grant examples](BI-GLOSSARY.md#view-only-grants). Labels do not expand
the anonymous BI facts or make private custom views safe for routine access.

### Custom data reporting views

Define custom properties, query parameter mappings, per-property consent, JSON value types, and SQL column names through **Collection → Data model** or YAML. The standard six UTM properties require consent by default. Each may be permitted separately; the recommendation for anonymous reporting is no more detail than `utm_medium`, with fixed channel values such as `email`, `social`, or `cpc`. This is advisory. Review actual values before permitting more detailed source, campaign, term, content, or ID values, which may reveal search text or identifiers. See [Data model](DATA-MODEL.md) for the configuration and shareable implementation contract.

Regenerate the views from **Reporting → Reporting views** or the CLI:

```bash
php bin/console app:analytics:views:regenerate --dry-run
php bin/console app:analytics:views:regenerate
```

| View | Retained raw rows included |
| --- | --- |
| `analytics_custom_events_v1` | All event names in both privacy modes |
| `analytics_custom_pageviews_v1` | Rows with `event_name = 'view'` |
| `analytics_custom_goals_v1` | Rows with a non-null `goal_event` |

Each view exposes `id`, `website_token`, `event_name`, `page_path`, `referrer_channel`, `privacy_mode`, `device_class`, `viewport_bucket`, `geo_area`, `goal_event`, `created_at`, and `archived_at`, followed by configured text aliases and then optional numeric aliases. Dotted and hyphenated keys are literal top-level JSON keys.

An existing property's `column` retains its text contract: scalar JSON values become text, booleans become `true` or `false`, and missing keys, JSON null, arrays, and objects become SQL `NULL`. Declaring a collection `type` does not change an existing text alias into a numeric column.

Add a separate `numeric_column` alias to an explicitly declared `integer`, `float`, or `double` property for arithmetic in external BI tools:

```yaml
custom_data_properties:
  total_minor: { type: integer, consent_required: true, column: total_minor_text, numeric_column: total_minor_number }
  discount_rate: { type: double, consent_required: true, numeric_column: discount_rate_number }
```

Merge these definitions into the existing model. Numeric projections accept actual JSON numbers; numeric strings, booleans, structured data, null, and unsupported values produce SQL `NULL`. Integer values must be integral and within ±9,007,199,254,740,991; fractions are never truncated. Both `float` and `double` projections use approximate double precision and preserve fractions, subject to the database's numeric range and rounding. They do not provide exact decimal-money arithmetic. Prefer integer minor units plus a currency code for monetary amounts; see the [ecommerce examples](EVENT-EXAMPLES.md#numeric-views-for-external-calculations). Historical numeric strings are not converted, and changing collection types does not rewrite retained events.

Historical JSON is interpreted through each engine's JSON functions. Native parsing can already round a very precise fraction or underflow a very small number before the view sees it, particularly with SQLite. Projections cannot restore precision already lost; test historical edge cases on the database you use.

Regeneration guards deployed aliases, order, and SQL types. Text and numeric aliases must be unique across the model; keep existing aliases and append compatible columns. Because generated numeric aliases follow text aliases, adding a text alias after numeric aliases have been deployed can move existing columns and requires a separately planned database/reporting migration. Removing, renaming, reordering, or changing a deployed column's SQL type is also rejected. Preview SQL and test regeneration on the actual database/version with the intended view owner and reporting grants; generated SQL and mocked tests alone do not establish execution compatibility.

These views expose individual rows without suppression and need separately approved raw-data access. They include retained rows already marked `archived_at`, but never archive aggregate cells. Deleting raw rows removes them from these views even when archived counts remain elsewhere. Existing `bi_anonymous_*` reporting contracts and archives retain their dimensions and do not gain custom properties.

### Switching Databases

**To switch from one database to another:**

1. **Export data from old database:**
   ```bash
   # PostgreSQL
   pg_dump analytics > backup.sql

   # MySQL/MariaDB
   mysqldump analytics > backup.sql
   ```

2. **Update `DATABASE_URL` in your `.env` file**

3. **Create new database schema:**
   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console app:analytics:glossary:sync
   ```

4. **Import data** (requires manual SQL adaptation or use a tool like [pgloader](https://github.com/dimitri/pgloader))

### Data Type Mapping

| Doctrine Type | PostgreSQL | MySQL/MariaDB | SQL Server | SQLite |
|--------------|-----------|---------------|------------|--------|
| `integer` | INTEGER | INT | INT | INTEGER |
| `string` | VARCHAR | VARCHAR | NVARCHAR | TEXT |
| `text` | TEXT | TEXT | NVARCHAR(MAX) | TEXT |
| `datetime_immutable` | TIMESTAMP | DATETIME | DATETIME2 | TEXT |
| `json` | JSONB | JSON | NVARCHAR(MAX) | TEXT |

### Testing Migrations

Test migrations on your target database before production:

```bash
# Dry run
php bin/console doctrine:migrations:migrate --dry-run

# Execute
php bin/console doctrine:migrations:migrate -n
php bin/console app:analytics:glossary:sync

# Rollback if needed
php bin/console doctrine:migrations:migrate prev
```

---

## Analytics Archive and Maintenance

Lifecycle migrations add an `archived_at` marker to `events` and three private aggregate tables:

- `analytics_archive_events`: hourly event/pageview cells by privacy mode and reporting dimensions;
- `analytics_archive_goals`: daily goal cells by privacy mode; and
- `analytics_archive_geo_events`: daily event/geography cells by privacy mode.

`analytics_archived_events_v1`, `analytics_archived_pageviews_v1`, and `analytics_archived_goals_v1` are operational, unsuppressed archive views. Treat both the tables and these views as private data. The disclosure-controlled `bi_anonymous_events_v1`, `bi_anonymous_goals_v1`, and `bi_anonymous_geo_events_v1` views transparently union eligible live and archived anonymous counts and are the supported routine-BI surface.

Enhanced archive cells drop visitor/session identifiers and custom properties, but preserve reporting dimensions including the sanitized page path and coarse referrer channel. They can remain personal or identifying in context and no longer provide the source identifiers needed for person-level lookup; assess this transformation explicitly in the deployment's rights-handling process.

Do not update archive tables or `events.archived_at` manually. The application maintenance service aggregates and marks each source batch atomically, so retries do not double-count completed rows. Retention deletion runs in bounded batches and is disabled by default. Configure it in `config/aggregate.yaml` or the admin Data lifecycle page, then schedule and monitor:

```bash
# Inspect counts and cutoffs without changing data
php bin/console app:analytics:maintain --dry-run

# Archive and/or delete according to the active policy
php bin/console app:analytics:maintain
```

Run maintenance at least daily when either feature is enabled. Include the archive tables and lifecycle lease state in backups, and enforce separate expiration for database backups, replicas, BI extracts, and exports. On SQLite, file access still bypasses view permissions; use controlled exports or a server database for separate BI access.

---

## Production Recommendations

### By Use Case

**Small Sites (<10k/day):**
- SQLite or MySQL

**Medium Sites (10k-1M/day):**
- MySQL or PostgreSQL

**Large Sites (>1M/day):**
- PostgreSQL (recommended)
- Or SQL Server for Windows environments

**Enterprise:**
- PostgreSQL with read replicas
- Or SQL Server with Always On
- Consider database pooling (PgBouncer, ProxySQL)

### Backup Strategies

**Automated Backups:**

```bash
# PostgreSQL
0 2 * * * pg_dump -U user dbname | gzip > /backups/analytics_$(date +\%Y\%m\%d).sql.gz

# MySQL/MariaDB
0 2 * * * mysqldump -u user -p'password' dbname | gzip > /backups/analytics_$(date +\%Y\%m\%d).sql.gz

# SQL Server
0 2 * * * sqlcmd -S localhost -U sa -Q "BACKUP DATABASE analytics TO DISK = '/backups/analytics.bak'"
```

**Retention:**
- Keep daily backups for 7 days
- Keep weekly backups for 4 weeks
- Keep monthly backups for 1 year

---

## Troubleshooting

### Connection Issues

**Check PHP extension:**
```bash
php -m | grep -i pdo
php -m | grep -i mysql    # or pgsql, sqlsrv
```

**Test connection:**
```bash
php bin/console doctrine:query:sql "SELECT 1"
```

### Migration Failures

**Check database exists:**
```bash
php bin/console doctrine:database:create
```

**Check permissions:**
```sql
-- PostgreSQL
GRANT ALL ON SCHEMA public TO your_user;

-- MySQL
GRANT ALL PRIVILEGES ON dbname.* TO 'user'@'host';
```

**View migration status:**
```bash
php bin/console doctrine:migrations:status
```

---

## Support Matrix

| Database | Min Version | Max Version | Production Ready | Notes |
|----------|------------|-------------|------------------|-------|
| PostgreSQL | 13 | 16+ | ✅ Yes | Recommended |
| MySQL | 8.0 | 8.4+ | ✅ Yes | Geography BI view requires CTEs/window functions |
| MariaDB | 10.6 | 11.4+ | ✅ Yes | MySQL compatible |
| SQL Server | 2017 | 2022+ | ✅ Yes | Requires pdo_sqlsrv |
| SQLite | 3.25 | Latest | ⚠️ Dev only | Geography BI view requires window functions; not for high traffic |

---

## Further Reading

- [Doctrine DBAL Documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/)
- [Symfony Doctrine Integration](https://symfony.com/doc/current/doctrine.html)
- [PostgreSQL Performance Tips](https://wiki.postgresql.org/wiki/Performance_Optimization)
- [MySQL Performance Best Practices](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)
