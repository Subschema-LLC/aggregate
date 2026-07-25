#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================"
echo "  Aggregate Analytics Installation"
echo "========================================"
echo ""

# Check if we're root
if [ "$EUID" -eq 0 ]; then
    echo -e "${YELLOW}Warning: Running as root. Consider running as a regular user.${NC}"
    echo ""
fi

# Check PHP version
echo "Checking PHP version..."
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is not installed. Please install PHP 8.2 or higher.${NC}"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;")

if [ "$PHP_MAJOR" -lt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 2 ]); then
    echo -e "${RED}Error: PHP 8.2 or higher is required. Found: $PHP_VERSION${NC}"
    exit 1
fi

echo -e "${GREEN}✓ PHP $PHP_VERSION detected${NC}"

# Check required PHP extensions
echo ""
echo "Checking required PHP extensions..."
REQUIRED_EXTENSIONS=("ctype" "iconv" "pdo" "mbstring" "xml" "curl" "intl")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^$ext$"; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
    echo -e "${RED}Error: Missing required PHP extensions:${NC}"
    printf '%s\n' "${MISSING_EXTENSIONS[@]}"
    echo ""
    echo "Install them with:"
    echo "  Debian/Ubuntu: sudo apt-get install php-${MISSING_EXTENSIONS[*]}"
    echo "  CentOS/RHEL:   sudo yum install php-${MISSING_EXTENSIONS[*]}"
    exit 1
fi

echo -e "${GREEN}✓ All required extensions found${NC}"

# Check Composer
echo ""
echo "Checking Composer..."
if ! command -v composer &> /dev/null; then
    echo -e "${YELLOW}Composer not found. Installing...${NC}"
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    echo -e "${GREEN}✓ Composer installed${NC}"
else
    echo -e "${GREEN}✓ Composer found${NC}"
fi

# Install dependencies
echo ""
echo "Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader

# Configure environment
echo ""
echo "========================================"
echo "  Configuration"
echo "========================================"
echo ""

set_env_value() {
    local key="$1"
    local value="$2"
    local file="$3"
    local escaped

    escaped=$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')

    if grep -q "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
    else
        echo "${key}=${value}" >> "$file"
    fi
}

if [ ! -f .env.local ]; then
    echo "Creating .env.local..."
    cp .env .env.local
fi

APP_SECRET=$(openssl rand -hex 32)
set_env_value "APP_ENV" "prod" ".env.local"
set_env_value "APP_DEBUG" "0" ".env.local"
set_env_value "APP_SECRET" "$APP_SECRET" ".env.local"
set_env_value "MAILER_DSN" "null://null" ".env.local"

echo ""
echo "Database Configuration (.env.local / DATABASE_URL):"
echo "1) SQLite 3.25+ (Easiest, good for small sites)"
echo "2) PostgreSQL (Recommended for production)"
echo "3) MySQL"
echo "4) MariaDB"
echo "5) Microsoft SQL Server"
read -p "Choose database type [1-5]: " DB_CHOICE

