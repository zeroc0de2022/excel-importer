# Export host UID/GID so containers write files owned by you, not root
export UID := $(shell id -u)
export GID := $(shell id -g)

COMPOSE := docker compose
APP     := $(COMPOSE) exec app

.DEFAULT_GOAL := help
.PHONY: help up down restart test lint shell logs migrate fresh bench

help: ## Show available commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  make %-10s %s\n", $$1, $$2}'

.env:
	cp .env.example .env

up: .env ## Build and start everything, install deps, run migrations
	$(COMPOSE) build
	$(COMPOSE) run --rm --no-deps app composer install --no-interaction
	@grep -q '^APP_KEY=$$' .env && $(COMPOSE) run --rm --no-deps app php artisan key:generate || true
	$(COMPOSE) run --rm node sh -c "npm ci && npm run build"
	$(COMPOSE) up -d --wait
	$(APP) php artisan migrate --force
	@echo ""
	@echo "  App:    http://localhost:$${APP_PORT:-8000}"
	@echo "  Login:  $$(grep '^BASIC_AUTH_USER=' .env | cut -d= -f2) / $$(grep '^BASIC_AUTH_PASSWORD=' .env | cut -d= -f2)"
	@echo ""

down: ## Stop containers (data volumes are kept)
	$(COMPOSE) down

restart: ## Restart containers (picks up config/code changes in workers)
	$(COMPOSE) restart

test: ## Run the test suite (against Postgres + Redis in containers)
	$(APP) php artisan test

lint: ## Code style (Pint) and static analysis (Larastan)
	$(APP) vendor/bin/pint --test
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G

bench: ## Import 100 000 generated rows in one process, print time and peak memory
	$(APP) php artisan import:benchmark --rows=100000 --fresh

shell: ## Open a shell in the app container
	$(APP) sh

logs: ## Follow logs of all services
	$(COMPOSE) logs -f --tail=100

migrate: ## Run migrations
	$(APP) php artisan migrate

fresh: ## Drop all tables and migrate again
	$(APP) php artisan migrate:fresh
