# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Plugin Overview

FreemKit is a WordPress plugin (v1.0.0-beta1) that bridges Freemius software licensing with Kit (formerly ConvertKit) email marketing. It receives Freemius webhook events and subscribes customers to Kit forms/tags based on whether they are free or paid users. Namespace: `WebberZone\FreemKit`. Prefix: `freemkit_`. Requires WordPress 5.0+, PHP 7.4+.

## Commands

### PHP

```bash
composer phpcs          # Lint PHP (WordPress coding standards)
composer phpcbf         # Auto-fix PHP code style
composer phpstan        # Static analysis (level configured in phpstan.neon.dist)
composer phpcompat      # Check PHP 7.4–8.5 compatibility
composer test           # Run all checks (phpcs + phpcompat + phpstan)
composer build:vendor   # Install production-only dependencies
composer zip            # Create distribution zip
```

### JavaScript/CSS

```bash
npm run build           # Build JS/CSS assets with wp-scripts
npm run build:assets    # Minify CSS/JS and generate RTL CSS (node build-assets.js)
npm start               # Watch mode
npm run lint:js         # ESLint
npm run lint:css        # Stylelint
npm run format          # Format with wp-scripts
npm run zip             # Create plugin zip via wp-scripts
```

## Architecture

### Entry Point & Bootstrap

`freemkit.php` defines constants (`FREEMKIT_VERSION`, `FREEMKIT_PLUGIN_FILE`, `FREEMKIT_PLUGIN_DIR`, `FREEMKIT_PLUGIN_URL`), loads Kit shared library classes from `vendor/convertkit/convertkit-wordpress-libraries/`, registers the autoloader, and calls `\WebberZone\FreemKit\load()` on `plugins_loaded`.

**Autoloader convention:** Namespace segments become path segments under `includes/`; underscores → hyphens, lowercase, last segment prefixed with `class-`. e.g. `WebberZone\FreemKit\Admin\Settings` → `includes/admin/class-settings.php`.

### Core Components

- **`Main`** (`includes/class-main.php`) — Singleton. Instantiates `Runtime`, `Kit_Credential_Hooks`, and `Language_Handler`; registers hooks via `Hook_Registry`.
- **`Runtime`** (`includes/class-runtime.php`) — Initializes `Database`, `Kit_API`, and `Webhook_Handler` on `init`. Creates the `Admin` object when in admin context.
- **`Webhook_Handler`** (`includes/class-webhook-handler.php`) — Core logic. Registers a REST endpoint at `freemkit/v1/webhook` (or a query-var fallback). Validates HMAC-SHA256 signatures (`x-signature` header) and webhook freshness (15-minute window). Queues events as WP transients and processes them asynchronously via WP-Cron (`freemkit_process_webhook_event`). Includes deduplication via `freemkit_webhook_seen_*` transients, and exponential-backoff retry (max 3 attempts).
- **`Database`** (`includes/class-database.php`) — Manages the `{prefix}freemkit_subscribers` custom table, which persists subscriber records locally.
- **`Options_API`** (`includes/class-options-api.php`) — All settings stored as a single `freemkit_settings` array in `wp_options`. Access via `Options_API::get_option($key)` / `Options_API::get_settings()`. Sensitive keys (access/refresh tokens) are encrypted at rest using AES-256-CBC (OpenSSL) or libsodium.

### Kit Integration (`includes/kit/`)

- **`Kit_API`** — Wraps `ConvertKit_API_V4` to subscribe users to forms and apply tags.
- **`Kit_Settings`** — Manages OAuth tokens (`kit_access_token`, `kit_refresh_token`). Falls back to the official Kit WordPress plugin's stored credentials if available.
- **`Kit_Credential_Hooks`** — Listens for `freemkit_api_get_access_token` / `freemkit_api_refresh_token` / `convertkit_api_access_token_invalid` actions to keep tokens in sync.
- **`Kit_Audit_Log`** — Logs Kit API interactions.

### Admin (`includes/admin/`)

- **`Admin`** — Admin menu, settings page, subscriber list screen.
- **`Settings`** / **`Settings_Wizard`** — Settings pages; wizard is shown on first activation. Settings include: global Kit form/tag defaults, per-plugin overrides (free and paid form IDs, tag IDs, event types), custom field mappings, webhook endpoint type (REST vs query-var).
- **`Kit_OAuth`** — OAuth flow for connecting to Kit.
- **`Subscribers_List`** / **`Subscribers_List_Table`** — Admin screen displaying the local `freemkit_subscribers` table.

### Utilities (`includes/util/`)

- **`Hook_Registry`** — Static registry for all registered actions/filters; prevents duplicates (same pattern as CRP).

## Key Patterns

- **Webhook event routing:** Freemius sends a `type` field (e.g. `install.installed`, `license.created`). Default free events: `['install.installed']`; default paid events: `['license.created']`. Both can be overridden per-plugin config or via `freemkit_default_free_event_types` / `freemkit_default_paid_event_types` filters.
- **Per-plugin config:** The settings store a `plugins` array, each entry keyed by Freemius plugin ID, with separate free/paid form IDs, tag IDs, and event types. Falls back to global kit form/tag settings when per-plugin values are empty.
- **Settings access:** Always use `Options_API::get_option($key)` rather than reading `freemkit_settings` directly.
- **Hook registration:** Use `Hook_Registry::add_action()` / `Hook_Registry::add_filter()` rather than WordPress functions directly.
- **Async processing:** Webhooks are never processed synchronously (except when WP-Cron is disabled or scheduling fails). Always go through `queue_webhook_event()` → `process_queued_webhook()`.
