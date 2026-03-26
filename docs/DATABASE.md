# Database Configuration Guide

Aggregate Analytics supports **PostgreSQL**, **MySQL**, **MariaDB**, **Microsoft SQL Server**, and **SQLite** via YAML configuration.

## Table of Contents

- [Quick Reference](#quick-reference)
- [PostgreSQL](#postgresql)
- [MySQL](#mysql)
- [MariaDB](#mariadb)
- [Microsoft SQL Server](#microsoft-sql-server)
- [SQLite](#sqlite)
- [Migration and Compatibility](#migration-and-compatibility)

---

## Quick Reference

Set `DATABASE_URL` in your `.env` file (project root, not committed to git):

```dotenv
DATABASE_URL="DATABASE_CONNECTION_STRING_HERE"
```

### Connection String Format

| Database | Connection String |
|----------|------------------|
| **PostgreSQL** | `postgresql://user:pass@host:5432/dbname?serverVersion=16` |
| **MySQL** | `mysql://user:pass@host:3306/dbname?serverVersion=8.0` |
| **MariaDB** | `mysql://user:pass@host:3306/dbname?serverVersion=mariadb-11.4` |
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
- `events.website_id`
- `events.session_id`
- `websites.public_token` (unique)

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
DATABASE_URL="mysql://analytics_user:your_secure_password@localhost:3306/analytics?serverVersion=8.0"
```

**3. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
```

### Version Support

| MySQL Version | serverVersion Parameter |
|--------------|------------------------|
| MySQL 8.4 | `?serverVersion=8.4` |
| MySQL 8.0 | `?serverVersion=8.0` |
| MySQL 5.7 | `?serverVersion=5.7` |

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

**Important:** Use `mariadb-` prefix in serverVersion!

```dotenv
DATABASE_URL="mysql://analytics_user:your_secure_password@localhost:3306/analytics?serverVersion=mariadb-11.4"
```

**3. Run migrations:**
```bash
php bin/console doctrine:migrations:migrate -n
```

### Version Support

| MariaDB Version | serverVersion Parameter |
|----------------|------------------------|
| MariaDB 11.4 | `?serverVersion=mariadb-11.4` |
| MariaDB 11.0 | `?serverVersion=mariadb-11.0` |
| MariaDB 10.11 | `?serverVersion=mariadb-10.11` |
| MariaDB 10.6 | `?serverVersion=mariadb-10.6` |

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

### Database-Agnostic Migrations

All migrations use Doctrine DBAL's platform-agnostic schema API, ensuring compatibility across all supported databases:

```php
// Example from migrations/Version20251008220000.php
$websites = $schema->createTable('websites');
$websites->addColumn('id', 'integer', ['autoincrement' => true]);
$websites->addColumn('name', 'string', ['length' => 191]);
// ... works on all databases!
```

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

# Rollback if needed
php bin/console doctrine:migrations:migrate prev
```

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
| MySQL | 5.7 | 8.4+ | ✅ Yes | Well tested |
| MariaDB | 10.6 | 11.4+ | ✅ Yes | MySQL compatible |
| SQL Server | 2017 | 2022+ | ✅ Yes | Requires pdo_sqlsrv |
| SQLite | 3.35 | Latest | ⚠️ Dev only | Not for high traffic |

---

## Further Reading

- [Doctrine DBAL Documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/)
- [Symfony Doctrine Integration](https://symfony.com/doc/current/doctrine.html)
- [PostgreSQL Performance Tips](https://wiki.postgresql.org/wiki/Performance_Optimization)
- [MySQL Performance Best Practices](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)
