# FreemKit Documentation

FreemKit connects [Freemius](https://freemius.com/) software licensing with [Kit](https://kit.com/) (formerly ConvertKit) email marketing. When a Freemius event fires — a new install, a licence purchase, a cancellation — FreemKit subscribes or unsubscribes the customer in Kit automatically.

---

## User Guides

| Guide | Description |
|---|---|
| [Installing and Configuring](user/installing-and-configuring.md) | Install the plugin, run the setup wizard, connect Kit, and add your Freemius products |
| [Event Mapping](user/event-mapping.md) | Map Freemius events to Kit forms and tags for free and paid users |
| [Settings Reference](user/settings.md) | Every setting across the Kit, Freemius, and Subscribers tabs |
| [Subscribers Screen](user/subscribers-screen.md) | Browse, search, add, and export subscribers from the admin |

---

## Developer Reference

| Reference | Description |
|---|---|
| [Webhook Lifecycle](developer/webhook-lifecycle.md) | How a Freemius webhook is received, validated, queued, and processed |
| [Hooks Reference](developer/hooks-reference.md) | All actions and filters with signatures, defaults, and examples |
| [Database Schema](developer/database-schema.md) | Tables, columns, indexes, and relationships |
| [REST API](developer/rest-api.md) | Webhook endpoint: URL, authentication, payload format, and responses |

---

## Quick Links

- **Webhook URL (REST):** `https://yoursite.com/wp-json/freemkit/v1/webhook`
- **Webhook URL (query var):** `https://yoursite.com/?freemkit_webhook`
- **Settings page:** WordPress Admin → Settings → FreemKit
- **Subscribers:** WordPress Admin → Users → FreemKit Subscribers
