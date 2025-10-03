# Symfony Flex Recipes

This directory contains Symfony Flex recipes for automated bundle installation.

## Directory Structure

```
recipe/
└── 1.0/                    # Recipe for bundle version 1.x
    ├── manifest.json       # Recipe configuration & tasks
    ├── config/             # Configuration files to copy
    ├── migrations/         # Database migrations to copy
    └── README.md          # Recipe documentation
```

## How to Use

### For Development/Testing

While developing the bundle, you can test the recipe locally:

1. Create a test Symfony project
2. Configure a private recipe repository pointing to this directory
3. Install the bundle and verify the recipe works

See `1.0/README.md` for detailed instructions.

### For Production Use

**Option A: Private Recipe Repository**

For internal/private bundles:
1. Set up a private recipe server
2. Reference these recipes in your organization's recipe repository
3. Configure projects to use your private endpoint

**Option B: Contribute to symfony/recipes-contrib**

For public bundles:
1. Fork https://github.com/symfony/recipes-contrib
2. Copy `recipe/1.0/` to `freyr/message-broker/1.0/` in the fork
3. Submit a Pull Request
4. Once merged, recipe will auto-execute on `composer require`

## Recipe Versions

- **1.0/** - Initial recipe for v1.x (current)
- **2.0/** - Future recipe for v2.x (if breaking changes occur)

Each major version of the bundle should have its own recipe directory.

## What the Recipe Automates

When users run `composer require freyr/message-broker`, Flex automatically:

✅ Registers the bundle
✅ Copies configuration files
✅ Adds environment variables
✅ Copies database migrations
✅ Shows post-install instructions

**Zero manual configuration required!**

## Testing Checklist

Before publishing the recipe, verify:

- [ ] Fresh Symfony project installs cleanly
- [ ] Configuration files are created correctly
- [ ] Migration file is copied to `migrations/`
- [ ] Environment variables are added to `.env`
- [ ] Post-install message is helpful and accurate
- [ ] Bundle works immediately after `composer require`
- [ ] `composer remove` properly uninstalls everything

## Documentation

Full recipe documentation: `1.0/README.md`

## Resources

- [Symfony Flex Documentation](https://symfony.com/doc/current/quick_tour/flex_recipes.html)
- [Creating Recipes](https://github.com/symfony/recipes/blob/main/CONTRIBUTING.md)
- [Private Recipe Repositories](https://symfony.com/doc/current/setup/flex_private_recipes.html)
- [Recipe Repository](https://github.com/symfony/recipes-contrib)
