.PHONY: help build logs shell install test phpstan cs-check cs-fix infection bench bench-consumer

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

install: ## Install Composer dependencies in the container
	docker compose run --rm php composer install

test: ## Run tests against PostgreSQL and RabbitMQ
	docker compose run --rm php vendor/bin/phpunit

phpstan: ## Run PHPStan
	docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1

cs-check: ## Check code style with ECS
	docker compose run --rm php vendor/bin/ecs check

cs-fix: ## Fix code style with ECS
	docker compose run --rm php vendor/bin/ecs check --fix

infection: ## Mutation testing with Infection and pcov (full run, all CPU cores); ARGS for extra options
	docker compose run --rm php vendor/bin/infection --threads=max $(ARGS)

bench: ## Relay throughput benchmark; ARGS="<rows> <workers> <batch> [full|db-only|amqp-only]"
	docker compose run --rm php php tests/Functional/Scripts/amqp-relay-bench.php $(ARGS)

bench-consumer: ## Consumer throughput benchmark; ARGS="<messages> <workers> <prefetch> <handler-sleep-ms>"
	docker compose run --rm php php tests/Functional/Scripts/amqp-consumer-bench.php $(ARGS)
