# FreemKit

[![License](https://img.shields.io/badge/license-GPL_v2%2B-orange.svg?style=flat-square)](https://opensource.org/licenses/GPL-2.0)

__Requires at least:__ 6.6

__Tested up to:__ 7.0

__Requires PHP:__ 7.4

__License:__ [GPL-2.0+](http://www.gnu.org/licenses/gpl-2.0.html)

__Plugin page:__ [FreemKit](https://webberzone.com/plugins/freemkit/)

Seamlessly connect your Freemius with Kit email marketing to automate customer communications and marketing workflows.

## Description

WebberZone's FreemKit bridges the gap between Freemius and Kit (formerly ConvertKit) email marketing platforms, enabling WordPress plugin and theme developers to automate subscription workflows and enhance customer communication.

FreemKit receives Freemius webhook events in real time and subscribes customers to Kit forms and tags based on their subscription status. Webhooks are signature-verified using HMAC-SHA256, queued as WP transients, and processed asynchronously via WP-Cron — with deduplication and exponential-backoff retry built in. Subscriber records are persisted locally in a custom database table.

## Key features

- __Real-time Webhook Integration__ — Automatically sync customer data between Freemius and Kit through secure, signature-verified webhooks
- __Automated List Management__ — Add or remove customers from Kit forms/tags based on their Freemius subscription status
- __Custom Field Mapping__ — Map Freemius customer data to Kit custom fields for personalised email marketing
- __Per-plugin Configuration__ — Configure separate free/paid form IDs, tag IDs, and event types for each of your Freemius products
- __Secure Implementation__ — HMAC-SHA256 signature verification, nonce checks, capability checks, and sanitised input throughout
- __Flexible Webhook Endpoint__ — Choose between a REST API endpoint or a query variable fallback
- __Async Processing__ — Webhooks are queued and processed via WP-Cron with deduplication and retry logic
- __Local Subscriber Records__ — Subscriber data is stored in a custom database table for reference and auditing
- __Setup Wizard__ — Get started quickly with a guided setup wizard on first activation
- __Developer-Friendly__ — Extensible through WordPress filters and actions

## Installation

### Manual install

1. Download the latest release from the [releases page](https://github.com/WebberZone/freemkit/releases)
2. Upload the `freemkit` folder to `/wp-content/plugins/`
3. Activate the plugin through the __Plugins__ menu in WordPress
4. Navigate to __Settings → FreemKit__ and connect your Kit account via OAuth
5. Enter your __Freemius Secret Key__ and register the webhook URL in Freemius

## Setting Up the Freemius Webhook

FreemKit receives events from Freemius in real time via webhooks. You must register the webhook URL in your Freemius Developer Dashboard for the integration to work.

### Step 1 — Find your Webhook URL

Go to __Settings → FreemKit → Webhook__ in your WordPress admin. The plugin shows the correct URL based on your chosen endpoint type:

| Endpoint type | URL format |
|---|---|
| REST API *(recommended)* | `https://yourdomain.com/wp-json/freemkit/v1/webhook` |
| Query variable | `https://yourdomain.com/?freemkit_webhook=1` |

You can switch between the two under __Webhook Endpoint Type__ on the same settings page.

### Step 2 — Register the webhook in Freemius

1. Log in to the [Freemius Developer Dashboard](https://dashboard.freemius.com) and open your product
2. Go to __Integrations → Webhooks → Listeners__ and click __Add Webhook__
3. Paste the Webhook URL from Step 1
4. Choose the events to subscribe to. Recommended minimum:
   - `install.installed` — fires when a user installs your product (maps to free users)
   - `license.created` — fires when a paid license is created (maps to paid users)
5. Save the webhook. You can activate it immediately or defer activation.

### Step 3 — Add your Freemius Secret Key

Freemius signs every webhook request with your product's __Secret Key__ using HMAC-SHA256. FreemKit verifies this signature and will reject any request that doesn't match.

1. In the Freemius Developer Dashboard, go to your product's __Settings → General__ and copy the __Secret Key__
2. Paste it into __Settings → FreemKit → Freemius Secret Key__ and save

## Configuration

After activation, the setup wizard guides you through the initial configuration. You can also configure the plugin at __Settings → FreemKit__:

1. __Connect Kit__ — Authenticate via OAuth to link your Kit account
2. __Set Global Defaults__ — Configure default Kit form/tag IDs for free and paid users
3. __Per-plugin Overrides__ — Set separate form IDs, tag IDs, and event types per Freemius product
4. __Custom Field Mapping__ — Map Freemius customer data fields to Kit custom fields
5. __Webhook Settings__ — Choose your endpoint type and review the webhook URL to register in Freemius

## Development

```bash
# Lint PHP (WordPress coding standards)
composer phpcs

# Auto-fix PHP code style
composer phpcbf

# Static analysis
composer phpstan

# Run all checks
composer test

# Build JS/CSS assets
npm run build

# Watch mode
npm start
```

## Other plugins by WebberZone

FreemKit is one of the many plugins developed by WebberZone. Check out our other plugins:

- [Contextual Related Posts](https://wordpress.org/plugins/contextual-related-posts/) - Display related posts on your WordPress blog and feed
- [Top 10](https://wordpress.org/plugins/top-10/) - Track daily and total visits to your blog posts and display the popular and trending posts
- [WebberZone Snippetz](https://wordpress.org/plugins/add-to-all/) - The ultimate snippet manager for WordPress to create and manage custom HTML, CSS or JS code snippets
- [Knowledge Base](https://wordpress.org/plugins/knowledgebase/) - Create a knowledge base or FAQ section on your WordPress site
- [Better Search](https://wordpress.org/plugins/better-search/) - Enhance the default WordPress search with contextual results sorted by relevance
- [Auto-Close](https://wordpress.org/plugins/autoclose/) - Automatically close comments, pingbacks and trackbacks and manage revisions
- [Popular Authors](https://wordpress.org/plugins/popular-authors/) - Display popular authors in your WordPress widget
- [Followed Posts](https://wordpress.org/plugins/where-did-they-go-from-here/) - Show a list of related posts based on what your users have read

## Contribute

FreemKit is also available on [GitHub](https://github.com/WebberZone/freemkit).
So, if you've got a cool feature you'd like to implement in the plugin or a bug you've fixed, consider forking the project and sending me a pull request.

Bug reports are [welcomed on GitHub](https://github.com/WebberZone/freemkit/issues). Please note that GitHub is *not* a support forum, and issues that aren't suitably qualified as bugs will be closed.

## Support

- __Documentation__: [webberzone.com/support/freemkit/](https://webberzone.com/support/freemkit/)
- __GitHub Issues__: [github.com/WebberZone/freemkit/issues](https://github.com/WebberZone/freemkit/issues)

## Translations

FreemKit is available for [translation directly on WordPress.org](https://translate.wordpress.org/projects/wp-plugins/freemkit). Check out the official [Translator Handbook](https://make.wordpress.org/polyglots/handbook/plugin-theme-authors-guide/) to contribute.

## License

This plugin is licensed under the GPL-2.0+ license.

## Credits

FreemKit is developed and maintained by [WebberZone](https://webberzone.com/).

## About this repository

This GitHub repository always holds the latest development version of the plugin. Beta and stable releases are made available under [releases](https://github.com/WebberZone/freemkit/releases).
