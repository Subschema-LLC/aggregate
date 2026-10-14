#!/usr/bin/env bash
# Installs a disposable copy of Aggregate as a production site for load tests.
#
#   tests/Load/server/install.sh SOURCE_DIR APP_ROOT DATABASE_URL
#
# Copies SOURCE_DIR to APP_ROOT, installs production dependencies, writes the
# release defaults and this server's .env.local, runs migrations, creates an
# administrator and a website for loadtest.example, and writes the website's
# public token to APP_ROOT/var/load-test-token. Never point it at a database
# that holds real data.
set -euo pipefail

source_dir=${1:?source directory}
app_root=${2:?application root}
database_url=${3:?DATABASE_URL}

sudo mkdir -p "$app_root"
sudo chown "$(id -un):$(id -gn)" "$app_root"
rsync -a --delete --exclude .git "$source_dir/" "$app_root/"
cd "$app_root"

composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader --classmap-authoritative

# The same defaults a release ZIP ships, then this server's values.
printf 'APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=\nTRUSTED_PROXIES=\nDATABASE_URL="sqlite:///%%kernel.project_dir%%/var/data.db"\nMESSENGER_TRANSPORT_DSN=sync://\nMAILER_DSN=null://null\n' > .env
{
  echo "APP_SECRET=$(openssl rand -hex 32)"
  echo "DATABASE_URL='${database_url}'"
  # k6 sends each simulated visitor's address in X-Forwarded-For through
  # Nginx on this machine; trust only that local hop.
  echo "TRUSTED_PROXIES=127.0.0.1"
} > .env.local
cp config/aggregate.yaml.example config/aggregate.yaml

php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:install --username=admin --password=load-test-admin --no-interaction
printf 'Load test\nloadtest.example\n' | php bin/console app:create-website
php bin/console cache:clear

php -r 'require "vendor/autoload.php"; echo Symfony\Component\Yaml\Yaml::parseFile("config/websites.yaml")["websites"][0]["token"];' > var/load-test-token
test -s var/load-test-token
