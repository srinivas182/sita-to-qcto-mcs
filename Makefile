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

demo: ## Fresh local database with demo data (password: demo passphrase 2026)
	cd $(API) && php artisan migrate:fresh && php artisan db:seed --class=DemoSeeder

dev: ## Run the API (:8000) and the web app (:5173). Open http://tym.platform.test:5173 (map *.platform.test to 127.0.0.1)
	cd $(API) && php artisan serve --port=8000 & pnpm --filter @skills/web dev

api-client: ## Regenerate the OpenAPI document and the typed client
	cd $(API) && php artisan scramble:export
	pnpm --filter @skills/api-client generate

mobile: ## Start the Expo dev server for the phone app (Expo Go / dev client)
	pnpm --filter @skills/mobile start

smoke: ## Browser smoke test against running API + built web (vite preview :4173)
	python3 apps/web/e2e/smoke.py

check: ## Local checks before pushing (style, static analysis, types)
	cd $(API) && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	pnpm -r --if-present run lint && pnpm -r --if-present run typecheck

test: ## Run all tests
	cd $(API) && php artisan test
	pnpm -r --if-present run test

fix: ## Fix code style
	cd $(API) && vendor/bin/pint
