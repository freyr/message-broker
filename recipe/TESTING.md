# Testing the Flex Recipe Locally

This guide shows how to test the Symfony Flex recipe before publishing it.

## Method 1: Local Recipe Repository (Recommended)

### Step 1: Create a Local Recipe Server

Create a simple local recipe repository structure:

```bash
mkdir -p ~/symfony-recipes/freyr/message-broker/1.0
cp -r recipe/1.0/* ~/symfony-recipes/freyr/message-broker/1.0/
```

### Step 2: Create index.json

Create `~/symfony-recipes/index.json`:

```json
{
    "recipes": {
        "freyr/message-broker": [
            "file:///"
        ]
    }
}
```

### Step 3: Configure Test Project

In your test Symfony project, add to `composer.json`:

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "file:///Users/YOUR_USERNAME/symfony-recipes/index.json",
                "flex://defaults"
            ],
            "allow-contrib": true
        }
    }
}
```

### Step 4: Test Installation

```bash
cd your-test-project
composer require freyr/message-broker
```

### Step 5: Verify

Check that these files were created:
- `config/bundles.php` - Bundle registered
- `config/packages/message_broker.yaml` - Configuration created
- `config/packages/messenger.yaml` - Messenger config created
- `migrations/Version20250103000000.php` - Migration copied
- `.env` - `MESSENGER_AMQP_DSN` added

## Method 2: Using Symfony Recipes Test Server

### Prerequisites

```bash
composer global require symfony/flex
git clone https://github.com/symfony/recipes-contrib.git
```

### Setup

1. Copy recipe to contrib repository:

```bash
cd symfony-recipes-contrib
mkdir -p freyr/message-broker/1.0
cp -r /path/to/messenger/recipe/1.0/* freyr/message-broker/1.0/
```

2. Start local recipe server:

```bash
symfony local:server:start --recipe-server
```

3. Configure test project to use local server:

```json
{
    "extra": {
        "symfony": {
            "endpoint": "http://127.0.0.1:8000",
            "allow-contrib": true
        }
    }
}
```

## Method 3: Direct Testing (Quick & Dirty)

For quick testing without a recipe server:

### Step 1: Manually Install Bundle

```bash
cd your-test-project
composer require freyr/message-broker --no-scripts
```

### Step 2: Manually Run Recipe Tasks

```bash
# Copy config files
cp vendor/freyr/message-broker/recipe/1.0/config/packages/message_broker.yaml config/packages/
cp vendor/freyr/message-broker/recipe/1.0/config/packages/messenger.yaml config/packages/

# Copy migration
cp vendor/freyr/message-broker/recipe/1.0/migrations/Version20250103000000.php migrations/

# Add env variable
echo "\n# Message Broker\nMESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f" >> .env
```

### Step 3: Verify Everything Works

```bash
# Run migration
php bin/console doctrine:migrations:migrate

# Check services are registered
php bin/console debug:container | grep MessageBroker

# Try to start consumers
php bin/console messenger:consume --help
```

## Validation Checklist

After testing installation, verify:

### ✅ Configuration Files

- [ ] `config/packages/message_broker.yaml` exists
- [ ] `config/packages/messenger.yaml` exists (or merged correctly)
- [ ] Configuration syntax is valid: `php bin/console debug:config message_broker`

### ✅ Database

- [ ] Migration file in `migrations/` directory
- [ ] Migration runs successfully: `php bin/console doctrine:migrations:migrate`
- [ ] Tables created: `messenger_outbox`, `message_broker_deduplication`, `messenger_messages`

### ✅ Services

- [ ] Bundle registered in `config/bundles.php`
- [ ] Transport factories registered: `php bin/console debug:container | grep TransportFactory`
- [ ] Serializers registered: `php bin/console debug:container | grep Serializer`

### ✅ Messenger

- [ ] Transports configured: `php bin/console debug:messenger`
- [ ] Can list transports: `outbox`, `amqp`, `failed`

### ✅ Environment

- [ ] `MESSENGER_AMQP_DSN` in `.env` file
- [ ] Can read env: `php bin/console debug:container --env-vars | grep MESSENGER`

### ✅ Documentation

- [ ] Post-install message is helpful
- [ ] README is accessible in `vendor/freyr/message-broker/README.md`
- [ ] Examples can be followed

## Testing Uninstallation

Flex should reverse all recipe actions:

```bash
composer remove freyr/message-broker
```

Verify these are removed:
- Bundle from `config/bundles.php`
- Configuration files (if added by recipe)
- **Note:** Migrations and `.env` changes are typically NOT removed

## Common Issues

### Issue: Recipe Not Executing

**Cause:** Flex not detecting recipe
**Solution:** Clear Flex cache: `composer symfony:recipes:install --force --reset`

### Issue: Config Files Not Copied

**Cause:** `copy-from-recipe` path incorrect
**Solution:** Verify paths in `manifest.json` use correct variables:
- `%CONFIG_DIR%` → `config/`
- `%MIGRATIONS_DIR%` → `migrations/`

### Issue: Bundle Not Registered

**Cause:** `bundles` configurator not working
**Solution:** Check bundle class name in `manifest.json` matches exactly

## Next Steps

Once testing is successful:

1. ✅ All validation checks pass
2. ✅ Installation works on fresh Symfony 6.4 project
3. ✅ Installation works on fresh Symfony 7.0 project
4. ✅ Uninstallation cleans up correctly
5. ✅ Ready to submit to symfony/recipes-contrib

See `CONTRIBUTING.md` for submission instructions.
