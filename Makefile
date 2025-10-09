.PHONY: help install setup start stop restart logs migrate worker test clean

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies and set up environment
	@echo "Setting up Aggregate Analytics..."
	@if [ ! -f .env.local ]; then \
		echo "Creating .env.local from .env.example..."; \
		cp .env.example .env.local; \
		echo ""; \
		echo "⚠️  IMPORTANT: Edit .env.local and set:"; \
		echo "  - DAILY_SALT_SECRET (generate with: openssl rand -base64 32)"; \
		echo "  - DATABASE_URL (if not using default)"; \
		echo "  - APP_SECRET (generate with: openssl rand -base64 32)"; \
		echo ""; \
	fi
	@echo "Starting services..."
	docker compose up -d
	@echo "Waiting for database to be ready..."
	@sleep 5
	@echo "Running database migrations..."
	docker compose exec php php bin/console doctrine:migrations:migrate -n
	@echo ""
	@echo "✅ Installation complete!"
	@echo ""
	@echo "Next steps:"
	@echo "  1. Edit .env.local with your configuration"
	@echo "  2. Run 'make restart' to apply changes"
	@echo "  3. Create a website with 'make create-website'"
	@echo "  4. Check status with 'make status'"

setup: install ## Alias for install

start: ## Start all services
	docker compose up -d
	@echo "✅ Services started. Run 'make logs' to view logs."

stop: ## Stop all services
	docker compose down

restart: ## Restart all services
	docker compose restart
	@echo "✅ Services restarted."

logs: ## Follow logs from all services
	docker compose logs -f

logs-worker: ## Follow worker logs only
	docker compose logs -f worker

migrate: ## Run database migrations
	docker compose exec php php bin/console doctrine:migrations:migrate

worker: ## Manually start worker (for debugging)
	docker compose exec php php -d variables_order=EGPCS bin/console messenger:consume async -vv

create-website: ## Create a new website (interactive)
	@echo "Creating a new website..."
	@read -p "Website name: " name; \
	read -p "Domain (e.g., example.com): " domain; \
	token=$$(openssl rand -hex 16); \
	docker compose exec -T database psql -U app -d app -c \
		"INSERT INTO websites (name, domain, public_token) VALUES ('$$name', '$$domain', '$$token');" && \
	echo "" && \
	echo "✅ Website created!" && \
	echo "" && \
	echo "Website Token: $$token" && \
	echo "" && \
	echo "Add this to your website:" && \
	echo "<script>" && \
	echo "  window.MyAnalytics = {" && \
	echo "    endpoint: 'http://localhost/api/receive'," && \
	echo "    websiteToken: '$$token'" && \
	echo "  };" && \
	echo "</script>" && \
	echo "<script src=\"http://localhost/aggregate.js\" async></script>"

status: ## Check service status and health
	@echo "Service Status:"
	@docker compose ps
	@echo ""
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
	docker compose down -v
	rm -rf var/cache/* var/log/*
	@echo "✅ Cleaned up."

db-shell: ## Open database shell
	docker compose exec database psql -U app -d app

php-shell: ## Open PHP container shell
	docker compose exec php bash

generate-salt: ## Generate a random salt for DAILY_SALT_SECRET
	@echo "Random salt for DAILY_SALT_SECRET:"
	@openssl rand -base64 32
