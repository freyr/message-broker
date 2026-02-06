# Testing the Flex Recipe Locally

This guide shows how to test the Symfony Flex recipe before publishing it.

> **Important:** Symfony Flex does **not** support `file://` URLs. All recipe endpoints
> must be served over HTTP/HTTPS.

## Method 1: Local HTTP Server (Fastest Iteration)

Serve the recipe JSON files from your machine using PHP's built-in server. No GitHub
repository required.

### Step 1: Build the Recipe JSON

Flex expects two JSON files in a specific format. Create a directory for them:

```bash
mkdir -p ~/flex-recipes
```

Create `~/flex-recipes/index.json`:

```json
{
    "recipes": {
        "freyr/message-broker": ["1.0"]
    },
    "branch": "main",
    "is_contrib": true,
    "_links": {
        "repository": "localhost",
        "origin_template": "{package}:{version}@localhost:main",
        "recipe_template": "http://127.0.0.1:8088/{package_dotted}.{version}.json"
    }
}
```

Create `~/flex-recipes/freyr.message-broker.1.0.json`. This file wraps the manifest and
inlines all recipe file contents as arrays of lines:

```json
{
    "manifests": {
        "freyr/message-broker": {
            "manifest": {
                "bundles": {
                    "Freyr\\MessageBroker\\FreyrMessageBrokerBundle": ["all"]
                },
                "copy-from-recipe": {
                    "config/": "%CONFIG_DIR%/",
                    "migrations/": "%MIGRATIONS_DIR%/"
                },
                "env": {
                    "MESSENGER_AMQP_DSN": "amqp://guest:guest@localhost:5672/%2f"
                }
            },
            "files": {
                "config/packages/message_broker.yaml": {
                    "contents": [
                        "message_broker:",
                        "    inbox:",
                        "        message_types:",
                        "            # 'order.placed': 'App\\\\Message\\\\OrderPlaced'"
                    ],
                    "executable": false
                },
                "config/packages/messenger.yaml": {
                    "contents": ["... each line of messenger.yaml as a separate element ..."],
                    "executable": false
                },
                "config/packages/doctrine.yaml": {
                    "contents": ["... each line of doctrine.yaml as a separate element ..."],
                    "executable": false
                },
                "migrations/Version20250103000001.php": {
                    "contents": ["... each line of the migration file ..."],
                    "executable": false
                }
            },
            "ref": "REPLACE_WITH_RANDOM_HEX"
        }
    }
}
```

Generate a `ref` value:

```bash
php -r "echo bin2hex(random_bytes(20)) . PHP_EOL;"
```

### Step 2: Start the Server

```bash
cd ~/flex-recipes
php -S 127.0.0.1:8088
```

### Step 3: Configure Test Project

In your test Symfony project's `composer.json`:

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

### Step 4: Install and Iterate

```bash
composer require freyr/message-broker

# Re-install after changing the recipe (regenerate ref first)
composer recipes:install freyr/message-broker --force
```

## Method 2: Private GitHub Repository (Recommended for CI/Team Use)

Host recipes in a GitHub repository with automatic JSON generation via a GitHub Action.

### Step 1: Create a GitHub Repository

Create a repository (e.g., `freyr/recipes`) with this structure on the `main` branch:

```
freyr/
  message-broker/
    1.0/
      manifest.json
      config/
        packages/
          message_broker.yaml
          messenger.yaml
          doctrine.yaml
      migrations/
        Version20250103000001.php
```

### Step 2: Add the Flex Update GitHub Action

Create `.github/workflows/flex-update.yml`:

```yaml
name: Update Flex endpoint

on:
  push:
    branches: [main]

jobs:
  call-flex-update:
    uses: symfony/recipes/.github/workflows/callable-flex-update.yml@main
    with:
      versions_json: .github/versions.json
```

This action reads `manifest.json` files from `main`, generates the machine-readable JSON
format, and pushes it to a `flex/main` branch automatically.

### Step 3: Configure the Consumer Project

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://api.github.com/repos/freyr/recipes/contents/index.json",
                "flex://defaults"
            ]
        }
    }
}
```

> **Note:** The URL must use `https://api.github.com/repos/...` — not `github.com` or
> `raw.githubusercontent.com`.

### Step 4: Install

```bash
composer require freyr/message-broker
```

## Method 3: Manual File Copy (Quick Smoke Test)

For a quick check that recipe files are valid, without testing Flex itself:

```bash
cd your-test-project

# Copy config files
cp vendor/freyr/message-broker/recipe/1.0/config/packages/*.yaml config/packages/

# Copy migration
cp vendor/freyr/message-broker/recipe/1.0/migrations/*.php migrations/

# Add env variable
echo "MESSENGER_AMQP_DSN=amqp://guest:guest@localhost:5672/%2f" >> .env

# Register the bundle manually in config/bundles.php
```

This does **not** test Flex recipe execution — only that the shipped files work in a real
application.

## Useful Commands

| Command | Purpose |
|---------|---------|
| `composer recipes` | List installed recipes and check for updates |
| `composer recipes:install freyr/message-broker --force` | Re-install a recipe (overwrites files) |
| `composer require freyr/message-broker -vvv` | Verbose output for debugging recipe resolution |

## Validation Checklist

After installation, verify:

### Configuration Files

- [ ] `config/packages/message_broker.yaml` exists
- [ ] `config/packages/messenger.yaml` exists
- [ ] `config/packages/doctrine.yaml` has `id_binary` type registered
- [ ] Configuration is valid: `php bin/console debug:config message_broker`

### Database

- [ ] Migration file in `migrations/` directory
- [ ] Migration runs: `php bin/console doctrine:migrations:migrate`
- [ ] `message_broker_deduplication` table created

### Services

- [ ] Bundle registered in `config/bundles.php`
- [ ] Serialisers registered: `php bin/console debug:container | grep Serializer`

### Messenger

- [ ] Transports configured: `php bin/console debug:messenger`
- [ ] Transports listed: `outbox`, `amqp`, `failed`

### Environment

- [ ] `MESSENGER_AMQP_DSN` in `.env`
- [ ] Readable: `php bin/console debug:container --env-vars | grep MESSENGER`

## Testing Uninstallation

Flex should reverse recipe actions on removal:

```bash
composer remove freyr/message-broker
```

Verify:
- Bundle removed from `config/bundles.php`
- Configuration files removed
- **Note:** Migrations and `.env` changes are typically **not** removed

## References

- [Symfony Flex Private Recipes](https://symfony.com/doc/current/setup/flex_private_recipes.html)
- [symfony/recipes Repository](https://github.com/symfony/recipes)
- [symfony/flex Downloader Source](https://github.com/symfony/flex/blob/2.x/src/Downloader.php)
