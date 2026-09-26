DC = docker compose
PHP = $(DC) exec api php
RDC = cd /opt/cars && docker compose --env-file .env.prod.local -f compose.prod.yaml

.DEFAULT_GOAL := help

help:
	@echo "build          Build images"
	@echo "up             Start the full stack (API, nginx, 4 workers, Postgres, RabbitMQ)"
	@echo "down           Stop the stack"
	@echo "destroy        Stop the stack and drop volumes with their data"
	@echo "logs           Follow logs"
	@echo "shell          Open a shell in the application container"
	@echo "migrate        Run database migrations"
	@echo "migration      Create an empty migration skeleton to fill with SQL"
	@echo "worker         Run one extra worker in the foreground, sharing the rate limiter"
	@echo "restart-worker Restart the workers so they pick up code changes"
	@echo "import         Queue a full import from the sitemap"
	@echo "import-sample  Queue an import of 50 pages"
	@echo "search-refresh Rebuild the search index after an import"
	@echo "mapping        Validate the entity mapping against itself"
	@echo "test           Run tests"
	@echo "stan           Run static analysis"
	@echo "cs             Check code style"
	@echo "cs-fix         Fix code style"
	@echo "check          Full verification: tests, static analysis, code style"
	@echo ""
	@echo "deploy         Sync the working tree to the EC2 host, migrate and restart"
	@echo "remote-import  Run a full import on the EC2 host and refresh the search index"
	@echo "remote-status  Show import progress on the EC2 host"
	@echo "remote-logs    Follow logs on the EC2 host"
	@echo "remote-shell   Open a shell on the EC2 host"

build:
	DOCKER_BUILDKIT=0 $(DC) build

up:
	$(DC) up -d

down:
	$(DC) down

destroy:
	$(DC) down -v

logs:
	$(DC) logs -f

shell:
	$(DC) exec api sh

migrate:
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction

migration:
	$(PHP) bin/console doctrine:migrations:generate

worker:
	$(DC) run --rm worker php bin/console messenger:consume import -vv

restart-worker:
	$(DC) restart worker

import:
	$(PHP) bin/console app:import:start

import-sample:
	$(PHP) bin/console app:import:start --limit=50

search-refresh:
	$(PHP) bin/console app:search:refresh

mapping:
	$(PHP) bin/console doctrine:schema:validate --skip-sync

test:
	$(DC) exec -e APP_ENV=test api vendor/bin/phpunit

stan:
	$(PHP) vendor/bin/phpstan analyse

cs:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix

check: mapping test stan cs

deploy:
	deploy/deploy.sh

remote-import:
	deploy/remote.sh "cd /opt/cars && deploy/host/import.sh"

remote-status:
	deploy/remote.sh "$(RDC) exec -T api php bin/console app:import:status"

remote-logs:
	deploy/remote.sh "$(RDC) logs -f --tail=100 api caddy database rabbitmq"

remote-shell:
	deploy/remote.sh

.PHONY: help build up down destroy logs shell migrate migration worker restart-worker import import-sample search-refresh mapping test stan cs cs-fix check deploy remote-import remote-status remote-logs remote-shell
