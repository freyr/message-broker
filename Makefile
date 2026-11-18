.PHONY: help build logs shell install test test-pgbouncer phpstan deptrac cs-check cs-fix infection split-rehearsal bench bench-consumer

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

# The pooler is started first: `docker compose run --rm php` starts only the services php depends on.
PGBOUNCER_RUN = docker compose run --rm -e "POSTGRES_DSN=pgsql:host=pgbouncer;port=6432;dbname=message_broker_test" -e POSTGRES_ROTATING_POOLER=1 php vendor/bin/phpunit

test-pgbouncer: ## Run the rotation guard, the PostgreSQL contract tests and the consumer functional tests through pgbouncer (transaction mode)
	docker compose up -d --wait pgbouncer
	$(PGBOUNCER_RUN) tests/adapter/postgresql/Functional/PoolerRotationGuardTest.php
	$(PGBOUNCER_RUN) \
		tests/adapter/postgresql/Functional/PostgreSqlOutboxStoreContractTest.php \
		tests/adapter/postgresql/Functional/PostgreSqlDeduplicationStoreContractTest.php \
		tests/adapter/postgresql/Functional/PostgreSqlDeadLetterStoreContractTest.php \
		tests/adapter/postgresql/Functional/PdoTransactionContractTest.php \
		tests/adapter/amqp/Functional/AmqpConsumerTest.php \
		tests/adapter/amqp/Functional/EndToEndTest.php \
		tests/adapter/amqp/Functional/ParallelConsumptionTest.php

phpstan: ## Run PHPStan
	docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1

deptrac: ## Check the dependency rules between the layers
	docker compose run --rm php vendor/bin/deptrac analyse --no-progress

cs-check: ## Check code style with ECS
	docker compose run --rm php vendor/bin/ecs check

cs-fix: ## Fix code style with ECS
	docker compose run --rm php vendor/bin/ecs check --fix

infection: ## Mutation testing with Infection and pcov (full run, all CPU cores); ARGS for extra options
	docker compose run --rm php vendor/bin/infection --threads=max $(ARGS)

split-rehearsal: ## Split rehearsal of one future package; PACKAGE=core|postgresql|amqp, ARGS=--infection
	docker compose run --rm php tools/split-rehearsal.sh $(PACKAGE) $(ARGS)

bench: ## Relay throughput benchmark; ARGS="<rows> <workers> <batch> [full|db-only|amqp-only]"
	docker compose run --rm php php tests/adapter/amqp/Scripts/amqp-relay-bench.php $(ARGS)

bench-consumer: ## Consumer throughput benchmark; ARGS="<messages> <workers> <prefetch> <handler-sleep-ms>"
	docker compose run --rm php php tests/adapter/amqp/Scripts/amqp-consumer-bench.php $(ARGS)
