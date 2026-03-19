.PHONY: help install setup start stop restart logs migrate worker test clean

# Detect if Docker is available and being used
USE_DOCKER := $(shell command -v docker >/dev/null 2>&1 && [ -f compose.yaml ] && echo 1 || echo 0)

ifeq ($(USE_DOCKER),1)
    PHP_CMD = docker compose exec php php
    DB_CMD = docker compose exec -T database psql -U app -d app
    COMPOSE = docker compose
else
    PHP_CMD = php
    DB_CMD = psql
    COMPOSE = @echo "Docker not available."
endif

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Deployment mode: $(if $(filter 1,$(USE_DOCKER)),Docker,Native)'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies and set up environment
	@echo "Setting up Aggregate Analytics..."
ifeq ($(USE_DOCKER),1)
	@echo "Using Docker installation..."
	@if [ ! -f config/aggregate.yaml ]; then \
		cp config/aggregate.yaml.example config/aggregate.yaml; \
		echo "⚠️  Edit config/aggregate.yaml and set daily_salt_secret"; \
	fi
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

setup: install ## Alias for install

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
		-d '{"url":"http://localhost/test","referrer":"http://localhost/","screenWidth":1920,"websiteToken":"'$$token'"}'

clean: ## Clean up containers, volumes, and cache
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) down -v
endif
	rm -rf var/cache/* var/log/*
	@echo "✅ Cleaned up."

db-shell: ## Open database shell
ifeq ($(USE_DOCKER),1)
	$(COMPOSE) exec database psql -U app -d app
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

generate-salt: ## Generate a random salt for DAILY_SALT_SECRET
	@echo "Random salt for DAILY_SALT_SECRET:"
	@openssl rand -base64 32