case $DB_CHOICE in
    1)
        DB_URL="sqlite:///%kernel.project_dir%/var/data.db"
        ;;
    2)
        read -p "PostgreSQL host [localhost]: " PG_HOST
        PG_HOST=${PG_HOST:-localhost}
        read -p "PostgreSQL port [5432]: " PG_PORT
        PG_PORT=${PG_PORT:-5432}
        read -p "PostgreSQL database name: " PG_DB
        read -p "PostgreSQL username: " PG_USER
        read -sp "PostgreSQL password: " PG_PASS
        echo ""
        read -p "PostgreSQL version [16]: " PG_VER
        PG_VER=${PG_VER:-16}
        DB_URL="postgresql://$PG_USER:$PG_PASS@$PG_HOST:$PG_PORT/$PG_DB?serverVersion=$PG_VER"
        ;;
    3)
        read -p "MySQL host [localhost]: " MY_HOST
        MY_HOST=${MY_HOST:-localhost}
        read -p "MySQL port [3306]: " MY_PORT
        MY_PORT=${MY_PORT:-3306}
        read -p "MySQL database name: " MY_DB
        read -p "MySQL username: " MY_USER
        read -sp "MySQL password: " MY_PASS
        echo ""
        read -p "MySQL version [8.0]: " MY_VER
        MY_VER=${MY_VER:-8.0}
        DB_URL="mysql://$MY_USER:$MY_PASS@$MY_HOST:$MY_PORT/$MY_DB?serverVersion=$MY_VER"
        ;;
    4)
        read -p "MariaDB host [localhost]: " MA_HOST
        MA_HOST=${MA_HOST:-localhost}
        read -p "MariaDB port [3306]: " MA_PORT
        MA_PORT=${MA_PORT:-3306}
        read -p "MariaDB database name: " MA_DB
        read -p "MariaDB username: " MA_USER
        read -sp "MariaDB password: " MA_PASS
        echo ""
        read -p "MariaDB version [11.4]: " MA_VER
        MA_VER=${MA_VER:-11.4}
        DB_URL="mysql://$MA_USER:$MA_PASS@$MA_HOST:$MA_PORT/$MA_DB?serverVersion=mariadb-$MA_VER"
        ;;
    5)
        read -p "SQL Server host [localhost]: " MS_HOST
        MS_HOST=${MS_HOST:-localhost}
        read -p "SQL Server port [1433]: " MS_PORT
        MS_PORT=${MS_PORT:-1433}
        read -p "SQL Server database name: " MS_DB
        read -p "SQL Server username [sa]: " MS_USER
        MS_USER=${MS_USER:-sa}
        read -sp "SQL Server password: " MS_PASS
        echo ""
        read -p "SQL Server version [2022]: " MS_VER
        MS_VER=${MS_VER:-2022}
        DB_URL="sqlsrv://$MS_USER:$MS_PASS@$MS_HOST:$MS_PORT/$MS_DB?serverVersion=$MS_VER"
        echo ""
        echo -e "${YELLOW}Note: SQL Server requires pdo_sqlsrv PHP extension${NC}"
        echo "Install with: sudo pecl install sqlsrv pdo_sqlsrv"
        ;;
    *)
        echo -e "${YELLOW}Invalid choice, using SQLite${NC}"
        DB_URL="sqlite:///%kernel.project_dir%/var/data.db"
        ;;
esac

echo ""
echo "Ingestion Mode:"
echo "1) Sync (easiest, no worker required)"
echo "2) Async queue (recommended for higher traffic, requires worker)"
read -p "Choose ingestion mode [1-2]: " MODE_CHOICE

case $MODE_CHOICE in
    1)
        MESSENGER_DSN="sync://"
        ;;
    2)
        MESSENGER_DSN="doctrine://default"
        ;;
    *)
        echo -e "${YELLOW}Invalid choice, using sync mode${NC}"
        MESSENGER_DSN="sync://"
        ;;
esac

set_env_value "DATABASE_URL" "\"$DB_URL\"" ".env.local"
set_env_value "MESSENGER_TRANSPORT_DSN" "$MESSENGER_DSN" ".env.local"
echo -e "${GREEN}✓ .env.local updated${NC}"

