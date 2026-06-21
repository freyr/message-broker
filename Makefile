.PHONY: help build logs shell test test-pgsql test-all

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker compose build

logs: ## Show container logs
	docker compose logs -f

shell: ## Open shell in PHP container
	docker compose run --rm php sh

test: ## Run tests against MySQL
	docker compose run --rm php vendor/bin/phpunit

test-pgsql: ## Run tests against PostgreSQL
	docker compose run --rm -e DB_ENGINE=pgsql php vendor/bin/phpunit

test-all: test test-pgsql ## Run tests against both engines

phpstan: ## Run PHPStan
	docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1

cs-check: ## Check code style with ECS
	docker compose run --rm php vendor/bin/ecs check

cs-fix: ## Fix code style with ECS
	docker compose run --rm php vendor/bin/ecs check --fix