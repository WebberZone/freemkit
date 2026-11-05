---
slug: webhook-lifecycle
title: "Webhook Lifecycle"
products: [freemkit]
sections: ["03-freemkit-developer-docs"]
tags: [developer, freemkit, webhook]
status: publish
order: 0
toc: true
---

[toc]

This document describes how [FreemKit](https://webberzone.com/plugins/freemkit/) receives, validates, queues, and processes a Freemius webhook event from end to end.

---

## Overview

```text
Freemius → HTTP POST → Endpoint
                          │
                    Signature check
                    Timestamp check
                    Replay/dedup check
                          │
                    Queue (transient)
                    202 Accepted ──→ Freemius
                          │
                    WP-Cron fires
                          │
                    Event routing (in order)
                          │
          ┌───────────────┼──────────────────┬──────────────┐
     Unsubscribe        Opt-in           Name change     Free / Paid
     event types        event            event           event types
          │               │                 │                │
    Kit unsubscribe  Clear opt-out     Kit name update  Kit subscribe
    Mark opted-out   locally           locally          Local DB upsert
    locally                                             Audit log entry
```

---

## 1. Endpoint Registration

FreemKit registers one of two endpoints depending on the **Webhook Endpoint Type** setting.

### REST API (default)

Registered via `register_rest_route()`:

- **Route:** `freemkit/v1/webhook`
- **Full URL:** `https://yoursite.com/wp-json/freemkit/v1/webhook`
- **Method:** POST
- **Permission callback:** Validates the Freemius HMAC-SHA256 signature before the handler runs.

### Query Variable fallback

A `parse_request` hook intercepts requests containing the `freemkit_webhook` query variable and routes them to the same handler.

---

## 2. Signature Validation

Every incoming request must carry an `x-signature` header containing a Freemius-generated HMAC-SHA256 signature.

FreemKit computes the expected signature by hashing the raw request body with the plugin's **Secret Key** using `hash_hmac('sha256', $body, $secret_key)` (returning a hex string) and comparing it against the header value using `hash_equals()` to prevent timing attacks.

A missing or unusable secret key returns `missing_secret_key` before signature comparison. This includes a secret that cannot be decrypted after a salt change. Re-enter the configured secret to restore delivery. An incorrect signature returns `invalid_signature`. The REST callback returns a `WP_Error` without assigning an HTTP status; the query-variable endpoint returns HTTP `400`. No further processing occurs.

---

## 3. Timestamp and Replay Checks

After signature validation, FreemKit applies two additional guards.

### Timestamp check

If the webhook payload (or headers) contains a timestamp, FreemKit checks that its difference from the current time is within the allowed window (default: 15 minutes), including future timestamps. This prevents delayed or replayed requests.

The maximum age is configurable via the `freemkit_webhook_max_age` filter (value in seconds).

If no timestamp is present, FreemKit accepts the request by default. To instead reject requests that have no timestamp:

```php
add_filter( 'freemkit_webhook_require_timestamp', '__return_true' );
```

### Replay prevention

FreemKit marks an event as seen before queuing it, using a transient keyed as `freemkit_webhook_seen_{hash}`. The default lifetime is 24 hours. A duplicate returns `status: ignored`; the REST endpoint responds with HTTP `200`, while the query-variable endpoint responds with HTTP `202`.

The deduplication window is configurable:

```php
add_filter( 'freemkit_webhook_replay_ttl', function( $ttl ) {
    return DAY_IN_SECONDS * 2; // extend to 48 hours
} );
```

---

## 4. Queuing

FreemKit normally queues webhooks for processing. After validation, the event payload is stored in a transient and a WP-Cron event is scheduled:

```text
Hook:    freemkit_process_webhook_event
Args:    [ $event_key ]
```

For an ordinarily queued event, the REST endpoint returns HTTP `202` with `status: queued`. If scheduling fails or `DISABLE_WP_CRON` is enabled, FreemKit processes the event in the same request and returns HTTP `200` with `status: processed`. The query-variable endpoint uses HTTP `202` for either successful result.

---

## 5. Async Processing and Retry

When the cron event fires, `process_queued_webhook()` retrieves the payload from the transient and calls the main processing logic.

### Retry behavior

If processing fails (for example, due to a Kit API error), FreemKit reschedules the cron event with a linear delay capped at 5 minutes:

| Attempt | Delay |
|---|---|
| 1 (initial) | Scheduled after 1 second, or processed synchronously by the fallback |
| 2 | 1 minute after the first failure |
| 3 | 2 minutes after the second failure |
| 4 | 3 minutes after the third failure |

The default `freemkit_webhook_max_retries` value of `3` permits three retries after the initial attempt. After the fourth failure, FreemKit deletes the queued payload and seen marker. It writes a diagnostic to the PHP error log when `WP_DEBUG_LOG` is enabled.

The retry limit is configurable:

```php
add_filter( 'freemkit_webhook_max_retries', function( $max ) {
    return 5;
} );
```

---

## 6. Event Routing

Once the payload is available, FreemKit extracts the event `type` field and the user data (email, first name, last name, Freemius user ID) and determines how to handle it.

### Routing logic

Branches are evaluated in order. The first match wins.

| Priority | Condition | Handler |
|---|---|---|
| 1 | Event type matches unsubscribe event types (default: `user.marketing.opted_out`) | Mark opted-out locally, unsubscribe from Kit |
| 2 | Event type is `user.marketing.opted_in` | Clear marketing opt-out locally and attempt to restore previous form/tag mappings without overriding Kit unsubscribe state |
| 3 | Event type is `user.name.changed` | Update name in Kit (if sync setting is enabled) |
| 4 | Event type matches free event types | Subscribe as free user |
| 5 | Event type matches paid event types | Subscribe as paid user |
| Not applicable | No match | Log as ignored, take no action |

---

## 7. Kit Operations

### Subscribing a user

For free or paid events, FreemKit calls `Kit_API::subscribe_to_form()` for each configured form ID. This call:

1. Creates or updates the subscriber in Kit.
2. Applies any configured tag IDs.
3. Sets custom fields (first name, last name, and any mapped fields).

If no per-plugin form is configured, FreemKit falls back to the global default form. If no global default is set, no Kit subscription is attempted (an audit log entry is written).

Before subscribing, FreemKit checks Kit's current state. A `cancelled`, `bounced`, or `complained` subscriber is recorded locally as `kit_unsubscribed`, with the Kit state in `kit_status`. The operation is treated as completed without re-adding that subscriber or retrying the subscription. A later Freemius marketing opt-in does not by itself reactivate a Kit subscription.

### Unsubscribing a user

`Kit_API::unsubscribe_subscriber()` is called with the subscriber's email address. This removes the subscriber from all Kit forms and sequences. Tags are not removed (Kit does not support bulk tag removal via API).

### Updating a name

`Kit_API::update_subscriber_name()` is called with the subscriber's email and new name. This updates the subscriber record in Kit without affecting their form subscriptions.

---

## 8. Local Database Update

After each successful Kit operation, FreemKit upserts a record in the local `{prefix}freemkit_subscribers` table and inserts a row in `{prefix}freemkit_subscriber_events` recording the event type, plugin ID, user type, and which form/tag IDs were used.

See [Database Schema](database-schema.md) for full column details.

---

## 9. Audit Log

A log entry is written for every significant step: webhook received, event queued, Kit subscribed, Kit error, retry scheduled, and so on. Email addresses are partially masked in log output.

The audit log is capped at 200 entries. Older entries are pruned automatically when new ones are added.

---

## Filters Quick Reference

| Filter | Default | Description |
|---|---|---|
| `freemkit_webhook_require_timestamp` | `false` | Whether to reject requests that have no timestamp |
| `freemkit_webhook_max_age` | `900` (15 min) | Max event age in seconds |
| `freemkit_webhook_replay_ttl` | `86400` (24 hr) | Dedup window in seconds |
| `freemkit_webhook_max_retries` | `3` | Maximum retries after the initial attempt |
| `freemkit_default_free_event_types` | `['user.marketing.opted_in']` | Default free trigger events |
| `freemkit_default_paid_event_types` | `['license.created']` | Default paid trigger events |

See [Hooks Reference](hooks-reference.md) for full signatures.
