# Public route access review (`_access: TRUE`)

**Date:** 2026-06-22  
**Scope:** All `web/modules/custom/myeventlane_*/*.routing.yml` routes with `_access: 'TRUE'` or `_access: TRUE`  
**Method:** Route YAML review + controller/form inspection for internal access controls

## Summary

| Category | Count | Verdict |
|----------|-------|---------|
| Stripe webhooks | 3 | Safe — Stripe signature verification |
| Postmark webhooks | 2 | Safe — shared webhook secret header |
| OAuth / SSO auth | 8 | Safe — client credentials, tokens, OAuth state |
| Stripe Connect return URLs | 3 | Safe — authenticated session + account validation in controller |
| Public content / discovery | 14 | Safe — anonymous-safe published or cached content only |
| Redirect / shell entrypoints | 4 | Safe — redirects only, no sensitive payloads |
| Analytics / feedback beacons | 2 | Acceptable — CSRF or flood in controller; monitor abuse |
| Infrastructure | 1 | Intentional — health probe returns status only |
| Error pages | 2 | Safe — static themed markup |
| Passcode gate | 1 | Safe — passcode validation + Form API CSRF |
| Test fixtures | 2 | Test-only — not production routes |

**Route access changes:** None required. Every production route has a documented reason and an internal control appropriate to its purpose.

**Controller changes:** None. No missing signature, token, or state checks were confirmed.

## Protection mechanisms reference

| Mechanism | Where used |
|-----------|------------|
| Stripe signature (`Stripe-Signature`) | Payout webhook, Pro subscription webhook |
| Webhook shared secret (`X-Webhook-Secret`) | Postmark delivery, Postmark bounce |
| OAuth client_id + client_secret | `/auth/token`, `/auth/refresh`, `/auth/revoke` |
| One-time authorization code | `/auth/token`, vendor SSO callback |
| Bearer access token | `/auth/me`, `/auth/revoke` (revoke-all) |
| OAuth state (HMAC or session) | Vendor SSO callback |
| Authenticated session (controller) | Stripe Connect callbacks, create-event gateway |
| CSRF token | Help article feedback (`X-CSRF-Token`), passcode form (Form API) |
| Flood / rate limits | Help Assistant ask, help article feedback |
| Anonymous-safe content only | Help Centre listings, search, trust page, Boost PDF |
| Redirect only (no data) | mel_continue, login alias, shell entrypoints |

---

## myeventlane_admin_dashboard

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_admin_dashboard.stripe_payout_webhook` | `POST /stripe/webhook/payout` | Stripe must POST without Drupal session | `StripeWebhookController::handle()` — `Webhook::constructEvent()` with configured secret; rejects before dispatch | **Safe** |

---

## myeventlane_messaging

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_messaging.postmark_webhook_delivery` | `POST /webhooks/postmark/delivery` | Postmark delivery callbacks | `PostmarkWebhookController::validateWebhook()` — `X-Webhook-Secret` vs config; 401 if missing/wrong | **Safe** |
| `myeventlane_messaging.postmark_webhook_bounce` | `POST /webhooks/postmark/bounce` | Postmark bounce/complaint callbacks | Same secret validation as delivery | **Safe** |

---

## myeventlane_auth

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_auth.mel_continue` | `GET /mel/auth/continue` | Post-login handoff must work before session | Redirect only; anonymous → `user.login` with destination; authenticated → `PostAuthRedirectResolver` | **Safe** |
| `myeventlane_auth.mel_email_entry` | `GET /mel/auth/email` | Future email-first entry placeholder | Static placeholder linking to login; no account data | **Safe** |
| `myeventlane_auth.login` | `GET /auth/login` | OAuth authorization must start without session | Validates `client_id`, `redirect_uri`, `state`; codes issued on `authorize` (requires login) | **Safe** |
| `myeventlane_auth.token` | `POST /auth/token` | Standard OAuth token endpoint | `TokenGrantService::grantFromAuthorizationCode()` — client secret + one-time code + redirect_uri (+ PKCE verifier) | **Safe** |
| `myeventlane_auth.refresh` | `POST /auth/refresh` | OAuth refresh for SPA / vendor clients | Client secret + refresh token (body or HTTP-only cookie) | **Safe** |
| `myeventlane_auth.me` | `GET /auth/me` | OAuth userinfo for clients with access tokens | Bearer token required; 401 without valid token; returns only sub/name/mail/roles | **Safe** |
| `myeventlane_auth.revoke` | `POST /auth/revoke` | OAuth token revocation | Client credentials + refresh token, or Bearer for revoke-all | **Safe** |
| `myeventlane_auth.vendor_sso_callback` | `GET /vendor/sso/callback` | Auth-host redirect target on vendor host | `VendorSsoStateSigner` HMAC state or legacy session state + one-time code exchange; redirect_uri allowlist | **Safe** |

---

## myeventlane_pro

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_pro.subscription_webhook` | `POST /stripe/webhook/subscription` | Stripe subscription audit events | `ProSubscriptionWebhookController::handle()` — Stripe signature; audit log only | **Safe** |

---

