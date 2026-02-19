# Symfony Flex Recipe for Freyr Message Broker

This directory contains the Symfony Flex recipe for automated installation and configuration of the Freyr Message Broker bundle.

## What This Recipe Does

When you run `composer require freyr/message-broker`, this recipe automatically:

1. Registers `FreyrMessageBrokerBundle` in your application
2. Creates `config/packages/message_broker.yaml` with default configuration
3. Creates `config/packages/messenger.yaml` with transport configuration
4. Adds `MESSENGER_AMQP_DSN` to your `.env` file
5. Displays post-install instructions

## Recipe Structure

```
recipe/1.0/
├── manifest.json                           # Recipe configuration
├── config/packages/
│   ├── message_broker.yaml                 # Bundle configuration
│   └── messenger.yaml                      # Messenger transports
└── README.md                               # This file
```

## Post-Installation: Deduplication Table Setup

The deduplication table is **not** created automatically by the recipe. Instead, use the
bundled console command to create it in the way that best suits your project:

### With doctrine/doctrine-migrations-bundle (recommended)

```bash
# 1. (Optional) Customise table name in config/packages/message_broker.yaml
# 2. Generate a migration file
php bin/console message-broker:setup-deduplication --migration

# 3. Run the migration
php bin/console doctrine:migrations:migrate
```

### Without doctrine-migrations-bundle

```bash
# Preview the SQL first
php bin/console message-broker:setup-deduplication

# Execute directly against the database
php bin/console message-broker:setup-deduplication --force
```

### Custom table name

To change the deduplication table name from the default (`message_broker_deduplication`),
edit `config/packages/message_broker.yaml` **before** running the setup command:

```yaml
message_broker:
    inbox:
        deduplication:
            table_name: 'my_custom_dedup_table'
```

## Using This Recipe

### Option 1: Private Recipe Repository (Recommended for Testing)

For testing or private use, you can configure Composer to use this recipe from your local package:

1. Add to your `composer.json`:

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://api.github.com/repos/YOUR-USERNAME/symfony-recipes/contents/index.json",
                "flex://defaults"
            ]
        }
    }
}
```

2. Create a private recipe repository following Symfony's structure
3. Reference this recipe in your repository

See: https://symfony.com/doc/current/setup/flex_private_recipes.html

### Option 2: Contribute to symfony/recipes-contrib (Public)

To make this recipe available to all Symfony users:

1. Fork https://github.com/symfony/recipes-contrib
2. Copy this `recipe/1.0/` directory to `freyr/message-broker/1.0/` in the fork
3. Create a Pull Request
4. Wait for approval from Symfony recipe maintainers

Once merged, anyone can install with automatic configuration:

```bash
composer require freyr/message-broker
```

## Testing the Recipe Locally

Symfony Flex does **not** support `file://` URLs. To test locally, serve the recipe over HTTP
or use a GitHub repository. See `recipe/TESTING.md` for full details.

**Quick approach — local HTTP server:**

1. Build the recipe JSON files (see `recipe/TESTING.md`)
2. Serve them: `php -S 127.0.0.1:8088` from the recipe directory
3. In your test project's `composer.json`:

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "http://127.0.0.1:8088/index.json",
                "flex://defaults"
            ]
        }
    }
}
```

4. Run `composer require freyr/message-broker`

## Recipe Versioning

- `1.0/` - Initial recipe for version 1.x of the bundle
- Future versions can be added as `2.0/`, `3.0/`, etc.

## What Gets Installed

### Configuration Files

**config/packages/message_broker.yaml:**
- Message type mappings for inbox (needs user customisation)
- Deduplication table name (defaults to `message_broker_deduplication`)
- Used by InboxSerializer to translate semantic names to PHP classes

**config/packages/messenger.yaml:**
- Multiple transports: `outbox`, `amqp` (publish), `amqp_your_inbox` (consume template), `failed`
- Middleware configuration (DeduplicationMiddleware)
- Comments showing where to add domain event routing
- Example handler documentation

### Environment Variables

**\.env:**
- `MESSENGER_AMQP_DSN` - Default RabbitMQ connection string

## Prerequisites

This bundle uses the `doctrine_transaction` middleware, which requires **Doctrine ORM** to be
configured in your application. Most Symfony applications using Doctrine already have this in
`config/packages/doctrine.yaml`. If you do not have ORM configured, add a minimal `orm:` section:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(DATABASE_URL)%'
    orm:
        auto_generate_proxy_classes: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
```

The bundle itself only uses DBAL (not ORM entities), but the middleware requires ORM to be bootstrapped.

## Post-Installation Steps

After running `composer require freyr/message-broker`, users need to:

1. Configure inbox message types in `config/packages/message_broker.yaml`
2. Route domain events to outbox in `config/packages/messenger.yaml`
3. Create the deduplication table: `php bin/console message-broker:setup-deduplication --migration`
4. Run migrations: `php bin/console doctrine:migrations:migrate`
5. Start workers:
   - `php bin/console messenger:consume outbox -vv` (publish to AMQP)
   - `php bin/console messenger:consume amqp_your_inbox -vv` (consume from AMQP)

## Customisation

Users can customise:
- Message type mappings in `message_broker.yaml` (inbox deserialisation)
- Deduplication table name in `message_broker.yaml`
- Transport DSNs in `messenger.yaml` (including table names)
- AMQP connection string in `.env`
- Middleware priority (DeduplicationMiddleware)

## Documentation

Full documentation is available in the package:
- `vendor/freyr/message-broker/README.md`
- `vendor/freyr/message-broker/docs/`

## Support

- Issues: https://github.com/freyr/message-broker/issues
- Discussions: https://github.com/freyr/message-broker/discussions