if [ ! -f config/aggregate.yaml ]; then
    echo ""
    echo "Creating config/aggregate.yaml..."

    read -p "Public app host [http://localhost:9001]: " APP_HOST
    APP_HOST=${APP_HOST:-http://localhost:9001}
    read -p "JS namespace [Aggregate]: " JS_NAMESPACE
    JS_NAMESPACE=${JS_NAMESPACE:-Aggregate}
    read -p "Enable dashboard UI? [Y/n]: " DASHBOARD_CHOICE
    DASHBOARD_CHOICE=${DASHBOARD_CHOICE:-Y}

    if [[ "$DASHBOARD_CHOICE" =~ ^[Nn]$ ]]; then
        DASHBOARD_ENABLED=false
    else
        DASHBOARD_ENABLED=true
    fi

    cat > config/aggregate.yaml <<EOF
# Aggregate Analytics Configuration
# App-specific settings only.

environments:
  prod:
    rate_limit_per_minute: 100
    app_host: "$APP_HOST"
    js_namespace: "$JS_NAMESPACE"
    dashboard_enabled: $DASHBOARD_ENABLED
    anonymous_tracking_enabled: true
    anonymous_excluded_paths: []

  dev:
    rate_limit_per_minute: 1000
    app_host: "http://localhost:9001"
    js_namespace: "Aggregate"
    dashboard_enabled: true
    anonymous_tracking_enabled: true
    anonymous_excluded_paths: []

  test:
    rate_limit_per_minute: 1000
    js_namespace: "Aggregate"
    dashboard_enabled: true
    anonymous_tracking_enabled: true
    anonymous_excluded_paths: []
EOF

    echo -e "${GREEN}✓ config/aggregate.yaml created${NC}"
else
    echo -e "${YELLOW}config/aggregate.yaml already exists, skipping...${NC}"
fi

# Set up database
echo ""
echo "========================================"
echo "  Database Setup"
echo "========================================"
echo ""

read -p "Create database and run migrations? [Y/n]: " RUN_MIGRATIONS
RUN_MIGRATIONS=${RUN_MIGRATIONS:-Y}

if [[ "$RUN_MIGRATIONS" =~ ^[Yy]$ ]]; then
    echo "Creating database (if needed)..."
    php bin/console doctrine:database:create --if-not-exists || true

    echo "Running migrations..."
    php bin/console doctrine:migrations:migrate -n

    echo -e "${GREEN}✓ Database setup complete${NC}"
fi

# Compile assets
echo ""
echo "Compiling assets..."
php bin/console asset-map:compile

# Set permissions
echo ""
echo "Setting permissions..."
chmod -R 755 var/ public/
echo -e "${GREEN}✓ Permissions set${NC}"

# Optional admin setup for dashboard users
echo ""
if grep -q "dashboard_enabled: true" config/aggregate.yaml 2>/dev/null; then
    read -p "Create dashboard admin user now? [y/N]: " CREATE_ADMIN
    CREATE_ADMIN=${CREATE_ADMIN:-N}

    if [[ "$CREATE_ADMIN" =~ ^[Yy]$ ]]; then
        php bin/console app:install
    fi
else
    echo "Dashboard is disabled in config/aggregate.yaml, skipping admin setup."
fi

# Create website
echo ""
echo "========================================"
echo "  Create First Website"
echo "========================================"
echo ""

read -p "Would you like to create a website now? [Y/n]: " CREATE_SITE
CREATE_SITE=${CREATE_SITE:-Y}

if [[ "$CREATE_SITE" =~ ^[Yy]$ ]]; then
    php bin/console app:create-website
fi

if [[ "$MESSENGER_DSN" != "sync://" ]]; then
    # Worker setup
    echo ""
    echo "========================================"
    echo "  Worker Setup (Required)"
    echo "========================================"
    echo ""
    echo "To process analytics events, you need to run a background worker."
    echo "Choose your preferred method:"
    echo ""
    echo "1) systemd (Recommended for Linux servers)"
    echo "2) Supervisor"
    echo "3) Cron (Simple but less reliable)"
    echo "4) Skip (I'll set it up manually later)"
    echo ""
    read -p "Choose worker setup method [1-4]: " WORKER_CHOICE

    case $WORKER_CHOICE in
        1)
            echo ""
            echo "Setting up systemd service..."
            INSTALL_PATH=$(pwd)

            # Create service file
            sudo tee /etc/systemd/system/aggregate-worker.service > /dev/null <<EOF
[Unit]
Description=Aggregate Analytics Worker
After=network.target

[Service]
Type=simple
User=$USER
Group=$USER
WorkingDirectory=$INSTALL_PATH
ExecStart=/usr/bin/php $INSTALL_PATH/bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=10
Environment="APP_ENV=prod"
StandardOutput=journal
StandardError=journal
SyslogIdentifier=aggregate-worker

[Install]
WantedBy=multi-user.target
EOF

            sudo systemctl daemon-reload
            sudo systemctl enable aggregate-worker
            sudo systemctl start aggregate-worker

            echo -e "${GREEN}✓ systemd service created and started${NC}"
            echo "Check status with: sudo systemctl status aggregate-worker"
            ;;
        2)
            echo ""
            echo "Please install Supervisor and add the configuration from:"
            echo "  docs/supervisor/aggregate-worker.conf"
            echo ""
            echo "Then run:"
            echo "  sudo supervisorctl reread"
            echo "  sudo supervisorctl update"
            echo "  sudo supervisorctl start aggregate-worker:*"
            ;;
        3)
            echo ""
            echo "Add this to your crontab (crontab -e):"
            echo ""
            echo "* * * * * cd $(pwd) && php bin/console messenger:consume async --time-limit=60 >> var/log/worker.log 2>&1"
            echo ""
            ;;
        4)
            echo ""
            echo "Worker setup skipped. See docs/ for configuration examples."
            ;;
    esac
else
    echo ""
    echo -e "${GREEN}Sync ingestion enabled: no worker setup required.${NC}"
fi

# Final instructions
echo ""
echo "========================================"
echo -e "${GREEN}  Installation Complete!${NC}"
echo "========================================"
echo ""
echo "Next steps:"
echo "1. Configure your web server (see docs/nginx/ or docs/apache/)"
echo "2. Point document root to: $(pwd)/public"
echo "3. Test the installation: curl http://localhost/api/health"
echo ""
echo "Documentation:"
echo "  - Web server configs: docs/nginx/ and docs/apache/"
echo "  - Worker configs: docs/systemd/ and docs/supervisor/"
echo "  - Full guide: README.md"
echo ""
echo -e "${GREEN}Happy tracking!${NC}"