## myeventlane_vendor

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_vendor.login_alias` | `GET /vendor/login` | Avoid `/vendor/{slug}` 404 for slug `login` | Redirect to `user.login` with normalised destination only | **Safe** |
| `myeventlane_vendor.stripe_callback` | `GET /stripe/callback` | Stripe AccountLink `return_url` | `StripeConnectController::callback()` — requires login; validates `account_id` against vendor store | **Safe** |
| `myeventlane_vendor.stripe_callback_legacy` | `GET /stripe/connect/callback` | Legacy in-flight AccountLinks | Same as `stripe_callback` | **Safe** |
| `myeventlane_vendor.stripe_onboard_return` | `GET /vendor/onboard/stripe-return` | Stripe Dashboard registered return URL | Same as `stripe_callback` | **Safe** |
| `myeventlane_vendor.create_event_gateway` | `GET /create-event` | Marketing / onboarding entry for event creation | Redirects anonymous to login; enforces vendor onboarding in controller | **Safe** |
| `myeventlane_vendor.shell.dashboard` | `GET /dashboard` | Vendor host console shell | `entrypointRedirect()` — permission-based redirect, no data | **Safe** |
| `myeventlane_vendor.shell.vendor_root` | `GET /vendor` | Vendor host console root | Same as shell dashboard | **Safe** |

---

## myeventlane_help_centre

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_help_centre.public_index` | `GET /help/index` | Public help hub index | Views with published help content only | **Safe** |
| `myeventlane_help_centre.attendees_index` | `GET /help/attendees` | Attendee help listing | Published content via Views | **Safe** |
| `myeventlane_help_centre.organisers_index` | `GET /help/organisers` | Organiser help listing | Published content via Views | **Safe** |
| `myeventlane_help_centre.vendors_index` | `GET /help/vendors` | Organiser help hub | Controller redirects anonymous users to login before vendor-scoped view | **Safe** |
| `myeventlane_help_centre.policies_index` | `GET /help/policies` | Policies listing | Published content via Views | **Safe** |
| `myeventlane_help_centre.category_index` | `GET /help/category/{category}` | Category-scoped articles | Views + taxonomy term parameter | **Safe** |
| `myeventlane_help_centre.search` | `GET /help/search` | Help search | Published articles/FAQs via Views | **Safe** |
| `myeventlane_help_centre.article_feedback` | `POST /help/article/{node}/feedback` | “Was this helpful?” AJAX | CSRF (`myeventlane_help_feedback`), flood, `help_article` bundle check | **Safe** |
| `myeventlane_help.analytics_click` | `POST /mel-help/click` | Help panel click beacon | Accepts click key only; no CSRF (low sensitivity). **Monitor** for flood abuse | **Acceptable** |

---

## myeventlane_help_assistant

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_help_assistant.ask` | `POST /help/assistant` | JSON help retrieval for embedded assistant | Flood limits (IP burst + per-session hourly); public help corpus only. No CSRF — read-only with rate limits | **Acceptable** |

---

## myeventlane_search

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `mel_search.view` | `GET /search` | Public event discovery | Search API — upcoming published events | **Safe** |
| `mel_search.autocomplete` | `GET /search/autocomplete` | Search autocomplete JSON | Same indexes; titles/venues/categories only | **Safe** |

---

## myeventlane_public_trust

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_public_trust.page` | `GET /trust` | Trust & transparency page | Cached aggregate metrics only; no PII | **Safe** |

---

## myeventlane_boost

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_boost.performance_guide_pdf` | `GET /boost-performance-guide.pdf` | Public marketing PDF | Static generated PDF; no vendor financial data | **Safe** |

---

## myeventlane_event

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_event.passcode_gate` | `GET/POST /event/{node}/passcode` | Buyers must enter passcode before hidden events | `EventPasscodeAccess`; 404 if not passcode-protected; Form API CSRF on POST | **Safe** |

---

## myeventlane_core

| Route name | Path | Why public | Internal protection | Verdict |
|------------|------|------------|---------------------|---------|
| `myeventlane_core.error_403` | `GET /mel/403` | Themed 403 page | Static error theme | **Safe** |
| `myeventlane_core.error_404` | `GET /mel/404` | Themed 404 page | Static error theme | **Safe** |
| `myeventlane_core.health` | `GET /health` | Load balancer / uptime probe | Returns `ok` or error status string only; `_maintenance_access: TRUE` | **Intentional** |

---

## Test fixtures (non-production)

| Route name | Module | Notes |
|------------|--------|-------|
| `commerce_checkout.review` | `mel_kernel_route_fixtures` | Kernel test route fixture; 404 controller |
| `commerce_checkout.order_information` | `mel_kernel_route_fixtures` | Kernel test route fixture; 404 controller |

---

## Routes excluded from this review

These use `_myeventlane_pro_access: 'TRUE'` (Pro subscription gate), not Drupal `_access: TRUE`:

- `myeventlane_analytics` (5 routes)
- `myeventlane_reporting` (12 routes)
- `myeventlane_pro.branding`
- `myeventlane_escalations_analytics.vendor_dashboard`
- `myeventlane_vendor.console.event_analytics`

---

## Residual risk and optional follow-ups

1. **Help analytics click** (`/mel-help/click`) — No CSRF or flood at route or controller layer. Low data sensitivity (click keys only). Consider flood registration if beacon abuse appears in logs.
2. **Help Assistant ask** (`POST /help/assistant`) — Flood-protected but no CSRF. Endpoint is read-only against public help content; monitor LLM cost if abuse spikes.
3. **Health endpoint** (`/health`) — Public infrastructure probe by design. Restrict at edge (IP allowlist) in production if not already done.
4. **Stripe Connect callbacks** — Route layer is open; controller correctly requires authentication. Optional hardening: add `_user_is_logged_in: TRUE` to route requirements for clearer access semantics (not required for safety).

---

## YAML documentation

Inline comments were added above each `_access: 'TRUE'` route in the affected `*.routing.yml` files explaining why public access is required and which controller/form enforces protection.

## Validation commands

```bash
# Drupal 11 uses core:route (router:debug is not available in this project's Drush).
ddev drush core:route | grep myeventlane
grep -R "_access:.*TRUE\|_access:.*'TRUE'" web/modules/custom/myeventlane_*/*.routing.yml
ddev drush cr
```
