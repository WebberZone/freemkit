---
slug: syncing-existing-subscribers
title: "Syncing existing subscribers"
products: [freemkit]
sections: ["01-freemkit-getting-started"]
tags: [freemkit, kit, subscribers, sync]
status: publish
order: 0
toc: true
---

[toc]

[FreemKit](https://webberzone.com/plugins/freemkit/) can import historical Freemius customers and re-send captured subscribers to Kit. Use **Tools → FreemKit Sync** when customers predate your webhook setup or your form and tag mappings have changed.

## Before you start

You need the `manage_options` capability and at least one configured Freemius product. For a Freemius import, enter that product's API credentials in the [settings](https://webberzone.com/support/knowledgebase/settings/). To send subscribers to Kit, connect Kit and configure a form for the selected user types.

## Choose the source and destination

| Setting | Choice | Behavior |
| --- | --- | --- |
| **Source** | **Freemius API (import historical users)** | Fetch customers from your configured Freemius products, including users who never triggered a webhook on this site. This is the default source. |
| **Source** | **Local database (re-sync captured subscribers)** | Use local subscriber records associated with recorded product events. |
| **Destination** | **Local database only (no Kit push)** | Save or update local records without sending them to Kit. This is the default destination. |
| **Destination** | **Local database + Kit (save locally and subscribe to email list)** | Attempt the configured Kit subscriptions and save successful or skipped subscription results locally. Kit or configuration errors can prevent a record from being saved. |

For a staged import, choose the local-only destination first. You can run a later sync from the local database with the Kit destination.

## Filter the customers

**Plugin** selects one configured product. Leave it at **All Plugins** to process each configured product in sequence for a Freemius import.

**User Types** offers **Free**, **Paid**, and **Trial**. **Paid** is selected by default. Freemius imports classify customers from the API data; local syncs use the user type recorded with the product event. Trial users use the free form and tag mappings.

## Choose forms and tags

For a Kit destination, **Override Kit Form IDs** and **Override Kit Tag IDs** take precedence over the configured mapping. Leave an override blank to use the selected product's free or paid mapping, then the global defaults.

At least one effective form is required for a Kit push. A missing form is reported in the result row. The local-only destination does not need a Kit form.

## Run the sync

1. Check the source, destination, product, and user types.
2. Set any form or tag overrides.
3. Click **Start Sync**.
4. Keep the page open while it fetches and processes batches.
5. Review **Sync Results**, including each row's **Action** and **Notes**.

**Cancel** stops further batch processing. It does not undo subscribers already processed, and an in-flight server request may still finish.

## Understand the results

| Action | Meaning |
| --- | --- |
| `synced` | A new local record was saved. Kit subscriptions were also attempted when that destination was selected. |
| `updated` | An existing local record was updated. Records are matched by email address. |
| `opted_out` | The record was saved locally without a Kit push because marketing consent is absent and **Respect Marketing Opt-Out** is enabled. |
| `skipped` | The user did not match the selected type, or Kit reported a cancellation, bounce, or complaint. Read **Notes** for the reason. |
| `error` | The operation failed, for example because a form was missing or an API call failed. |

When Kit reports a subscriber as `cancelled`, `bounced`, or `complained`, FreemKit records `kit_unsubscribed` locally and does not force a re-subscription. Disabling Freemius marketing opt-out enforcement does not override Kit's own subscription state. Local imports preserve a previously recorded Kit unsubscribe until Kit reports the subscriber as active.

See the [Subscribers screen](https://webberzone.com/support/knowledgebase/subscribers-screen/) to inspect the saved records and use the **Unsubscribed in Kit** filter.
