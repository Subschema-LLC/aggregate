.PHONY: help install setup start stop restart logs migrate worker test clean build-js check-js \
	start-mysql start-postgres start-mariadb \
	migrate-mysql migrate-postgres migrate-mariadb \
	install-mysql install-postgres install-mariadb

# Detect if Docker is available and being used
USE_DOCKER := $(shell command -v docker >/dev/null 2>&1 && [ -f compose.yaml ] && echo 1 || echo 0)
DOCKER_PROFILE ?= mysql
MYSQL_DOCKER_DSN ?= mysql://app:!ChangeMe!@database:3306/aggregate_analytics?serverVersion=8.0
POSTGRES_DOCKER_DSN ?= postgresql://app:!ChangeMe!@database:5432/aggregate_analytics?serverVersion=16
MARIADB_DOCKER_DSN ?= mysql://app:!ChangeMe!@database:3306/aggregate_analytics?serverVersion=mariadb-11.4

ifeq ($(USE_DOCKER),1)
    COMPOSE = docker compose --profile $(DOCKER_PROFILE)
    PHP_CMD = docker compose exec php php
    ifeq ($(DOCKER_PROFILE),postgres)
        DB_CMD = $(COMPOSE) exec database-postgres psql -U $${POSTGRES_USER:-app} -d $${POSTGRES_DB:-aggregate_analytics}
    else ifeq ($(DOCKER_PROFILE),mariadb)
        DB_CMD = $(COMPOSE) exec database-mariadb mysql -u$${MARIADB_USER:-app} -p$${MARIADB_PASSWORD:-!ChangeMe!} $${MARIADB_DATABASE:-aggregate_analytics}
    else
        DB_CMD = $(COMPOSE) exec database-mysql mysql -u$${MYSQL_USER:-app} -p$${MYSQL_PASSWORD:-!ChangeMe!} $${MYSQL_DATABASE:-aggregate_analytics}
    endif
else
    PHP_CMD = php
    DB_CMD = psql
    COMPOSE = @echo "Docker not available."
endif

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Deployment mode: $(if $(filter 1,$(USE_DOCKER)),Docker,Native)'
	@echo 'Docker profile: $(DOCKER_PROFILE)'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies and set up environment
	@echo "Setting up Aggregate Analytics..."
ifeq ($(USE_DOCKER),1)
	@echo "Using Docker installation..."
	@if [ ! -f config/aggregate.yaml ]; then \
		cp config/aggregate.yaml.example config/aggregate.yaml; \
		echo "⚠️  Review the privacy measurement controls in config/aggregate.yaml"; \
	fi
	@echo "Using Docker profile: $(DOCKER_PROFILE)"
	@echo "Starting services..."
	$(COMPOSE) up -d
	@echo "Waiting for database to be ready..."
	@sleep 5
	@echo "Running database migrations..."
	$(PHP_CMD) bin/console doctrine:migrations:migrate -n
	@echo ""
	@echo "✅ Docker installation complete!"
else
	@echo "Using native installation..."
	@echo "Run: ./install.sh for interactive setup"
	@echo "Or run: composer install && php bin/console doctrine:migrations:migrate"
endif
	@echo ""
	@echo "Next steps:"
	@echo "  1. Create a website with 'make create-website'"
	@echo "  2. Check status with 'make status'"
	@echo "Tip: choose DB profile with 'make <target> DOCKER_PROFILE=postgres' (or mariadb/mysql)"

setup: install ## Alias for install

start-mysql: ## Start Docker services with MySQL profile
	@DOCKER_DATABASE_URL="$(MYSQL_DOCKER_DSN)" $(MAKE) start DOCKER_PROFILE=mysql

start-postgres: ## Start Docker services with PostgreSQL profile
	@DOCKER_DATABASE_URL="$(POSTGRES_DOCKER_DSN)" $(MAKE) start DOCKER_PROFILE=postgres

start-mariadb: ## Start Docker services with MariaDB profile
	@DOCKER_DATABASE_URL="$(MARIADB_DOCKER_DSN)" $(MAKE) start DOCKER_PROFILE=mariadb

