shell:
	docker compose run --rm -it php sh

qa: unit stan ecs

unit:
	vendor/bin/phpunit
stan:
	vendor/bin/phpstan analyze -v
ecs:
	vendor/bin/ecs --fix

reset:
	docker compose down -v
	docker compose up -d

setup:
	docker compose run --rm -it php php bin/setup.php

send:
	docker compose run --rm -it php php bin/sender.php

consume:
	docker compose run --rm -it php php consume.php

