---
slug: subscribers-screen
title: "Subscribers Screen"
products: [freemkit]
sections: ["01-freemkit-getting-started"]
tags: [freemkit, subscribers]
status: publish
order: 0
toc: true
---

[toc]

The Subscribers screen shows the local customer records [FreemKit](https://webberzone.com/plugins/freemkit/) has processed. Open **Users → Subscribers** to search them, edit details, or export the list. Access requires `manage_options`.

## The subscriber list

| Column | Description |
| --- | --- |
| **Email** | The subscriber's address. Click it to edit the record. |
| **First Name** | First name received from Freemius or entered manually. |
| **Last Name** | Last name received from Freemius or entered manually. |
| **Status** | The saved local status, such as `active`, `inactive`, `opted_out`, or `kit_unsubscribed`. |
| **Created** | When the local record was created. |
| **Plugins** | Names of the configured plugins associated with the subscriber's recorded events. |

Marketing consent and Kit subscription state are separate from the local status. A Freemius marketing opt-in does not override a cancellation, bounce, or complaint recorded by Kit.

## Searching, filtering, and pagination

Search by email, first name, or last name. The status filters above the table are **All**, **Active**, **Inactive**, and **Unsubscribed in Kit**. **All** also includes other saved statuses, such as `opted_out`.

The **Unsubscribed in Kit** filter selects local records with status `kit_unsubscribed`. Webhooks and sync operations use this status when Kit reports a subscriber as `cancelled`, `bounced`, or `complained`. These subscribers are not automatically re-added to Kit.

The list shows 20 subscribers per page by default. Open **Screen Options**, change **Subscribers**, and apply the change to save your preferred page size.

## Adding a subscriber manually

Click **Add New**. Enter an **Email** address and optional **First Name**, **Last Name**, and **Notes**. **Status** offers **Active** and **Inactive**. **Marketing** records whether the subscriber has opted in to marketing emails.

In **Plugins**, add rows for the relevant Freemius products and select the **User Type**. **Kit Forms** and **Kit Tags** override that product's mapping. Leave them blank to use the product mapping, then the global defaults.

Click **Add Subscriber** to save locally. **Add & Sync to Kit** also attempts the configured Kit subscriptions when **Marketing** is enabled. If the email already exists, the form updates that local record instead of creating a duplicate.

## Editing a subscriber

Click the subscriber's email address. **Freemius User ID**, **Freemius Created**, **Email Verified**, and **Email Status** show imported information as read-only fields.

Use **Update Subscriber** to save changes without requesting a Kit subscription. **Update & Sync to Kit** also attempts the subscriptions configured in the plugin rows. Kit cancellations, bounces, and complaints are not overridden by a subscription attempt.

Clearing **Marketing** attempts to unsubscribe the address from Kit when Kit is connected, even when you use the ordinary save button. Saving also replaces the subscriber's stored event rows with the plugin rows in the form.

## Deleting and exporting

Use the row's **Delete** action, the edit form's **Delete Subscriber** button, or the **Delete** bulk action to remove local records. If **Unsubscribe from Kit on Delete** is enabled, deletion also attempts a Kit unsubscribe.

**Export CSV** is a separate button above the table. It exports up to 99,999 local records in ID order. It does not use the selected checkboxes, search, or status filter. The CSV includes identity, status, marketing consent, Freemius details, verification information, and timestamps.

## Syncing historical subscribers

Use [Syncing existing subscribers](https://webberzone.com/support/knowledgebase/syncing-existing-subscribers/) to import historical Freemius customers or re-send captured subscribers to Kit. This is separate from editing a single local record.
