#!/bin/bash
set -e

echo "=========================================="
echo "Aggregate Analytics - Plesk Setup Script"
echo "=========================================="
echo ""

# Check if running as correct user
if [ "$EUID" -eq 0 ]; then
   echo "WARNING: Do not run this script as root. Run it as your Plesk domain user."
   echo "Typically: su - username"
   exit 1
fi

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION;" 2>/dev/null || echo "0")
PHP_MAJOR=$(echo $PHP_VERSION | cut -d. -f1)
PHP_MINOR=$(echo $PHP_VERSION | cut -d. -f2)

echo "Detected PHP version: $PHP_VERSION"

if [ "$PHP_MAJOR" -lt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 2 ]); then
    echo "ERROR: PHP 8.2 or higher is required. Current version: $PHP_VERSION"
    echo ""
    echo "To change PHP version in Plesk:"
    echo "1. Go to Plesk Panel > Domains > your-domain.com"
    echo "2. Click 'PHP Settings'"
    echo "3. Select PHP 8.2 or higher"
    exit 1
fi

# Check if composer is available
if ! command -v composer &> /dev/null; then
    echo "ERROR: Composer is not installed or not in PATH"
    echo ""
    echo "Install Composer in Plesk:"
    echo "1. SSH to your server"
    echo "2. Run: curl -sS https://getcomposer.org/installer | php"
    echo "3. Run: mv composer.phar /usr/local/bin/composer"
    echo "   Or use: php composer.phar instead of 'composer' in this script"
    exit 1
fi

echo ""
echo "Step 1/7: Checking configuration..."
echo "-----------------------------------"

# Check if config file exists
if [ ! -f "config/aggregate.yaml" ]; then
    echo "Configuration file not found. Creating from example..."
    if [ -f "config/aggregate.yaml.example" ]; then
        cp config/aggregate.yaml.example config/aggregate.yaml
        echo "Created config/aggregate.yaml from example"
        echo ""
        echo "IMPORTANT: Edit config/aggregate.yaml before continuing!"
        echo "Review these application settings:"
        echo "  - app_host and js_namespace"
        echo "  - anonymous_tracking_enabled and anonymous_excluded_paths"
        echo "  - analytics collection and optional coarse-geography controls"
        echo "Database and Messenger connection strings belong in .env.local."
        echo ""
        read -p "Press Enter after you've edited config/aggregate.yaml..."
    else
        echo "ERROR: config/aggregate.yaml.example not found!"
        exit 1
    fi
fi

echo ""
echo "Step 2/7: Installing dependencies..."
echo "-------------------------------------"
composer install --no-dev --optimize-autoloader

echo ""
echo "Step 3/7: Setting up environment..."
echo "------------------------------------"

# Create .env.local if it doesn't exist
if [ ! -f ".env.local" ]; then
    echo "Creating .env.local..."
    cat > .env.local <<EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
EOF
    echo "Created .env.local with production settings"
else
    echo ".env.local already exists, skipping..."
fi

echo ""
echo "Step 4/7: Clearing and warming up cache..."
echo "-------------------------------------------"
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

echo ""
echo "Step 5/7: Running database migrations..."
echo "-----------------------------------------"
read -p "Run database migrations now? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    php bin/console doctrine:migrations:migrate --no-interaction
    echo "Migrations completed"
else
    echo "Skipped migrations. Run manually later with:"
    echo "  php bin/console doctrine:migrations:migrate"
fi

echo ""
echo "Step 6/7: Setting up file permissions..."
echo "-----------------------------------------"
chmod -R 777 var/cache var/log 2>/dev/null || chmod -R 775 var/cache var/log

echo ""
echo "Step 7/7: Compiling assets..."
echo "------------------------------"
php bin/console importmap:install
php bin/console asset-map:compile

echo ""
echo "=========================================="
echo "Setup Complete!"
echo "=========================================="
echo ""
echo "Next Steps:"
echo "-----------"
echo ""
echo "1. Configure Plesk Domain Settings:"
echo "   - Go to Plesk > Domains > your-domain.com > Apache & nginx Settings"
echo "   - Set 'Document root' to: $(pwd)/public"
echo "   - Enable 'proxy mode' (if using nginx)"
echo ""
echo "2. Create your first website:"
echo "   php bin/console app:create-website"
echo ""
echo "3. Test the installation:"
echo "   curl https://your-domain.com/api/health"
echo ""
echo "4. Set up the background worker:"
echo "   See PLESK-DEPLOYMENT.md for worker setup instructions"
echo ""
echo "=========================================="
