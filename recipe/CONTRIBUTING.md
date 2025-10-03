# Contributing the Recipe to symfony/recipes-contrib

This guide explains how to submit this Flex recipe to the official Symfony recipes repository.

## Before You Start

### Prerequisites

- [ ] Bundle is published on Packagist
- [ ] Bundle has stable version (1.0.0 or higher)
- [ ] Recipe has been tested locally (see `TESTING.md`)
- [ ] Recipe works on Symfony 6.4 and 7.0+
- [ ] You have a GitHub account

### Repository Choice

**symfony/recipes** vs **symfony/recipes-contrib**:

- **symfony/recipes**: Only for Symfony official packages
- **symfony/recipes-contrib**: For community packages ← **Use this one**

## Submission Process

### Step 1: Fork the Repository

```bash
# Fork on GitHub: https://github.com/symfony/recipes-contrib
# Then clone your fork
git clone https://github.com/YOUR_USERNAME/recipes-contrib.git
cd recipes-contrib
```

### Step 2: Create Recipe Directory

```bash
# Create directory structure
mkdir -p freyr/message-broker/1.0

# Copy recipe files
cp -r /path/to/messenger/recipe/1.0/* freyr/message-broker/1.0/
```

### Step 3: Verify Recipe Structure

Your directory should look like:

```
freyr/message-broker/1.0/
├── manifest.json
├── config/
│   └── packages/
│       ├── message_broker.yaml
│       └── messenger.yaml
└── migrations/
    └── Version20250103000000.php
```

**Do NOT include:**
- `README.md` (documentation goes in main bundle)
- Test files
- Non-essential files

### Step 4: Validate Recipe

```bash
# From recipes-contrib root
composer validate-recipe freyr/message-broker/1.0
```

### Step 5: Create Pull Request

```bash
git checkout -b recipe-freyr-message-broker
git add freyr/message-broker/1.0/
git commit -m "Add recipe for freyr/message-broker"
git push origin recipe-freyr-message-broker
```

Then:
1. Go to https://github.com/symfony/recipes-contrib
2. Click "New Pull Request"
3. Select your fork and branch
4. Fill in the PR template

### Step 6: PR Description Template

```markdown
## Recipe for freyr/message-broker

This recipe provides automated installation and configuration for the Freyr Message Broker bundle.

**Package:** freyr/message-broker
**Version:** 1.0
**Type:** symfony-bundle

### What this recipe does:

- ✅ Registers FreyrMessageBrokerBundle
- ✅ Creates config/packages/message_broker.yaml with inbox/outbox configuration
- ✅ Creates config/packages/messenger.yaml with transport configuration
- ✅ Copies database migration for messenger tables
- ✅ Adds MESSENGER_AMQP_DSN environment variable
- ✅ Displays helpful post-install instructions

### Testing:

- [x] Tested on Symfony 6.4
- [x] Tested on Symfony 7.0
- [x] Fresh installation works
- [x] Configuration files are created correctly
- [x] Migrations are copied successfully
- [x] Post-install message is helpful

### Documentation:

Package documentation: https://github.com/freyr/message-broker
```

## Review Process

### What Reviewers Will Check

1. **Recipe Quality**
   - manifest.json is properly formatted
   - Configurators are used correctly
   - File paths are correct

2. **Configuration Quality**
   - Config files use best practices
   - Sensible defaults
   - Well-commented

3. **User Experience**
   - Post-install message is helpful
   - Zero-friction installation
   - Clear next steps

4. **Package Quality**
   - Package exists on Packagist
   - Has stable version
   - Has documentation

### Common Review Feedback

**"Use environment variables for sensitive data"**
✅ Already done: MESSENGER_AMQP_DSN in `.env`

**"Provide sensible defaults"**
✅ Already done: localhost, default ports, commented examples

**"Keep configuration minimal"**
✅ Already done: Only essential config, rest is documented

**"Post-install message should be concise"**
✅ Review if too long, keep to essential steps only

## Timeline

- **Initial Review**: 1-7 days
- **Revisions**: As needed
- **Final Approval**: 1-14 days total
- **Merge**: Automatic once approved

## After Merge

Once your recipe is merged:

### It Becomes Active Immediately

Users can now run:

```bash
composer require freyr/message-broker
```

And get:
- ✅ Automatic bundle registration
- ✅ Automatic configuration
- ✅ Migrations ready to run
- ✅ Zero manual setup

### Update Your README

Add this to the bundle README:

```markdown
## Installation

```bash
composer require freyr/message-broker
\```

That's it! Symfony Flex will automatically:
- Register the bundle
- Create configuration files
- Copy database migrations
- Add environment variables

Just run migrations and start consuming:

```bash
php bin/console doctrine:migrations:migrate
php bin/console messenger:consume inbox outbox -vv
\```
```

### Announce It

- Blog post
- Social media
- Symfony community channels

## Maintaining the Recipe

### When to Create a New Recipe Version

Create `2.0/` when:
- Breaking configuration changes
- Different file structure
- Incompatible with 1.x configuration

### Updating Existing Recipe

Small updates (typos, better comments):
1. Submit new PR to recipes-contrib
2. Update files in `freyr/message-broker/1.0/`
3. Explain changes in PR

**Note:** Users won't auto-update. They can run:
```bash
composer symfony:recipes:update freyr/message-broker
```

## Resources

- [Symfony Flex Docs](https://symfony.com/doc/current/quick_tour/flex_recipes.html)
- [Recipe Contributing Guide](https://github.com/symfony/recipes-contrib/blob/main/CONTRIBUTING.md)
- [Recipe Examples](https://github.com/symfony/recipes-contrib/tree/main)
- [Flex Repository](https://github.com/symfony/flex)

## Getting Help

- **Symfony Slack**: #flex channel
- **GitHub Discussions**: symfony/recipes-contrib
- **Stack Overflow**: Tag `symfony-flex`

## Checklist

Before submitting PR:

- [ ] Recipe tested locally (see TESTING.md)
- [ ] Works on Symfony 6.4
- [ ] Works on Symfony 7.0
- [ ] Package published on Packagist
- [ ] Package has stable version (1.0.0+)
- [ ] manifest.json is valid JSON
- [ ] Configuration files use Symfony best practices
- [ ] Post-install message is helpful
- [ ] PR description is complete
- [ ] No unnecessary files included
- [ ] Ready for review! 🚀
