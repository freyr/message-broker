.PHONY: help build logs shell install test test-pgbouncer phpstan deptrac cs-check cs-fix infection split-rehearsal bench bench-consumer bench-webhook-server bench-webhook bench-outbox-cleanup

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

test-pgbouncer: ## Run the rotation guard, the PostgreSQL contract tests (also the flow control stores) and the consumer and webhook functional tests through pgbouncer (transaction mode)
	docker compose up -d --wait pgbouncer
	$(PGBOUNCER_RUN) --fail-on-skipped storage/postgresql/tests/Functional/PoolerRotationGuardTest.php
	$(PGBOUNCER_RUN) \
		storage/postgresql/tests/Functional/PostgreSqlOutboxStoreContractTest.php \
		storage/postgresql/tests/Functional/PostgreSqlDeduplicationStoreContractTest.php \
		storage/postgresql/tests/Functional/PostgreSqlDeadLetterStoreContractTest.php \
		storage/postgresql/tests/Functional/PostgreSqlDeliveryStoreContractTest.php \
		storage/postgresql/tests/Functional/PostgreSqlRateLimiterContractTest.php \
		storage/postgresql/tests/Functional/PostgreSqlCircuitBreakerContractTest.php \
		storage/postgresql/tests/Functional/PostgreSqlLaneStatesContractTest.php \
		storage/postgresql/tests/Functional/PdoTransactionContractTest.php \
		storage/postgresql/tests/Functional/WebhookEndToEndTest.php \
		transport/amqp/tests/Functional/AmqpConsumerTest.php \
		transport/amqp/tests/Functional/EndToEndTest.php \
		transport/amqp/tests/Functional/ParallelConsumptionTest.php

phpstan: ## Run PHPStan
	docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=-1

deptrac: ## Check the dependency rules between the layers
	docker compose run --rm php vendor/bin/deptrac analyse --no-progress

cs-check: ## Check code style with ECS
	docker compose run --rm php vendor/bin/ecs check

cs-fix: ## Fix code style with ECS
	docker compose run --rm php vendor/bin/ecs check --fix

infection: ## Mutation testing of the whole code (infection.repo.json5, full run, all CPU cores); ARGS for extra options
	docker compose run --rm php php -d memory_limit=1G vendor/bin/infection --configuration=infection.repo.json5 --threads=max $(ARGS)

split-rehearsal: ## Install one package alone and run its tests; PACKAGE=core|storage-postgresql|transport-amqp|webhook-http|ratelimit-redis, ARGS=--infection or --lowest
	docker compose run --rm php tools/split-rehearsal.sh $(PACKAGE) $(ARGS)

bench: ## Relay throughput benchmark; ARGS="<rows> <workers> <batch> [full|db-only|amqp-only]"
	docker compose run --rm php php transport/amqp/tests/Scripts/amqp-relay-bench.php $(ARGS)

bench-consumer: ## Consumer throughput benchmark; ARGS="<messages> <workers> <prefetch> <handler-sleep-ms>"
	docker compose run --rm php php transport/amqp/tests/Scripts/amqp-consumer-bench.php $(ARGS)

bench-webhook-server: ## Load check of the test HTTP server alone; ARGS="<rps> <delay-ms> <seconds>"
	docker compose up -d --wait webhook-server
	docker compose run --rm --no-deps webhook-server node /srv/load.mjs http://webhook-server:8080 $(ARGS)

bench-webhook: ## Webhook flow control benchmark; ARGS="<postgresql|redis> <lanes> <workers> <rps> <burst> <lane-rps> <batch> <endpoint-ms> <seconds>" (lane-rps 0 = no lane limit)
	docker compose run --rm php php tools/webhook-bench.php $(ARGS)

bench-outbox-cleanup: ## Outbox cleanup benchmark (PublishedRows::Mark and partition drop against PublishedRows::Delete); ARGS="<drain|steady> [key=value ...]"
	docker compose run --rm php php tools/outbox-cleanup-bench.php $(ARGS)
