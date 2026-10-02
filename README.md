# Wicket Directory

Configurable public directories of **Individuals** and **Organizations** from the Wicket member data platform (MDP). Visitors can search, filter, sort and page through them. Admins set up a directory once in WP Admin and place it on any page with the **Wicket Directory** block.

> **Status: in development.** The MVP plan is in [docs/engineering/mvp-plan.md](docs/engineering/mvp-plan.md) and the build tickets are in [docs/engineering/mvp-tickets.md](docs/engineering/mvp-tickets.md).

## Requirements

- WordPress 6.6+
- PHP 8.3+
- Wicket Base plugin active

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/industrialdev/wicket-wp-directory.git
cd wicket-wp-directory
composer install
composer setup-hooks
```

Activate through WordPress Admin → Plugins.

## Development

```bash
composer install       # Install dependencies (including dev)
composer cs:lint       # Check code style (dry run)
composer cs:fix        # Fix code style
composer production    # Fix style → remove dev deps → optimise autoloader
```

Tests live in the shared QA suite (`wicket-warden`), not in this repo.
