.PHONY: up down dev logs test seed fresh

SAIL = ./vendor/bin/sail

## Start everything: app, MySQL, Redis, Mailpit and the queue worker (migrated and seeded).
up:
	@test -f .env || cp .env.example .env
	@test -d vendor || docker run --rm -u "$$(id -u):$$(id -g)" -v "$$(pwd):/var/www/html" -w /var/www/html \
		laravelsail/php84-composer:latest composer install --prefer-dist --ignore-platform-reqs --no-interaction
	@$(SAIL) up -d --build --wait
	@grep -q '^APP_KEY=base64' .env || $(SAIL) artisan key:generate --no-interaction
	@$(SAIL) npm ci --no-audit --no-fund
	@$(SAIL) npm run build
	@$(SAIL) artisan migrate --seed --force --no-interaction
	@$(SAIL) artisan queue:restart >/dev/null
	@. ./.env; echo ""; \
	 echo "  App:     http://localhost:$${APP_PORT:-8080}"; \
	 echo "  Mailpit: http://localhost:$${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}"; \
	 echo "  Login:   demo@acme.test / password"

down:
	@$(SAIL) down

## Vite dev server with hot reload (run after `make up`).
dev:
	@$(SAIL) npm run dev

logs:
	@$(SAIL) logs -f

test:
	@$(SAIL) artisan test

seed:
	@$(SAIL) artisan db:seed --force

fresh:
	@$(SAIL) artisan migrate:fresh --seed --force