migrate-mysql: ## Run migrations using MySQL profile
	@DOCKER_DATABASE_URL="$(MYSQL_DOCKER_DSN)" $(MAKE) migrate DOCKER_PROFILE=mysql

migrate-postgres: ## Run migrations using PostgreSQL profile
	@DOCKER_DATABASE_URL="$(POSTGRES_DOCKER_DSN)" $(MAKE) migrate DOCKER_PROFILE=postgres

migrate-mariadb: ## Run migrations using MariaDB profile
	@DOCKER_DATABASE_URL="$(MARIADB_DOCKER_DSN)" $(MAKE) migrate DOCKER_PROFILE=mariadb

install-mysql: ## Docker install flow with MySQL profile
	@DOCKER_DATABASE_URL="$(MYSQL_DOCKER_DSN)" $(MAKE) install DOCKER_PROFILE=mysql

install-postgres: ## Docker install flow with PostgreSQL profile
	@DOCKER_DATABASE_URL="$(POSTGRES_DOCKER_DSN)" $(MAKE) install DOCKER_PROFILE=postgres

install-mariadb: ## Docker install flow with MariaDB profile
	@DOCKER_DATABASE_URL="$(MARIADB_DOCKER_DSN)" $(MAKE) install DOCKER_PROFILE=mariadb

start: ## Start all services
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) up -d
	@echo "✅ Services started. Run 'make logs' to view logs."
else
	@echo "Native mode: Start your web server and worker manually"
	@echo "Worker: php bin/console messenger:consume async -vv"
endif

stop: ## Stop all services
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) down
else
	@echo "Native mode: Stop your web server and worker manually"
endif

restart: ## Restart all services
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) restart
	@echo "✅ Services restarted."
else
	@echo "Native mode: Restart your web server and worker manually"
endif

logs: ## Follow logs from all services
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) logs -f
else
	@echo "Showing last 100 lines of var/log/*.log files:"
	@tail -100 var/log/*.log 2>/dev/null || echo "No log files found"
endif

logs-worker: ## Follow worker logs only
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) logs -f worker
else
	@echo "Showing worker logs:"
	@tail -f var/log/worker.log 2>/dev/null || echo "No worker log found"
endif

migrate: ## Run database migrations
	$(PHP_CMD) bin/console doctrine:migrations:migrate

test: ## Run PHP and JavaScript privacy regression tests
	$(PHP_CMD) vendor/bin/phpunit
	node tests/JavaScript/aggregate-consent.test.js

build-js: ## Build optional minified tracker, marker, and consent drop-in scripts
	node scripts/build-js.cjs

check-js: ## Check that optional generated JavaScript matches current source
	node scripts/build-js.cjs --check

worker: ## Manually start worker (for debugging)
	$(PHP_CMD) -d variables_order=EGPCS bin/console messenger:consume async -vv

create-website: ## Create a new website (interactive)
	$(PHP_CMD) bin/console app:create-website

status: ## Check service status and health
ifeq ($(USE_DOCKER),1)
	@echo "Service Status:"
	@$(COMPOSE) ps
	@echo ""
endif
	@echo "Health Check:"
	@curl -s http://localhost/api/health || echo "❌ Health check failed"

test-tracking: ## Send a test tracking event
	@echo "Sending test tracking event..."
	@read -p "Website Token: " token; \
	curl -i -X POST http://localhost/api/receive \
		-H "Origin: http://localhost" \
		-H "Content-Type: application/json" \
		-d '{"eventName":"button_click","pagePath":"/test","referrerChannel":"internal","deviceClass":"desktop","viewportBucket":"large","consentState":"unknown","websiteToken":"'$$token'"}'

clean: ## Clean up containers, volumes, and cache
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) down -v
endif
	rm -rf var/cache/* var/log/*
	@echo "✅ Cleaned up."

db-shell: ## Open database shell
ifeq ($(USE_DOCKER),1)
	$(DB_CMD)
else
	@echo "Connect to your database using your configured credentials"
endif

php-shell: ## Open PHP container shell
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) exec php bash
else
	@echo "Native mode: You're already in your shell!"
endif

cache-clear: ## Clear Symfony cache
	$(PHP_CMD) bin/console cache:clear

assets-compile: ## Compile frontend assets
	$(PHP_CMD) bin/console asset-map:compile
