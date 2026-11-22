# Skills Ecosystem — common tasks. Run `make help`.
API=apps/api

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-12s %s\n", $$1, $$2}'

up: ## Start PostgreSQL, Redis and Mailpit
	docker compose -f infra/docker-compose.yml up -d

down: ## Stop local services
	docker compose -f infra/docker-compose.yml down

setup: ## Install dependencies and prepare the API
	cd $(API) && composer install && cp -n .env.example .env && php artisan key:generate && php artisan migrate
	pnpm install

dev: ## Run the API (and web/mobile once added)
	cd $(API) && php artisan serve

check: ## Local checks before pushing (style, static analysis, types)
	cd $(API) && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	pnpm -r --if-present run typecheck

test: ## Run all tests
	cd $(API) && php artisan test
	pnpm -r --if-present run test

fix: ## Fix code style
	cd $(API) && vendor/bin/pint
