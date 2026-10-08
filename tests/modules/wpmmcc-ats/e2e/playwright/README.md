# Cross-System Playwright Lanes

This directory is the Playwright home for:

- the official cross-system gate
- support browser lanes
- audit browser lanes

Suites:

- `official-gate/` - official three-system gate only
- `journey-three-system/` - real user-operation journeys across website, WP, and client
- `support-plugin-webui/` - WP/plugin-side support smoke and route-access flows
- `support-client-server/` - client support flows against live client/server runtime
- `support-client-local-ui/` - client local runtime page-flow regressions
- `support-client-mock/` - client support flows against local mock server + mock translate API
- `support-web-control-plane/` - website control-plane support flows
- `audit-web-control-plane/` - website control-plane audit / migration / repair flows

Important scope note:

- Current owned WP save entries reuse `../run-owned-wp-output.sh`:
  `npm run test:support:plugin-admin-ops` (17),
  `npm run test:support:plugin-content-admin` (20), and
  `npm run test:support:plugin-content-matrix` (21). Run them from
  `tests/modules/wpmmcc-ats/e2e/playwright`, never against a shared first row.
- Owned21 requires core post/page and pinned native Woo product. Each case
  saves title/content/excerpt through UI POST and edit-mode PUT, checks separate
  GET/WP reads and two reloads, and rejects a wrong target. It is not the full
  content-plugin/native-field matrix. Core's one unsaved admin auto-draft is
  counted separately and must not grow; the second site must keep zero
  post/page/product objects, including auto-drafts.
- Owned20/21 use existing local pinned Woo/ACF archives and a compatible local
  immutable WP image, no download or shared-WP upgrade. Missing pins fail closed.
  Their legacy and owned specs are excluded from shared comprehensive collection.
  No Client, mock, worker, timer, or real provider is started by these entries.
  Reports require private, nonempty output and exact owned cleanup.
- `support-client-mock/` and `support-client-server/` are browser/UI support lanes
- they are not the canonical worker-task component-template baselines anymore
- current component-template baselines are executed from `dev-tool/e2e/run-component-template-*.sh`
- those wrappers call:
  - control-plane sync/smoke scripts
  - `client/tests/component_template_mock_task_flow.rs`
  - `client/tests/wp_core_source_component_flow.rs`

Canonical commands:

```bash
cd dev-tool/e2e/playwright && npm test
cd dev-tool/e2e/playwright && npm run test:gate
cd dev-tool/e2e/playwright && npm run test:gate:plugin-side
cd dev-tool/e2e/playwright && npm run test:gate:client-execution
cd dev-tool/e2e/playwright && npm run test:gate:client-verification
cd dev-tool/e2e/playwright && npm run test:support:plugin-webui
cd dev-tool/e2e/playwright && npm run test:support:client-server
cd dev-tool/e2e/playwright && npm run test:support:client-local-ui
cd dev-tool/e2e/playwright && npm run test:support:client-mock
cd dev-tool/e2e/playwright && npm run test:support:web-control-plane
cd dev-tool/e2e/playwright && npm run test:journey:three-system
cd dev-tool/e2e/playwright && npm run test:audit:web-control-plane
```

Current `support-plugin-webui/` coverage:

1. `plugin-usage-smoke.support.spec.ts` - open representative plugin admin pages and do a light interaction smoke
2. `gated-url-access.support.spec.ts` - verify gated WP/frontend URLs stay reachable or expectedly blocked under admin session
3. `plugin-install-activation.support.spec.ts` - verify representative plugins are listed in WP, enabled, and their core menu pages are reachable

The current plugin-install/activation lane covers:

1. `wptsall`
2. `woocommerce`
3. `bbpress`
4. `tutor`
5. `learnpress`
6. `the-events-calendar`
7. `wp-job-manager`
8. `elementor`
9. `wordpress-seo`
10. `advanced-custom-fields`

For the live `support-client-server` lane, the npm entry now goes through:

```bash
bash dev-tool/e2e/run-playwright-support-client-server.sh [playwright args...]
```

For the live `journey-three-system` lane, the npm entry now goes through:

```bash
bash dev-tool/e2e/run-playwright-journey-three-system.sh [playwright args...]
```

That wrapper currently:

1. clears the Client runtime `session_cache`
2. removes the cached `session-token.enc` when present
3. kills the current `wptsall-server` process so systemd restarts it with a clean in-memory rate-limit state
4. waits for `http://127.0.0.1:8787/health`
5. restarts `wptsall-client-webui.service`
6. waits for `http://127.0.0.1:8977/api/status`
7. preflights public-auth shared dependencies: server health, client status, and Postgres token access
8. writes the preflight result to `dev-tool/e2e/runtime/journey-public-auth-preflight.json`
9. launches `playwright.journey-three-system.config.ts`
10. on failure, archives Playwright report/test-results plus WP/Client/Server evidence under `dev-tool/e2e/runtime/journey-failures/<run-id>/`

