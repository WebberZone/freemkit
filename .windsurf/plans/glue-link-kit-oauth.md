# Glue Link ↔ Kit OAuth Integration Plan

This plan outlines how we will add full Kit (ConvertKit) OAuth v4 connectivity plus Freemius bridging inside the glue-link plugin while keeping changes modular and testable.

## 1. Foundations & Dependencies

- **Audit current plugin skeleton** to document option keys, settings loader, autoloader, and identify missing bootstrap files (main plugin file, service loaders). Capture in notes for later validation.
- **Decide on Kit client strategy**: either vendor the `convertkit-wordpress-libraries` package or install the official SDK via Composer, ensuring license compatibility and keeping bundle size manageable (likely composer dependency with selective autoloading).
- **Confirm Freemius SDK hooks** already available in glue-link or to be added (webhooks, customer fetch, plan data). Produce short spec of the data we need from Freemius and Kit for the bridge.

## 2. Settings & Storage Layer

- Extend `Options_API` (or add wrapper) with helper getters/setters for Kit credentials: `kit_access_token`, `kit_refresh_token`, `kit_token_expires`, `kit_account_id`, etc., plus encryption for secrets using existing helper logic.
- Define option defaults and validation rules for new settings (connection status, sync toggles, logging flag) and expose them via filters for extensibility.
- Plan schema for any custom DB tables or caches (ideally use WP transients for Kit resource caches unless scale demands tables).

## 3. Admin UX (Connect & Manage)

- Add a dedicated Glue Link → “Kit Connection” admin page/tab using existing Settings API framework.
- When no tokens exist, render OAuth explainer + “Connect to Kit” CTA. Once connected, show account metadata, sync controls, and Disconnect button.
- Implement notices for success/error states and surface last-sync timestamps/log links to aid support.

## 4. OAuth Flow Implementation

- Register a secure admin endpoint to initiate the OAuth flow: generate state payload (nonce + return URL), PKCE verifier/challenge, and redirect to `https://api.kit.com/v4/oauth/authorize` with required params.
- Handle the callback: verify nonce/state, exchange code for tokens via Kit client, persist credentials, schedule refresh cron, and redirect back with appropriate notice.
- Add graceful error handling (WP_Error propagation, log storage) and implement Disconnect action that clears credentials + cached data.

## 5. Token Lifecycle & Cron

- Schedule `gluelink_refresh_kit_token` (single event) each time new tokens are saved. Handler refreshes token via Kit API, updates stored values, reschedules itself, and logs failures.
- Add listener for API 401/invalid-token responses to trigger credential purge + admin notice.
- Ensure cron respects WP disabled-cron environments by exposing CLI command to refresh tokens manually.

## 6. Glue Logic Between Kit & Freemius

- Define the concrete sync scenarios (e.g., when a Freemius license activates, push subscriber/tag to Kit; when Kit subscriber tags change, update Freemius segments, etc.).
- Implement service classes for each direction with clear responsibilities:
  1. **FreemiusEventListener** – subscribe to Freemius hooks/webhooks and enqueue jobs.
  2. **KitApiService** – wrap API calls (subscribers, tags, purchases) using authenticated client.
  3. **SyncManager** – orchestrate transformations, dedupe, and retry policies.
- Plan background processing (WP Queue, Action Scheduler, or custom CRON) to stay within Kit rate limits (600 req/60s) and make sync resumable.

## 7. Logging, Debugging, and Telemetry

- Add optional debug logging (WP_DEBUG_LOG or custom log files) capturing request IDs, rate-limit hits, and sync outcomes.
- Provide admin UI to download logs, clear caches, and manually trigger sync/health checks.

## 8. Testing & Verification

- Unit-test new helpers (options, encryption, cron scheduling) using PHPUnit in glue-link.
- Add integration tests (mocking Kit/Freemius SDKs) for OAuth exchange, token refresh, and sync flows.
- Document manual QA checklist: OAuth happy path, error states, cron execution, Freemius-triggered sync, Kit-triggered sync, uninstall cleanup.

## 9. Documentation & Rollout

- Update README + inline docs to explain prerequisites (Kit app registration, Freemius API keys), configuration steps, and troubleshooting tips.
- Provide upgrade notes for existing glue-link users (new settings option, potential need to reconnect after upgrade).
- Prepare migration script if prior versions stored API keys differently.
