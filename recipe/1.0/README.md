# Symfony Flex Recipe for Freyr Message Broker

This directory contains the Symfony Flex recipe for automated installation and configuration of the Freyr Message Broker bundle.

## What This Recipe Does

When you run `composer require freyr/message-broker`, this recipe automatically:

1. ✅ Registers `FreyrMessageBrokerBundle` in your application
2. ✅ Creates `config/packages/message_broker.yaml` with default configuration
3. ✅ Creates `config/packages/messenger.yaml` with transport configuration
4. ✅ Copies database migration to `migrations/` directory
5. ✅ Adds `MESSENGER_AMQP_DSN` to your `.env` file
6. ✅ Displays post-install instructions

## Recipe Structure

```
recipe/1.0/
├── manifest.json                           # Recipe configuration
├── config/packages/
│   ├── message_broker.yaml                 # Bundle configuration
│   └── messenger.yaml                      # Messenger transports
├── migrations/
│   └── Version20250103000000.php           # Database tables migration
└── README.md                               # This file
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

To test this recipe without publishing:

1. In your test Symfony project, add this to `composer.json`:

```json
{
    "extra": {
        "symfony": {
            "allow-contrib": true
        }
    }
}
```

2. Create a local recipes repository or use Flex's testing features

## Recipe Versioning

- `1.0/` - Initial recipe for version 1.x of the bundle
- Future versions can be added as `2.0/`, `3.0/`, etc.

## What Gets Installed

### Configuration Files

**config/packages/message_broker.yaml:**
- Message type mappings for inbox (needs user customisation)
- Used by InboxSerializer to translate semantic names to PHP classes
- This is the ONLY required configuration for the bundle

**config/packages/messenger.yaml:**
- Multiple transports: `outbox`, `amqp` (publish), `amqp_orders` (consume example), `failed`
- Middleware configuration (DeduplicationMiddleware)
- Comments showing where to add domain event routing
- Example handler documentation

### Database Migration

**migrations/Version20250103000000.php:**
- Creates `messenger_outbox` table (binary UUID v7)
- Creates `message_broker_deduplication` table (binary UUID v7 for deduplication)
- Creates `messenger_messages` table (standard for failed messages)

### Environment Variables

**\.env:**
- `MESSENGER_AMQP_DSN` - Default RabbitMQ connection string

## Post-Installation Steps

After running `composer require freyr/message-broker`, users need to:

1. Configure inbox message types in `config/packages/message_broker.yaml`
2. Route domain events to outbox in `config/packages/messenger.yaml`
3. Run migrations: `php bin/console doctrine:migrations:migrate`
4. Start workers:
   - `php bin/console messenger:consume outbox -vv` (publish to AMQP)
   - `php bin/console messenger:consume amqp_orders -vv` (consume from AMQP)

## Customisation

Users can customise:
- Message type mappings in `message_broker.yaml` (inbox deserialisation)
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