Inside the journey helpers themselves:

1. `registerUser` retries once after a server recycle if signup hits a public-auth shared-env error such as rate limiting
2. `loginWebUser` retries once after a server recycle if login hits a public-auth shared-env error such as rate limiting
3. `requestWebPasswordReset` detects the public rate-limit error and restarts `wptsall-server` once before retrying
4. `loginClientViaOAuth` force-clears Client runtime `session_cache`, removes `session-token.enc`, restarts `wptsall-client-webui.service`, and retries once if the OAuth popup hits a transient auth/shared-state failure

Current journey coverage:

1. website forgot-password -> fetch reset token from Postgres -> reset password -> login with new password -> restore original password hash
2. website register -> verify email -> website login -> client OAuth login
3. WP admin login -> generate verification URL -> website domain reverify -> client sees bound domain
4. WP creates a real job bundle -> client OAuth login -> overview run-once -> tasks page shows execution result
5. client enables `review_mode` -> runs a real task -> pending_review item opens review page -> approve sync to WP
6. website user login -> create a user-owned template -> client pulls that template -> creates a local component -> binds and runs a real run-once task

Auth-token / inbox handling for this lane:

1. verification tokens and password-reset tokens are read directly from Postgres for repeatability
2. the lane does not require real SMTP delivery or external inbox polling
3. the domain reverify journey no longer assumes `demo@wptsall.dev` still uses the seeded plaintext password; it temporarily resets the account to a known strong password and restores the original hash in `finally`

The third journey currently uses the live WP admin REST endpoint
`/wp-json/wptsall/v2/site/generate-verification` after the browser has already
established the admin session, because the current WP admin menu no longer
surfaces a dedicated verification button in the active Tasks page UI.

That wrapper refreshes the live WP Client API runtime, resolves the current
`wp_client_token` / `route_secret`, syncs `client/config/domain-token-bindings.json`,
restarts the Client WebUI, and upserts the runtime binding before launching Playwright.

When called without extra args, the wrapper intentionally runs only the current
support/browser specs:

- `support-client-server/components-server-filters.support.spec.ts`
- `support-client-server/components-sync-ui.spec.ts`
- `support-client-server/components-webui-migration.support.spec.ts`
- `support-client-server/overview-preflight.support.spec.ts`

The heavier official-template validation remains opt-in:

```bash
cd dev-tool/e2e/playwright && npm run test:support:client-server:official-templates
```

Main orchestrator:

```bash
bash dev-tool/e2e/run.sh
```

Window-friendly wrappers:

```bash
bash dev-tool/e2e/run-official-plugin-preflight.sh
bash dev-tool/e2e/run-official-client-execution.sh
bash dev-tool/e2e/run-official-client-verification.sh
```

Component-template wrappers:

```bash
bash dev-tool/e2e/run-component-template-ct0-sync.sh --apply
bash dev-tool/e2e/run-component-template-ct0-smoke.sh
bash dev-tool/e2e/run-component-template-ct1.sh
bash dev-tool/e2e/run-component-template-ct2.sh
bash dev-tool/e2e/run-component-template-ct2-support-ui.sh
bash dev-tool/e2e/run-component-template-ct3.sh
bash dev-tool/e2e/run-component-template-lane.sh all-nonmutating
bash dev-tool/e2e/run-component-template-suite.sh --with-business-gate
```

`run-component-template-ct2-support-ui.sh` reuses the WP core fixture seed from `ct2`,
then runs the live Client support-browser check for:

- `GET /api/rule-component-bindings/discovery`
- the Rule Bindings page discovery panel
- one-click scope / slot fill behavior
- the Sites page manual save -> `/api/domain-tokens/test` -> discovery recovery flow

Before launching the browser lane it also:

- refreshes the WP Client API runtime gate via `dev-tool/e2e/php/ensure-client-api-runtime.php`
- refreshes `dev-tool/e2e/runtime/core-component-template-sources.json`
- syncs the live `wp_client_token` / `route_secret` into `client/config/domain-token-bindings.json`
- upserts the same binding into the running Client WebUI via `/api/domain-tokens/upsert`

Without that runtime/token refresh, the live Sites-page "测试" button and
`/api/rule-component-bindings/discovery` can both fail with stale WP auth even
when the browser lane itself is otherwise healthy.

When run through `dev-tool/e2e/run.sh`, the official gate is now split into 3 explicit groups:

- plugin-side preflight
- client execution
- client verification

The wrappers above are still intended to run in that order for the same lane.

Important boundary:

- `npm test` now means the official gate only.
- support and audit lanes are opt-in.
- `npm run test:support:client-server` is no longer a bare browser run; it is a live-runtime-aware wrappered run.
- old aliases such as `test:plugin-webui`, `test:client-webui`, and `test:web-control-plane` stay only for compatibility.

This directory is distinct from:

- `client/frontend/tests/` - archived historical reference only; active page-flow suites moved here
- `web/app/e2e/` - compatibility marker only; cases moved here
