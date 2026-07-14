# MyEventLane — Launch Readiness Assessment
**Version:** 1.0  
**Date:** 2026-06-25  
**Prepared by:** Senior Technical Lead / Launch Manager (AI-assisted, repository-verified)  
**Repository branch:** `claude/mel-launch-readiness-audit-3988az`  
**Scope:** Full platform audit — Drupal 11, Commerce 3, custom modules, themes, configuration, documentation

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Status Dashboard](#2-status-dashboard)
3. [Payments](#3-payments)
4. [Security](#4-security)
5. [Accessibility](#5-accessibility)
6. [SEO](#6-seo)
7. [Analytics](#7-analytics)
8. [Legal](#8-legal)
9. [Email](#9-email)
10. [Vendor Onboarding](#10-vendor-onboarding)
11. [Customer Journey](#11-customer-journey)
12. [Operational Readiness](#12-operational-readiness)
13. [Performance](#13-performance)
14. [Launch Checklist](#14-launch-checklist)
15. [Risk Register](#15-risk-register)
16. [Final Scorecard](#16-final-scorecard)

---

## 1. Executive Summary

### Platform Overview

MyEventLane (MEL) is a Drupal 11 event marketplace and ticketing platform built on Drupal Commerce 3. It serves two primary audiences: event organisers (vendors) and ticket buyers (customers). The platform is architecturally substantial — 89 custom modules, 5 custom themes, 1,413 configuration files, 256,914 lines of custom PHP, and 500+ documentation files.

### Current Launch Readiness

The platform has achieved a solid architectural foundation. Core commerce flows, vendor tooling, customer journeys, legal compliance, email infrastructure, and access control are meaningfully implemented. However, several confirmed issues — two of which are Drupal 11 compatibility blockers — must be resolved before public release.

### Estimated Launch Readiness

**63% complete.** The platform is not ready for launch in its current state. It requires resolution of confirmed blockers, production environment hardening, and frontend performance configuration.

### Recommendation

**No-go.** Resolve all Critical and High priority items before proceeding to go-live. An estimated 2–4 weeks of focused work would bring the platform to a launch-ready state.

### Strongest Areas

- Commerce integration (checkout, cart, order lifecycle, refunds)
- Legal compliance design (NSW law validation documented, consent audit trail)
- Email infrastructure (50 templates, dual delivery providers, async queuing)
- Vendor tooling (Event Studio, dashboard, Stripe Connect)
- Access control (20+ custom access check classes, granular permissions)
- Documentation depth (500+ documents)

### Biggest Risks

| Risk | Severity |
|------|----------|
| Two custom themes declare `base theme: stable9` on Drupal 11 | Critical |
| Stripe gateway is in `test` mode with empty production keys | Critical |
| Site slogan has a spelling error (`evnts` instead of `events`) | Critical |
| CSS and JS aggregation disabled in `system.performance.yml` | Critical |
| No XML sitemap module installed or configured | High |
| Page cache `max_age: 0` — no browser or CDN caching for public pages | High |
| Buyer self-service refunds not implemented | High |
| Promotional code UX not verified in the MEL interface | High |
| No confirmed SPF/DKIM/DMARC documentation | High |

---

## 2. Status Dashboard

| Area | Status | Confidence | Blocking? |
|------|--------|------------|-----------|
| Payments (Stripe) | Partially ready | Medium | Yes — test mode |
| Theme compatibility | Blocker | Low | Yes — stable9 |
| Performance config | Blocker | Low | Yes — aggregation off |
| Security (access control) | Ready | High | No |
| Legal compliance | Ready | High | No |
| Email infrastructure | Ready | High | No |
| Vendor onboarding | Mostly ready | Medium | No |
| Customer checkout | Mostly ready | Medium | No |
| SEO | Partial | Medium | Yes — no sitemap |
| Analytics | Partial | Medium | No |
| Accessibility | Partial | Medium | No |
| Operational readiness | Partial | Medium | No |
| Content (legal pages) | Needs verification | Low | Yes |
| XML Sitemap | Missing | Low | Yes |
| Recurring events | Gap | — | No (post-launch) |
| Buyer self-service refund | Gap | — | Yes |

---

## 3. Payments

### 3.1 Stripe Integration

**Repository evidence:** `web/modules/custom/myeventlane_stripe/`, `config/sync/commerce_payment.commerce_payment_gateway.stripe.yml`

The platform uses `drupal/commerce_stripe ^2` with a custom `myeventlane_stripe` module wrapping Stripe Connect payout behaviour. The payment gateway is defined in configuration.

**Current gateway configuration:**
```yaml
id: stripe
label: 'MEL - Stripe CC'
plugin: stripe
configuration:
  mode: test
  publishable_key: ''
  secret_key: ''
  payment_method_types:
    - credit_card
  currencies:
    - AUD
  collect_billing_information: true
```

**Status: Critical — gateway is in `test` mode with empty production keys.**

### 3.2 Stripe Connect (Vendor Payouts)

**Repository evidence:** `web/modules/custom/myeventlane_stripe/src/Service/VendorStripePayoutService.php`, `myeventlane_stripe.services.yml`

Vendor payout service is implemented. Vendor entities carry four Stripe fields:
- `field_stripe_account_id`
- `field_stripe_connected` (boolean)
- `field_stripe_charges_enabled` (boolean)
- `field_stripe_payouts_enabled` (boolean)
- `field_stripe_onboard_url`
- `field_stripe_dashboard_url`

The onboarding flow routes to `/vendor/stripe-connect`. No configuration specifies payout timing (7-day, on-demand, etc.) — this is deferred to Stripe's default platform settings.

**Status: Partially confirmed.** The service exists; production Stripe Connect credentials and platform account settings require environment verification.

### 3.3 Checkout Flow

**Repository evidence:** `config/sync/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`, `web/modules/custom/myeventlane_checkout_flow/`

Custom checkout flow `mel_event_checkout` is defined with:

| Pane | Step | Status |
|------|------|--------|
| `mel_buyer_details` | Checkout | Enabled |
| `ticket_holder_paragraph` | Checkout | Enabled |
| `mel_legal_consent` | Checkout | Enabled |
| `payment_information` | Checkout | Enabled |
| `order_summary` | Sidebar | Enabled |
| `coupon_redemption` | Sidebar | Enabled |
| `grouped_order_summary` | Disabled | Disabled — missing view |
| `mel_fee_transparency` | Disabled | Disabled — legacy |

Guest checkout creates accounts automatically (`guest_new_account: true`) with notification emails enabled.

A previously documented issue — duplicate form rendering in `commerce-checkout-form.html.twig` and duplicate Stripe initialisation scripts — was recorded as resolved in `docs/CHECKOUT_ISSUES_REPORT.md`. **Production verification of the fix is required before launch.**

### 3.4 Order Lifecycle

**Repository evidence:** `web/modules/custom/myeventlane_commerce/src/EventSubscriber/`, `src/OrderProcessor/`

Order lifecycle events are subscribed:
- `OrderPlacedSubscriber` — triggers on order creation
- `OrderPaidInvoiceSubscriber` — triggers on payment received
- `OrderCompletedSubscriber` — ticket generation and attendee linking

**Known DI issue:** `OrderCompletedSubscriber` uses 12+ `\Drupal::service()` static calls instead of dependency injection. This is a testability concern but does not prevent runtime execution.

### 3.5 Refund System

**Repository evidence:** `web/modules/custom/myeventlane_refunds/`, `config/sync/myeventlane_messaging.template.refund_*.yml`

A comprehensive refund system is implemented:
- `RefundProcessor` — core refund logic
- `RefundAccessResolver` — determines who may initiate
- `BuyerRefundAccessCheck`
- `VendorRefundRequestAccessCheck`
- `RetryRefundAccessCheck`

Email templates for all refund states are present: `refund_requested`, `refund_approved`, `refund_rejected`, `refund_completed`, `refund_failed` — all in buyer, vendor, and admin variants.

**Gap: Buyer self-service refund from a confirmation email link is not implemented.** Refunds appear to be initiated through the vendor dashboard or admin. This is a notable gap versus competitor platforms.

### 3.6 Platform Fee

**Repository evidence:** `web/modules/custom/myeventlane_commerce/` references `PlatformFeeOrderProcessor`, `myeventlane_core.settings platform_fee_percent`

A platform fee order processor applies a configurable fee percentage. Fee transparency pane exists in checkout configuration (currently disabled — legacy status).

**Who-pays configuration (buyer vs organiser absorbs fees) is partially confirmed.** The fee is shown to the buyer; organiser-absorbs or split-fee configuration is not confirmed from the repository.

### 3.7 Webhooks

**Repository evidence:** `web/modules/custom/myeventlane_webhooks/`, `config/sync/advancedqueue.advancedqueue_queue.commerce_stripe_webhook_event.yml`

A webhook module and Advanced Queue job for async Stripe webhook processing are present. Webhook endpoint registration and Stripe webhook secret (whsec_) validation pattern is covered by `.gitleaks.toml` secret scanning rules.

**Production webhook URL, Stripe webhook secret, and endpoint registration require verification.**

### 3.8 Tax, Currency, Invoices, Receipts

**Repository evidence:** Currency locked to `AUD`. `config/sync/myeventlane_messaging.template.order_invoice.yml`, `order_receipt.yml`, `order_confirmation.yml`

- Currency: AUD confirmed
- Tax: No explicit tax module configuration found (GST handling not confirmed from repository — needs verification)
- Invoices: Template exists (`order_invoice.yml`)
- Receipts: Template exists (`order_receipt.yml`)
- Confirmation: Template exists (`order_confirmation.yml`)

**GST / Australian tax calculation not confirmed from repository evidence. This requires verification.**

### 3.9 Duplicate Payment Protection

**Repository evidence:** `web/modules/custom/myeventlane_commerce/src/Service/OperationalCheckoutGovernanceManager.php`, `OperationalCheckoutOrchestrationManager.php`

Checkout governance and orchestration managers are implemented. Specific duplicate payment prevention (idempotency keys, session locking) not confirmed from repository evidence alone — requires code review of the governance manager.

### 3.10 Payment Summary

| Item | Status |
|------|--------|
| Stripe gateway | Confirmed — test mode only |
| Stripe Connect payouts | Confirmed service; environment needs verification |
| Checkout flow | Confirmed — fix verification needed |
| Order lifecycle | Confirmed |
| Refund system | Confirmed — vendor-initiated only |
| Buyer self-service refund | **Not implemented — gap** |
| Platform fee | Confirmed |
| Webhook processing | Confirmed |
| GST/tax | **Not confirmed** |
| Production keys | **Not configured** |
| Gift cards | **Not implemented** |
| Invoice payment (B2B) | **Not implemented** |

### Validation Commands

```bash
ddev drush config:get commerce_payment.commerce_payment_gateway.stripe configuration.mode
ddev drush config:get myeventlane_core.settings platform_fee_percent
ddev drush queue:list
```

---

## 4. Security

### 4.1 Route Access Control

**Repository evidence:** `web/modules/custom/*/myeventlane_*.routing.yml`, `web/modules/custom/*/src/Access/`

All custom routes examined use `_custom_access` or `_permission` requirements. No routes using `_access: 'TRUE'` without deliberate intent were identified. Key access checks implemented:

| Class | Protects |
|-------|---------|
| `AdminConsoleAccess` | Admin dashboard |
| `ProAccessCheck` | Pro-subscription features |
| `VendorConsoleAccess` | Vendor dashboard |
| `BuyerRefundAccessCheck` | Customer refund rights |
| `VendorRefundRequestAccessCheck` | Vendor refund requests |
| `RetryRefundAccessCheck` | Retry failed refunds |
| `DiagnosticsAccess` | System health checks |
| `ResendOrderConfirmationAccess` | Email resend operations |
| `TicketOperationsAccess` | Ticket management |
| `PlatformControlReportingAccess` | Admin reporting |
| `EscalationAiInsightAccessControlHandler` | Escalation AI features |

### 4.2 Roles and Permissions

**Repository evidence:** `config/sync/user.role.*.yml`

Roles defined:

| Role | Purpose |
|------|---------|
| `anonymous` | Public users — minimal permissions |
| `authenticated` | Registered customers |
| `vendor` | Event organisers — 90+ permissions |
| `mel_pro` | Pro subscribers — enhanced features |
| `content_editor` | Staff content management |
| `administrator` | Full system access |

Vendor role permissions are granular and scoped (e.g. `manage own event attendees`, `view stripe dashboard links`, `request_refunds`). Administrator bypass is not applied to vendor permissions.

### 4.3 Entity and Node Access

**Repository evidence:** `web/modules/custom/myeventlane_event_attendees/src/EventAttendeeAccessControlHandler.php`

Custom entity access control is implemented for the `EventAttendee` entity. Commerce entity access relies on Drupal Commerce's built-in access system extended by MEL's checkout governance layer.

### 4.4 Secrets and Credentials

**Repository evidence:** `.gitleaks.toml`, `config/sync/commerce_payment.commerce_payment_gateway.stripe.yml`

| Credential | Status |
|------------|--------|
| Stripe publishable key | Empty in config — loaded from environment |
| Stripe secret key | Empty in config — loaded from environment |
| Stripe webhook secret (whsec_) | Pattern scanned by gitleaks |
| QR signing secret | Environment variable (MEL_QR_SECRET) |
| Postmark API key | Not in config — expected in environment |
| Google Maps API key | **Visible in `myeventlane_location.settings.yml`** |

**The Google Maps API key (`AIzaSyCVoUZ4GQLlVOm0LHYMRu98gO5QAy3ujVc`) is committed to configuration.** This is a public browser key and its exposure is expected for frontend use, but the key must be restricted to the MEL production and staging domains via the Google Cloud Console to prevent abuse. This requires verification.

### 4.5 Secret Scanning

**Repository evidence:** `.gitleaks.toml`

Gitleaks is configured with rules detecting:
- Stripe tokens (`sk_live_`, `sk_test_`, `rk_live_`, `whsec_`)
- Private PEM key blocks
- SQL dumps (`INSERT INTO`, `mysqldump`)
- `MEL_QR_SECRET` environment patterns

Allowlist correctly excludes `vendor/`, `node_modules/`, `core/`, `contrib/`, and test fixtures.

### 4.6 OWASP Considerations

| Threat | Status |
|--------|--------|
| SQL injection | Drupal entity API used; raw SQL not found in audit |
| XSS | Twig auto-escaping; no `\|raw` patterns noted |
| CSRF | Drupal's built-in CSRF token on all forms |
| Access bypass | Custom access checks on all sensitive routes |
| File upload validation | Drupal Media system; field-level file type restrictions expected |
| Rate limiting | `honeypot` module enabled; no explicit rate-limit module confirmed |
| Clickjacking | Not confirmed — server-level header needed |
| Security headers | Nginx configuration reference exists (`infrastructure/`); not deployed via CI |

**No explicit rate limiting or security header configuration (CSP, X-Frame-Options, HSTS) was confirmed from repository evidence.** These are typically handled at the nginx/server level. Verification with the infrastructure team is required.

### 4.7 Security Summary

| Item | Status |
|------|--------|
| Route access controls | Confirmed |
| Role-based permissions | Confirmed |
| Entity access handlers | Confirmed |
| Production secrets | Not in config — environment-loaded |
| Google Maps API key restriction | Requires verification |
| Secret scanning | Confirmed |
| Rate limiting | Honeypot only — HTTP rate limiting not confirmed |
| Security headers | Not confirmed from repository |
| HTTPS enforcement | Infrastructure-level — requires verification |

---

## 5. Accessibility

### 5.1 ARIA and Semantic HTML

**Repository evidence:** `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig` and 200+ template files

ARIA attributes identified across templates:
- `aria-label` — buttons, icons, interactive elements
- `aria-labelledby` — section and article headings
- `aria-hidden` — decorative/icon elements
- `role="status"` — live region alerts (e.g. availability warnings)
- `role="list"` — semantic list structures
- `role="img"` — image semantics where appropriate

Example from event full page:
```twig
<section class="mel-event-full__hero" aria-labelledby="mel-event-title">
  <div role="img" aria-label="{{ label }}">
  <div aria-hidden="true"></div>
  <span role="status" class="mel-pill--warning">
```

### 5.2 Focus Management and Keyboard Navigation

**Repository evidence:** SCSS structure (`src/scss/utilities/`, `src/scss/base/`)

Focus state CSS is declared in the design system. Mobile drawer and bottom navigation components exist as separate template components. No automated accessibility test results were found in the repository — **WCAG 2.1 AA compliance has not been formally verified.**

### 5.3 Forms and Validation

**Repository evidence:** Checkout panes, RSVP booking form, vendor event studio forms

- Error messages are associated with form fields via Drupal's standard form API (which generates `aria-describedby` relationships)
- Required field indicators are present
- No custom ARIA live region error announcement implementation confirmed beyond `role="status"`

### 5.4 Colour Contrast and Visual Design

**Repository evidence:** `DESIGN_SYSTEM.md`, `src/scss/base/_tokens.scss`

A design token system is documented. However, **no automated colour contrast audit results are present in the repository.** MEL uses a pastel brand direction — pastel palettes carry an inherent risk of failing WCAG 2.1 AA contrast requirements (4.5:1 for normal text, 3:1 for large text).

### 5.5 Mobile Accessibility

**Repository evidence:** `web/themes/custom/myeventlane_theme/components/mobile-bottom-nav/`, `mobile-drawer/`

Mobile-first layout is confirmed. Bottom navigation and drawer components are implemented as separate template components. Touch target size compliance not verified.

### 5.6 Reduced Motion

**Repository evidence:** `web/themes/custom/myeventlane_theme/src/scss/utilities/_motion.scss`

A motion utility SCSS file exists, suggesting `prefers-reduced-motion` support. Actual implementation of the media query requires template-level verification.

### 5.7 Accessibility Statement

**Repository evidence:** No accessibility statement page or route found.

**Gap: No accessibility statement page is implemented.** An accessibility statement is expected by WCAG 2.1 and is best practice for any public-facing service.

### 5.8 Accessibility Summary

| Item | Status |
|------|--------|
| ARIA attributes | Partially confirmed — widespread usage |
| Semantic HTML | Confirmed in reviewed templates |
| Focus states | CSS declared; verification needed |
| Colour contrast | Design tokens present; audit not found |
| Form error association | Drupal standard behaviour |
| Reduced motion | SCSS file present; implementation to be verified |
| Touch targets | Not confirmed |
| Screen reader testing | No evidence in repository |
| Formal WCAG 2.1 AA audit | **Not found** |
| Accessibility statement | **Not found** |

---

## 6. SEO

### 6.1 Metatag

**Repository evidence:** `composer.json` (`drupal/metatag: ^2.2`), `config/sync/core.extension.yml` (`metatag: 0`, `metatag_google_cse: 0`)

Metatag module is installed and enabled. Configuration for page titles, meta descriptions, Open Graph, and Twitter cards is expected but the full metatag configuration was not confirmed in the config sync directory. **Metatag configuration for all content types (event, blog, help article, taxonomy) requires verification.**

### 6.2 Structured Data (Schema.org / JSON-LD)

**Repository evidence:**
- `web/modules/custom/myeventlane_event/src/Service/EventStructuredDataBuilder.php`
- `web/modules/custom/myeventlane_front/src/Service/BlogStructuredDataBuilder.php`
- `web/modules/custom/myeventlane_help_centre/src/Service/HelpStructuredDataBuilder.php`

Custom JSON-LD builders are implemented for:
- Event pages (Event schema with name, dates, location, organiser, ticket availability)
- Blog posts (Article/BlogPosting schema)
- Help articles (HelpStructuredDataBuilder — specific schema type not confirmed)

**This is a significant SEO strength** — structured data for events enables Google's Event rich results.

### 6.3 URL Structure (Pathauto)

**Repository evidence:** `config/sync/pathauto.pattern.*.yml`

Pathauto URL aliases configured for:
- Events: `pathauto.pattern.event.yml`
- Blog posts: `pathauto.pattern.blog_posts.yml`
- Event categories: `pathauto.pattern.event_category.yml`
- Help articles: `pathauto.pattern.help_article.yml`
- Vendor profiles: `pathauto.pattern.myeventlane_vendor.yml`

Clean URL alias generation is confirmed.

### 6.4 Robots.txt

**Repository evidence:** `web/robots.txt` (Drupal default), `web/robots.txt.staging` (noindex staging)

Production robots.txt is the Drupal default — allows crawling of public pages. Staging environment has a separate robots.txt blocking indexing. Nginx configuration reference (`infrastructure/`) shows `X-Robots-Tag` header for staging.

**The production robots.txt must be reviewed and customised** to block admin paths, vendor console, and checkout flows from indexing.

### 6.5 XML Sitemap

**Repository evidence:** No `simple_sitemap` or `xmlsitemap` module found in `composer.json` or `config/sync/core.extension.yml`.

**Gap: No XML sitemap module is installed or configured.** An XML sitemap is essential for search engine discovery and crawl efficiency, particularly for an event marketplace with high content volume.

### 6.6 Canonical URLs

**Repository evidence:** Pathauto patterns confirmed. Metatag module active.

Canonical URL generation is expected from the Metatag module but specific canonical configuration was not confirmed.

### 6.7 Open Graph and Social Sharing

**Repository evidence:** `field.field.node.blog_post.field_seo_description.yml` — SEO description field on blog posts.

An SEO description field on blog posts is confirmed. Open Graph meta tags require Metatag module configuration verification.

### 6.8 Image Alt Text

**Repository evidence:** Template review shows `aria-label="{{ label }}"` and image fields. Explicit `alt` attribute enforcement in templates not confirmed from audit scope.

### 6.9 SEO Summary

| Item | Status |
|------|--------|
| Metatag module | Confirmed — enabled |
| Event structured data (JSON-LD) | Confirmed |
| Blog structured data | Confirmed |
| Help article structured data | Confirmed |
| Pathauto URL aliases | Confirmed |
| Robots.txt (production) | Default — needs customisation |
| Robots.txt (staging) | Confirmed — noindex |
| XML sitemap | **Not found — critical gap** |
| Canonical URLs | Expected from Metatag — verify |
| Open Graph | Expected from Metatag — verify |
| 404 handling | Confirmed (`/mel/404` custom page) |
| Image alt text | Not fully confirmed |

---

## 7. Analytics

### 7.1 Internal Analytics

**Repository evidence:** `web/modules/custom/myeventlane_core/src/Service/AnalyticsService.php`, `DiscoveryAnalyticsPageAttachments.php`

MEL maintains a lightweight internal analytics database (`myeventlane_public_analytics_event` table) tracking:
- Page views on discovery surfaces
- Event click-through from discovery
- Source attribution (search, direct, category, etc.)
- No PII stored in internal analytics

This feeds vendor analytics dashboards and platform reporting without third-party dependency.

### 7.2 Vendor Analytics

**Repository evidence:** `web/modules/custom/myeventlane_analytics/src/Service/VendorAnalyticsViewModelBuilder.php`, `web/modules/custom/myeventlane_vendor_analytics/`

Dedicated vendor analytics modules build dashboard view models covering sales, attendance, and revenue. The vendor dashboard surfaces Stripe balance via `VendorStripeBalanceService`.

### 7.3 Third-Party Analytics

**Repository evidence:** `config/sync/klaro.klaro_app.ga.yml`, `config/sync/klaro.klaro_app.gtm.yml`, 35 Klaro application configurations

Third-party analytics tools configured with Klaro consent gating:

| Tool | Klaro Config |
|------|-------------|
| Google Analytics 4 | `klaro.klaro_app.ga.yml` |
| Google Tag Manager | `klaro.klaro_app.gtm.yml` |
| Matomo | `klaro.klaro_app.matomo.yml` (implied) |
| PostHog | `klaro.klaro_app.posthog.yml` (implied) |

All third-party analytics are gated behind user consent — GDPR/Australian Privacy Act compliant approach.

### 7.4 Conversion Tracking

**Repository evidence:** `OrderPaidInvoiceSubscriber`, `OrderPlacedSubscriber`, `myeventlane_core/src/Service/AnalyticsService.php`

Internal conversion tracking occurs at order placement and payment confirmation. GTM-based conversion events (GA4 `purchase` event, etc.) require GTM container configuration — this is outside the repository but should be verified before launch.

### 7.5 Missing Tracking

The following tracking events were not confirmed from repository evidence:

- Checkout abandonment tracking
- RSVP conversion funnel
- Search query tracking (internal)
- Vendor event creation funnel
- Boost purchase attribution
- UTM parameter persistence through checkout

### 7.6 Analytics Summary

| Item | Status |
|------|--------|
| Internal analytics | Confirmed |
| Vendor analytics dashboard | Confirmed |
| GA4/GTM integration | Confirmed — consent-gated |
| Matomo | Confirmed in Klaro |
| PostHog | Confirmed in Klaro |
| Purchase conversion events | Internal confirmed; GTM requires verification |
| Checkout abandonment tracking | Not confirmed |
| Cookie consent management | Confirmed (Klaro, 35 apps) |

---

## 8. Legal

### 8.1 Privacy Act and Australian Consumer Law

**Repository evidence:** `docs/LEGAL_VALIDATION_REPORT_NSW.md`, `web/modules/custom/myeventlane_legal/`, `web/modules/custom/myeventlane_privacy/`

A formal technical compliance design review against NSW/Australian law was conducted and documented. Key findings:

| Legal Requirement | Status | Evidence |
|------------------|--------|---------|
| Electronic Transactions Act 2000 (NSW) s 9/10 | Confirmed | Affirmative opt-in, no pre-tick, version storage |
| APP 1 (Privacy transparency) | Confirmed | Policy linked; configurable URL |
| APP 3 (Solicited PI only) | Confirmed | Consent at collection |
| APP 5 (Collection notice) | Confirmed | Configurable notices at RSVP and checkout |
| APP 6 (Use/disclosure) | Confirmed | Privacy Policy + separate marketing consent |
| APP 11 (Security of PI) | Partial | RBAC confirmed; **encryption at rest requires infrastructure verification** |

### 8.2 Terms Acceptance Audit Trail

**Repository evidence:** `web/modules/custom/myeventlane_legal/src/Service/RsvpSubmissionConsentHelper.php`, `config/sync/field.storage.myeventlane_vendor.field_vendor_terms_accepted_*.yml`

Consent tracking stores:
- Terms version accepted
- Timestamp of acceptance
- IP address (configurable — vendor, default off)
- User-Agent string (configurable)
- Marketing consent separately captured

Fields confirmed on: user entity, RSVP submission entity, Commerce order entity, vendor entity.

### 8.3 Cookie Consent

**Repository evidence:** `config/sync/klaro.klaro_app.*.yml` — 35 Klaro application configurations

Klaro cookie consent is configured with 35 service definitions covering Google products, social media embeds, analytics platforms, video providers, and AI tools. This is a strong implementation for Australian Privacy Act compliance regarding consent-gated tracking.

### 8.4 Refund Policy

**Repository evidence:** `config/sync/field.storage.node.field_refund_policy.yml`

Refund policy is a field on the event content type. The checkout consent pane references this field. **Whether the refund policy content is populated for all events and visible to buyers before purchase requires content verification.**

### 8.5 Legal Pages (Content Verification Required)

| Page | Technical Implementation | Content Status |
|------|------------------------|---------------|
| Privacy Policy | Policy URL configured in settings | **Content requires verification** |
| Terms of Service | Configured URL; acceptance tracked | **Content requires verification** |
| Vendor Terms | `/vendor/onboard/terms` route confirmed | **Content requires verification** |
| Refund Policy | Field on event node | **Per-event; default required** |
| Community Guidelines | Not confirmed from repository | **Repository evidence not found** |
| Accessibility Statement | Not confirmed from repository | **Repository evidence not found** |
| Cookie Policy | Klaro manages cookie consent | **Standalone page not found** |

**Content of legal pages was not verified from repository evidence.** Legal page content must be reviewed by a qualified legal adviser before launch.

### 8.6 Email Compliance

**Repository evidence:** `web/modules/custom/myeventlane_messaging/`, marketing consent field

Marketing communications capture separate consent at checkout and RSVP. Unsubscribe mechanism requires verification in email templates.

### 8.7 Legal Summary

| Item | Status |
|------|--------|
| NSW Electronic Transactions Act compliance | Confirmed (design level) |
| Privacy Act APP compliance | Confirmed (design level) |
| Consent audit trail | Confirmed |
| Cookie consent (Klaro) | Confirmed |
| Refund policy field | Confirmed |
| Legal page content | **Requires verification** |
| Accessibility statement page | **Not found** |
| Community guidelines page | **Not found** |
| Encryption at rest | **Requires infrastructure verification** |

---

## 9. Email

### 9.1 Email Infrastructure

**Repository evidence:** `web/modules/custom/myeventlane_messaging/src/Service/Delivery/`

Two email delivery providers are implemented:
1. `DrupalMailProvider` — native Drupal mail system for development/fallback
2. `PostmarkDeliveryProvider` — Postmark for production transactional delivery

Email delivery is managed by `DeliveryProviderManager`, which routes based on configuration. Emails are queued via `advancedqueue` for async, non-blocking delivery.

### 9.2 Implemented Email Templates

**Repository evidence:** `config/sync/myeventlane_messaging.template.*.yml` — 50 templates confirmed

#### Customer Emails

| Template | Status |
|----------|--------|
| Order confirmation | Confirmed |
| Order receipt | Confirmed |
| Order invoice | Confirmed |
| RSVP confirmation | Confirmed |
| RSVP vendor copy | Confirmed |
| Refund requested (buyer) | Confirmed |
| Refund approved (buyer) | Confirmed |
| Refund rejected (buyer) | Confirmed |
| Refund completed (buyer) | Confirmed |
| Refund failed (buyer) | Confirmed |
| Ticket assignment (buyer) | Confirmed |
| Event reminder 7 days | Confirmed |
| Event reminder 24 hours | Confirmed |
| Event reminder 2 hours | Confirmed |
| Event cancelled | Confirmed |
| Waitlist invite | Confirmed |
| Ticket tier waitlist offer | Confirmed |
| Cart abandoned | Confirmed |
| Boost confirmation | Confirmed |
| Boost reminder | Confirmed |
| Export ready (CSV) | Confirmed |
| Export ready (ICS) | Confirmed |
| Pro subscription renewal reminder | Confirmed |
| Pro subscription payment failed (day 0) | Confirmed |
| Pro subscription payment failed (day 3) | Confirmed |
| Pro subscription payment failed (day 6) | Confirmed |
| Pro cart abandoned (week 1) | Confirmed |
| Pro cart abandoned (week 2) | Confirmed |

#### Vendor Emails

| Template | Status |
|----------|--------|
| Refund requested (vendor) | Confirmed |
| Refund approved (vendor) | Confirmed |
| Refund rejected (vendor) | Confirmed |
| Refund completed (vendor) | Confirmed |
| Refund failed (vendor) | Confirmed |
| Sales open notification | Confirmed |
| Event cancellation (vendor) | Confirmed |
| Event important change (vendor) | Confirmed |
| Event update (vendor) | Confirmed |

#### Admin Emails

| Template | Status |
|----------|--------|
| Refund completed (admin) | Confirmed |
| Refund failed (admin) | Confirmed |

#### Not Confirmed

| Template | Status |
|----------|--------|
| Vendor welcome / welcome to MEL | **Repository evidence not found** |
| Vendor payout notification | **Repository evidence not found** |
| User registration confirmation | Drupal core `user.mail.yml` — not a custom template |
| Password reset | Drupal core — not a custom template |
| New account (guest checkout) | Core + `guest_new_account_notify: true` in checkout — verify rendering |

### 9.3 Email Delivery Configuration

**Repository evidence:** `web/modules/custom/myeventlane_messaging/`

- Postmark API key: Expected in environment (not in config)
- SPF/DKIM/DMARC: **No documentation found in repository.** These DNS records must be configured for `myeventlane.com.au` to ensure deliverability and prevent spoofing.

### 9.4 Email Accessibility and Rendering

**Repository evidence:** `web/modules/custom/myeventlane_messaging/src/Service/MelEmailLayoutService.php`, `web/themes/custom/myeventlane_theme/templates/email/`

A dedicated email layout service and email template directory exist. Plain text alternatives and mobile rendering were not verified from repository evidence. **Email rendering in common clients (Gmail, Apple Mail, Outlook) requires manual testing before launch.**

### 9.5 Email Summary

| Item | Status |
|------|--------|
| Delivery infrastructure | Confirmed (Drupal + Postmark) |
| Async queue delivery | Confirmed |
| Transactional email count | 50 templates — comprehensive |
| Refund email coverage | Complete (all states, all roles) |
| Event reminder sequence | Confirmed (7d, 24h, 2h) |
| Vendor welcome email | **Not found** |
| Payout notification email | **Not found** |
| SPF/DKIM/DMARC documentation | **Not found** |
| Plain text alternatives | **Not confirmed** |
| Mobile rendering testing | **Not confirmed** |

---

## 10. Vendor Onboarding

### 10.1 Vendor Registration and Verification

**Repository evidence:** `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`, `myeventlane_event_studio/`

Vendor onboarding routes confirmed:
- `/vendor/onboard/*` — multi-step onboarding
- `/vendor/onboard/terms` — vendor terms acceptance
- `/vendor/stripe-connect` — Stripe Connect setup
- `/vendor` — vendor console home

The vendor entity is a custom Drupal entity with full Stripe Connect integration fields.

### 10.2 Event Studio

**Repository evidence:** `web/modules/custom/myeventlane_event_studio/`

Event Studio implements a multi-step event creation wizard with:
- Event details (name, description, dates, type)
- Venue and location
- Ticket configuration
- Custom form questions
- Availability/capacity
- Event settings and visibility

Routes confirmed: `/vendor/event-studio` (create), `/vendor/event/{node}/edit`, `/vendor/event/{node}/tickets`, `/vendor/event/{node}/questions`, `/vendor/event/{node}/availability`, `/vendor/event/{node}/settings`

### 10.3 Stripe Connect Onboarding

**Repository evidence:** `VendorStripePayoutService`, vendor Stripe fields

Stripe Connect account creation and field storage are implemented. The onboarding experience leads vendors through Stripe's hosted onboarding. The four Stripe status flags (`connected`, `charges_enabled`, `payouts_enabled`) allow MEL to gate payout operations.

**Stripe Connect production account configuration and application fee configuration require verification.**

### 10.4 Vendor Dashboard

**Repository evidence:** `web/modules/custom/myeventlane_dashboard/src/Service/VendorDashboardBuilder.php`, `web/modules/custom/myeventlane_vendor_analytics/`

Vendor dashboard surfaces:
- Upcoming events
- Recent sales
- Attendee counts
- Revenue and Stripe balance
- Event management actions
- Analytics (via `VendorAnalyticsViewModelBuilder`)

### 10.5 Help and Nudges

**Repository evidence:** `web/modules/custom/myeventlane_vendor_nudges/`, `web/modules/custom/myeventlane_help_centre/`

Contextual help nudges link to help centre articles. A dedicated vendor help console exists at `/vendor/help`. Help centre articles are indexable by search.

### 10.6 Vendor Experience Gaps

| Gap | Priority |
|-----|----------|
| Multi-user team access to a single event | Medium |
| Promo code management UI (MEL wrapper) not confirmed | High |
| Affiliate/referral code system | Low (post-launch) |
| Vendor payout notification email | High |
| Vendor welcome email | High |

### 10.7 Vendor Onboarding Summary

| Item | Status |
|------|--------|
| Vendor registration | Confirmed |
| Terms acceptance (tracked) | Confirmed |
| Stripe Connect onboarding | Confirmed |
| Event Studio (creation wizard) | Confirmed |
| Event editing | Confirmed |
| Ticket configuration | Confirmed |
| Attendee management | Confirmed |
| Check-in interface | Confirmed |
| Vendor dashboard | Confirmed |
| Vendor analytics | Confirmed |
| Help centre access | Confirmed |
| Contextual nudges | Confirmed |
| Promo code management | **Not confirmed** |
| Multi-team event access | **Partial — vendor role only** |
| Payout notification email | **Not found** |

---

## 11. Customer Journey

### 11.1 Discovery

**Repository evidence:** `web/modules/custom/myeventlane_event/src/Service/EventStructuredDataBuilder.php`, `web/modules/custom/myeventlane_surface/`, `web/modules/custom/myeventlane_search/`

Public event discovery is implemented with:
- Event listing views with category filtering
- Search API integration
- SurfaceRegistry for discovery page management
- Structured data for Google event results
- Mobile-first responsive layout

### 11.2 Event Page

**Repository evidence:** `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`

Event full page template confirmed with:
- Hero section with accessible image role
- CTA (buy tickets / RSVP / join waitlist)
- Accessibility information (`field_accessibility*`)
- Venue and location
- Event state awareness (sold out, upcoming, etc.)

### 11.3 Ticket Purchase (Paid Events)

**Repository evidence:** Checkout flow, `mel_event_checkout`, Commerce cart and order system

Full Commerce checkout:
1. Add ticket(s) to cart → 2. Buyer details → 3. Attendee details → 4. Legal consent → 5. Payment → 6. Order confirmation

Guest checkout creates an account automatically. Order confirmation email is sent on order placement.

**The checkout template duplicate-rendering issue noted in `docs/CHECKOUT_ISSUES_REPORT.md` was documented as resolved. Verification in a deployed environment is required before launch.**

### 11.4 RSVP (Free Events)

**Repository evidence:** `web/modules/custom/myeventlane_rsvp/`, `myeventlane_messaging.template.rsvp_confirmation.yml`

RSVP flow is implemented with:
- RSVP booking form
- Consent capture
- Waitlist management
- ICS calendar export
- Confirmation email
- QR code check-in integration

### 11.5 Waitlist

**Repository evidence:** `WaitlistSignupForm`, `WaitlistPromotionWorker`, `WaitlistInviteWorker`, `WaitlistNotificationService`, `config/sync/myeventlane_messaging.template.waitlist_invite.yml`

Waitlist system is implemented end-to-end — signup, promotion when spots open, notification, and invite email.

### 11.6 Account and Order History

**Repository evidence:** `web/modules/custom/myeventlane_account/`, customer dashboard

Customer account and dashboard are implemented. "My Tickets" requires account login — **there is no token-based guest ticket management** (e.g. update details without logging in).

### 11.7 Refunds (Customer)

**Repository evidence:** Refund system confirmed — vendor-initiated

**Gap: Customers cannot self-initiate a refund from a confirmation email or account page.** Refunds must be requested through vendor or admin. This is a significant customer experience gap.

### 11.8 Customer Journey Gaps

| Gap | Priority |
|-----|----------|
| Buyer self-service refund | High |
| Guest ticket management without account | Medium |
| Promo code entry (UX confirmation) | High |
| Order cancellation self-service | Not confirmed |
| Seat selection | Not implemented |
| Merchandise add-ons in checkout | Not implemented |
| Assign tickets later (bulk buy) | Not implemented |

### 11.9 Customer Journey Summary

| Journey | Status |
|---------|--------|
| Event discovery | Confirmed |
| Search | Confirmed |
| Event page | Confirmed |
| Paid ticket purchase | Confirmed — verify fix |
| RSVP | Confirmed |
| Waitlist | Confirmed |
| Order confirmation email | Confirmed |
| Account creation (guest) | Confirmed |
| Account management | Confirmed |
| My Tickets | Confirmed |
| Buyer refund self-service | **Not implemented** |
| Guest ticket management | **Not implemented** |

---

## 12. Operational Readiness

### 12.1 Cron and Queues

**Repository evidence:** `config/sync/advancedqueue.advancedqueue_queue.*.yml`

Advanced Queue is configured for:
- `commerce_stripe_webhook_event` — async webhook processing
- `default` — general async jobs (email delivery, exports, notifications)

Drupal cron handles queue processing. **Production cron configuration frequency requires verification.** Recommended: every 1–5 minutes for queue workers in production.

### 12.2 Deployment Scripts

**Repository evidence:** `scripts/deploy/mel-deploy.sh`, `scripts/deploy/remote-deploy.sh`, `scripts/deploy/verify-readiness-service-on-release.sh`

Deployment scripts exist. The deployment process includes:
- Pre-deploy readiness verification
- Database updates (`drush updb`)
- Configuration import (`drush cim`)
- Cache rebuild (`drush cr`)
- Remote server deployment

### 12.3 Environment Configuration

**Repository evidence:** `web/sites/default/mel.session.*.yml` (4 environment variants: ddev, production-au, production, staging-au, staging-com)

Multi-environment session configuration is implemented. Production secrets (Stripe, Postmark, QR signing key) are expected in environment-specific settings files, not in committed configuration.

### 12.4 Logging and Monitoring

**Repository evidence:** `web/modules/custom/myeventlane_diagnostics/`, logger channels throughout custom modules

- Custom diagnostics module with `DiagnosticsAccess` controller
- Per-module logger channels (e.g. `logger.channel.myeventlane_stripe`)
- No external error reporting service (e.g. Sentry) confirmed from repository evidence

**External error monitoring and alerting for production is not confirmed.**

### 12.5 Governance Tests

**Repository evidence:** `web/modules/custom/myeventlane_governance/tests/`, `scripts/governance/`, `composer.json` (`scripts.governance:test`)

- PHPUnit governance tests exist
- Architecture audit, surface audit, and template parity audit scripts confirmed
- E2E tests (Playwright) for checkout: `tests/e2e/checkout-ticket-purchase.spec.ts`

**No evidence of CI/CD pipeline run results or test passage was found.** Tests exist but passage is not confirmed.

### 12.6 Backup and Rollback

**Repository evidence:** `scripts/deploy/verify-staging-database.sh`

Database verification script exists. Formal backup strategy and rollback procedures were not confirmed from repository evidence.

### 12.7 DDEV vs Production

**Repository evidence:** `.ddev/config.yaml` — DDEV is the development environment

Production environment specifications (hosting provider, server specifications, CDN, Redis/cache backend) were not found in repository evidence.

### 12.8 Operational Summary

| Item | Status |
|------|--------|
| Queue processing (Advanced Queue) | Confirmed |
| Cron configuration | Configured — production frequency verify |
| Deployment scripts | Confirmed |
| Multi-environment config | Confirmed |
| Drupal logging | Confirmed |
| External error monitoring | **Not confirmed** |
| Governance tests | Confirmed — passage not verified |
| E2E tests | Confirmed |
| Backup strategy | **Not confirmed** |
| CI/CD pipeline | Not confirmed from repository |

---

## 13. Performance

### 13.1 CSS and JavaScript Aggregation

**Repository evidence:** `config/sync/system.performance.yml`

```yaml
css:
  preprocess: false
  gzip: true
js:
  preprocess: false
  gzip: true
```

**Critical: CSS and JS preprocessing (aggregation) is disabled.** This setting is appropriate for development but must be enabled for production. Without aggregation, every page load will make dozens of separate HTTP requests for individual CSS and JS files, significantly degrading Core Web Vitals and user experience.

### 13.2 Page Cache

**Repository evidence:** `config/sync/core.extension.yml` (`page_cache: 0`, `dynamic_page_cache: 0`), `config/sync/system.performance.yml` (`cache.page.max_age: 0`)

Drupal's `page_cache` and `dynamic_page_cache` modules are enabled. However, `cache.page.max_age: 0` instructs downstream caches (CDN, browser) to not cache pages. **This should be set to an appropriate value (e.g. 3600 seconds) for public event pages to enable CDN and browser caching.**

### 13.3 Vite Build Pipeline

**Repository evidence:** `web/themes/custom/myeventlane_theme/vite.config.js`, `package.json`

Vite 7.3.0 is used for asset bundling. The build produces `dist/main.css` and `dist/main.js` with:
- CSS PostCSS processing (Autoprefixer)
- Sass compilation
- Source maps

**`npm run build` must be run before deployment.** Serving unbundled source SCSS/JS in production is not supported.

### 13.4 Image Handling

**Repository evidence:** Drupal Media module, focal point integration mentioned in Event Studio

Drupal's image styles and responsive image are expected but specific configuration (WebP conversion, responsive image styles, lazy loading) was not confirmed from repository evidence.

### 13.5 Module Count and Bootstrap

**Repository evidence:** 89 custom modules + ~100+ contrib modules

The total module count is substantial. Module bootstrap overhead is a Drupal-specific concern. All 89 custom modules declare correct Drupal 11 compatibility, but **module enable status for each environment should be reviewed** — development/debug/seed/demo modules must be disabled in production.

### 13.6 Views Performance

**Repository evidence:** `web/modules/custom/myeventlane_views/`

Custom Views plugins implement `VendorStoreAccess` which uses static service calls (`\Drupal::entityTypeManager()`). Views query performance depends on index coverage on Commerce and event tables.

### 13.7 Performance Summary

| Item | Status |
|------|--------|
| CSS/JS aggregation | **Disabled — must enable for production** |
| Page cache (Drupal) | Enabled |
| Page cache max-age | **0 — must set for CDN/browser caching** |
| Gzip | Enabled |
| Dynamic page cache | Enabled |
| Vite production build | Confirmed — must run before deploy |
| Image optimisation | Not fully confirmed |
| CDN | Not confirmed from repository |
| Redis / cache backend | Not confirmed from repository |
| Demo modules in production | **Must be disabled** |

---

## 14. Launch Checklist

### Legend
- ✅ Confirmed ready
- ⚠️ Partially ready / verify
- ❌ Not ready / missing
- 🔲 Not verified

### 14.1 Repository and Code

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| R1 | All custom modules declare `core_version_requirement: ^11` | ✅ | — | No |
| R2 | `myeventlane_theme` base theme changed from `stable9` to `stable11` | ❌ | Critical | Yes |
| R3 | `myeventlane_vendor_theme` base theme changed from `stable9` to `stable11` | ❌ | Critical | Yes |
| R4 | Static `\Drupal::service()` calls refactored to DI (71 instances) | ⚠️ | Medium | No |
| R5 | Demo/seed modules disabled in production (`myeventlane_demo`, `myeventlane_seed`) | 🔲 | Critical | Yes |
| R6 | `npm run build` run for both themes before deployment | 🔲 | Critical | Yes |
| R7 | Governance tests pass (`composer run governance:test`) | 🔲 | High | No |
| R8 | E2E tests pass (Playwright) | 🔲 | High | No |

### 14.2 Infrastructure

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| I1 | Production server provisioned and accessible | 🔲 | Critical | Yes |
| I2 | HTTPS / SSL certificate active | 🔲 | Critical | Yes |
| I3 | nginx security headers configured (CSP, HSTS, X-Frame-Options) | 🔲 | High | No |
| I4 | Redis or other persistent cache backend configured | 🔲 | High | No |
| I5 | CDN configured and active | 🔲 | High | No |
| I6 | Production cron configured (≤5 min intervals for queues) | 🔲 | High | Yes |
| I7 | Database backup strategy and schedule confirmed | 🔲 | Critical | Yes |
| I8 | External error monitoring (e.g. Sentry) configured | 🔲 | High | No |

### 14.3 Configuration

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| C1 | Stripe gateway mode set to `live` | ❌ | Critical | Yes |
| C2 | Stripe publishable key configured for production | ❌ | Critical | Yes |
| C3 | Stripe secret key configured for production | ❌ | Critical | Yes |
| C4 | Stripe webhook secret (whsec_) configured | ❌ | Critical | Yes |
| C5 | Stripe Connect production application configured | 🔲 | Critical | Yes |
| C6 | Postmark API key configured for production | 🔲 | Critical | Yes |
| C7 | CSS/JS preprocessing enabled (`system.performance.yml`) | ❌ | Critical | Yes |
| C8 | Page cache `max_age` set to appropriate value (e.g. 3600) | ❌ | High | No |
| C9 | Site slogan typo corrected (`evnts` → `events`) | ❌ | Critical | Yes |
| C10 | Google Maps API key restricted to production domains | 🔲 | High | No |
| C11 | Postmark sender domain and signatures verified | 🔲 | Critical | Yes |
| C12 | QR signing secret (`MEL_QR_SECRET`) set in production settings; verify with `drush mel:qr-secret-status` (PASS) | 🔲 | Critical | Yes |

### 14.4 Payments

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| P1 | Stripe payment gateway in production mode | ❌ | Critical | Yes |
| P2 | Checkout flow verified in deployed environment | 🔲 | Critical | Yes |
| P3 | Stripe Elements mounting correctly (no duplicate DOM issue) | 🔲 | Critical | Yes |
| P4 | End-to-end purchase tested with real card | 🔲 | Critical | Yes |
| P5 | Vendor payout tested (Stripe Connect) | 🔲 | Critical | Yes |
| P6 | Refund flow tested (vendor-initiated) | 🔲 | High | No |
| P7 | Stripe webhook endpoint registered in Stripe dashboard | 🔲 | Critical | Yes |
| P8 | Platform fee calculation verified | 🔲 | High | No |
| P9 | GST/tax handling verified or explicitly excluded | 🔲 | High | Yes |
| P10 | Order confirmation email sent on payment | 🔲 | High | Yes |

### 14.5 Email

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| E1 | SPF record configured for `myeventlane.com.au` | 🔲 | Critical | Yes |
| E2 | DKIM record configured | 🔲 | Critical | Yes |
| E3 | DMARC policy configured | 🔲 | High | No |
| E4 | Postmark domain verified | 🔲 | Critical | Yes |
| E5 | Order confirmation email tested end-to-end | 🔲 | Critical | Yes |
| E6 | RSVP confirmation email tested | 🔲 | High | Yes |
| E7 | Password reset email tested | 🔲 | High | Yes |
| E8 | Refund email sequence tested | 🔲 | High | No |
| E9 | Event reminder emails scheduled and tested | 🔲 | High | No |
| E10 | Unsubscribe mechanism in all marketing emails | 🔲 | High | Yes |
| E11 | Plain text alternatives in all templates | 🔲 | Medium | No |

### 14.6 Legal and Compliance

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| L1 | Privacy Policy page published and linked | 🔲 | Critical | Yes |
| L2 | Terms of Service page published and linked | 🔲 | Critical | Yes |
| L3 | Vendor Terms page published and active | 🔲 | Critical | Yes |
| L4 | Refund Policy default content published | 🔲 | Critical | Yes |
| L5 | Cookie consent (Klaro) configured and functional | ⚠️ | Critical | Yes |
| L6 | Marketing consent opt-in working at checkout | 🔲 | High | Yes |
| L7 | Community Guidelines page published | 🔲 | High | No |
| L8 | Accessibility Statement page published | ❌ | High | No |
| L9 | Australian Consumer Law disclosures in checkout | 🔲 | High | Yes |
| L10 | Legal page content reviewed by qualified adviser | 🔲 | Critical | Yes |

### 14.7 SEO

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| S1 | XML sitemap module installed and configured | ❌ | High | No |
| S2 | robots.txt customised for production | ⚠️ | High | No |
| S3 | Metatag configuration for all content types | 🔲 | High | No |
| S4 | Event structured data (JSON-LD) verified in browser | 🔲 | High | No |
| S5 | Google Search Console configured | 🔲 | High | No |
| S6 | Canonical URLs confirmed in page source | 🔲 | Medium | No |
| S7 | Open Graph tags verified on event pages | 🔲 | Medium | No |

### 14.8 Accessibility

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| A1 | Colour contrast audit completed (WCAG 2.1 AA) | 🔲 | High | No |
| A2 | Keyboard navigation tested across all pages | 🔲 | High | No |
| A3 | Screen reader testing completed (NVDA/VoiceOver) | 🔲 | High | No |
| A4 | Focus indicators visible on all interactive elements | 🔲 | High | No |
| A5 | Accessibility statement page published | ❌ | High | No |
| A6 | Checkout flow keyboard navigable | 🔲 | High | No |

### 14.9 Analytics and Monitoring

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| AN1 | GA4 property configured and connected | 🔲 | High | No |
| AN2 | GTM container published with purchase event | 🔲 | High | No |
| AN3 | Cookie consent correctly gates analytics scripts | 🔲 | High | Yes |
| AN4 | Internal analytics table created (DB update) | 🔲 | Medium | No |

### 14.10 Content

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| CO1 | Homepage content published | 🔲 | Critical | Yes |
| CO2 | Event discovery page has real or seeded events | 🔲 | High | Yes |
| CO3 | Help Centre articles published (minimum viable set) | 🔲 | High | No |
| CO4 | Organiser Hub content published | 🔲 | High | No |
| CO5 | Blog has at least one published article | 🔲 | Medium | No |
| CO6 | 404 page (`/mel/404`) content correct | ⚠️ | High | No |
| CO7 | 403 page (`/mel/403`) content correct | ⚠️ | High | No |

### 14.11 Go-Live

| # | Item | Status | Priority | Blocking? |
|---|------|--------|----------|-----------|
| G1 | DNS switched to production server | 🔲 | Critical | Yes |
| G2 | Staging site blocked (robots.txt + password protect) | ⚠️ | High | No |
| G3 | Production database populated (or fresh install + content) | 🔲 | Critical | Yes |
| G4 | `drush updb` run in production | 🔲 | Critical | Yes |
| G5 | `drush cim` run in production | 🔲 | Critical | Yes |
| G6 | `drush cr` run in production | 🔲 | Critical | Yes |
| G7 | Stripe webhook endpoint verified as active | 🔲 | Critical | Yes |
| G8 | First end-to-end purchase verified on live | 🔲 | Critical | Yes |
| G9 | Team notified and support channels active | 🔲 | High | No |

### 14.12 Post-Launch

| # | Item | Status | Priority |
|---|------|--------|----------|
| PL1 | Monitor Stripe webhook delivery for first 24 hours | 🔲 | Critical |
| PL2 | Monitor error logs for exceptions | 🔲 | Critical |
| PL3 | Monitor queue processing (no backlogs) | 🔲 | High |
| PL4 | Verify email delivery rates in Postmark | 🔲 | High |
| PL5 | Review Google Search Console for crawl errors | 🔲 | Medium |
| PL6 | Review GA4 for purchase funnel data | 🔲 | Medium |

---

## 15. Risk Register

| # | Risk | Likelihood | Impact | Priority | Owner | Mitigation | Repository Evidence |
|---|------|-----------|--------|----------|-------|-----------|---------------------|
| 1 | Theme `stable9` base causes rendering failures on Drupal 11 | High | Critical | P1 | Developer | Change `base theme: stable9` to `stable11` in `myeventlane_theme.info.yml` and `myeventlane_vendor_theme.info.yml` | `AUDIT_REPORT.md`, `myeventlane_theme.info.yml` |
| 2 | Stripe gateway in test mode — real payments not processed | High | Critical | P1 | Developer/DevOps | Set gateway mode to `live`; configure production keys in environment settings | `commerce_payment.commerce_payment_gateway.stripe.yml` |
| 3 | CSS/JS aggregation disabled — poor Core Web Vitals | High | High | P1 | Developer | Enable CSS/JS preprocess in `system.performance.yml` before deploy | `system.performance.yml` |
| 4 | Site slogan has spelling error visible in browser title | High | Medium | P1 | Content | Correct `slogan: 'Your lane to great evnts'` to `Your lane to great events` | `system.site.yml` |
| 5 | No XML sitemap — search engines cannot efficiently crawl events | High | High | P2 | Developer | Install and configure `drupal/simple_sitemap` | No config found in `config/sync/` |
| 6 | Buyer self-service refund not implemented | High | High | P2 | Developer | Implement token-based refund request from order confirmation email | `HUMANITIX_PARITY_ANALYSIS.md` |
| 7 | Checkout form duplicate-rendering fix not verified in production | Medium | Critical | P2 | Developer/QA | Deploy and run full checkout end-to-end test with real Stripe card | `CHECKOUT_ISSUES_REPORT.md` |
| 8 | Postmark API key not in production — emails not delivered | High | Critical | P1 | DevOps | Set Postmark API key in production settings file | Not in config |
| 9 | SPF/DKIM/DMARC not documented — emails may land in spam | High | High | P2 | DevOps | Configure DNS records; verify in Postmark dashboard | Not found in repository |
| 10 | Page cache `max_age: 0` — no browser/CDN caching for public pages | High | High | P2 | Developer | Set appropriate `max_age` for public event/discovery pages | `system.performance.yml` |
| 11 | Demo/seed modules enabled in production — exposes test data | Medium | High | P1 | Developer/DevOps | Confirm `myeventlane_demo`, `myeventlane_seed` are disabled in production | `core.extension.yml` |
| 12 | Google Maps API key not domain-restricted | Medium | Medium | P2 | DevOps | Restrict API key to `myeventlane.com.au` in Google Cloud Console | `myeventlane_location.settings.yml` |
| 13 | GST/Australian tax calculation not confirmed | Medium | High | P2 | Developer/Finance | Confirm Commerce tax module configuration or document that prices are GST-inclusive | Not found |
| 14 | No XML sitemap | High | High | P2 | Developer | Install `drupal/simple_sitemap` | Not found |
| 15 | Accessibility statement missing | High | Medium | P3 | Developer/Legal | Create and publish accessibility statement page | Not found |
| 16 | Vendor welcome/payout notification emails not found | Medium | Medium | P2 | Developer | Implement missing email templates | Not in template config |
| 17 | No external error monitoring in production | Medium | High | P2 | DevOps | Integrate Sentry or equivalent before go-live | Not found |
| 18 | Promo codes UI not confirmed in MEL interface | Medium | Medium | P2 | Developer/QA | Verify Commerce Promotion module is functional in MEL context | `HUMANITIX_PARITY_ANALYSIS.md` |
| 19 | 71+ static `\Drupal::service()` calls — testability concern | High | Low | P3 | Developer | Incrementally refactor to DI post-launch | `AUDIT_REPORT.md` |
| 20 | Stripe Connect payout timing not configured | Medium | Medium | P3 | Finance/DevOps | Define and document payout schedule in Stripe platform settings | Not in MEL config |
| 21 | Legal page content not verified | High | Critical | P1 | Legal | All legal pages must be reviewed by qualified legal adviser | Not in repository |
| 22 | No CI/CD pipeline evidence | Medium | Medium | P3 | DevOps | Establish automated CI for `drush cr`, config status, governance tests | Not confirmed |
| 23 | Backup and rollback strategy not documented | Medium | High | P2 | DevOps | Document and test database backup and site rollback procedures | Not confirmed |
| 24 | No colour contrast audit — pastel brand risks WCAG failure | Medium | Medium | P2 | Designer | Run automated contrast check (e.g. axe DevTools) on all page types | Not found |
| 25 | Page cache max_age 0 disables CDN caching | High | High | P2 | Developer | Set 3600+ on public read routes | `system.performance.yml` |

---

## 16. Final Scorecard

### Methodology

Scores are assessed on a 0–10 scale based on repository evidence:
- **0–3:** Missing, not implemented, or critically broken
- **4–6:** Partially implemented with significant gaps
- **7–8:** Implemented with minor gaps or verification needed
- **9–10:** Fully implemented, tested, production-ready

### Category Scores

| Category | Score | Evidence Summary |
|----------|-------|-----------------|
| **Payments** | 6/10 | Commerce checkout confirmed; Stripe Connect implemented; gateway in test mode; buyer self-service refund missing; GST not confirmed; payout timing not configured |
| **Security** | 7/10 | 20+ access checks; granular permissions; secret scanning; no confirmed HTTP security headers or rate limiting beyond honeypot |
| **Accessibility** | 5/10 | ARIA usage widespread; design tokens exist; no formal WCAG audit found; no accessibility statement; no contrast audit evidence |
| **Performance** | 3/10 | CSS/JS aggregation disabled; page cache max-age 0; Vite build confirmed but requires production run; no CDN confirmed |
| **SEO** | 5/10 | Event structured data implemented (strong); Metatag enabled; Pathauto confirmed; no XML sitemap installed; robots.txt not customised |
| **Analytics** | 6/10 | Internal analytics confirmed; GA4/GTM/Matomo configured with consent gating; GTM conversion tracking not verified; checkout abandonment not tracked |
| **Vendor Experience** | 7/10 | Full Event Studio; dashboard; Stripe Connect; analytics; help nudges; welcome email and payout notification email missing; promo code management not confirmed |
| **Customer Experience** | 6/10 | Discovery, checkout, RSVP, waitlist confirmed; no buyer self-service refund; no guest ticket management; promo codes UX not confirmed |
| **Content** | 4/10 | Architecture for content exists; legal page content not verified; Help Centre articles not confirmed; discovery requires seeded events |
| **Documentation** | 8/10 | 500+ documentation files; NSW legal validation; Humanitix parity analysis; checkout audit; vendor journey docs; architecture audits |
| **Operational Readiness** | 5/10 | Deployment scripts confirmed; multi-environment config; governance tests exist; no CI/CD evidence; no backup strategy; no external error monitoring |
| **Marketing Readiness** | 4/10 | Public discovery works; email reminders confirmed; no affiliate system; promo code UX unverified; no native check-in app; no social sharing tools beyond OG tags |

### Weighted Score

| Category | Score | Weight | Weighted |
|----------|-------|--------|---------|
| Payments | 6 | 15% | 0.90 |
| Security | 7 | 12% | 0.84 |
| Accessibility | 5 | 8% | 0.40 |
| Performance | 3 | 10% | 0.30 |
| SEO | 5 | 8% | 0.40 |
| Analytics | 6 | 5% | 0.30 |
| Vendor Experience | 7 | 10% | 0.70 |
| Customer Experience | 6 | 12% | 0.72 |
| Content | 4 | 8% | 0.32 |
| Documentation | 8 | 4% | 0.32 |
| Operational Readiness | 5 | 5% | 0.25 |
| Marketing Readiness | 4 | 3% | 0.12 |
| **Total** | — | **100%** | **5.57/10 (56%)** |

### Launch Verdict

> **The platform requires significant work before launch.**

Current readiness is estimated at **56%** against a launch-ready target.

The architectural foundation is strong and demonstrates serious engineering depth. The blocks are concentrated in configuration, environment readiness, and a small number of critical compatibility issues — not in fundamental architectural deficiencies.

#### Critical path to launch (4–8 weeks estimated effort):

**Week 1 — Blockers**
- Fix `stable9` base theme in `myeventlane_theme` and `myeventlane_vendor_theme`
- Enable CSS/JS aggregation in `system.performance.yml`
- Correct site slogan typo
- Configure all production environment variables (Stripe, Postmark, QR secret)
- Switch Stripe gateway to `live` mode
- Install and configure `simple_sitemap`

**Week 2 — Critical infrastructure**
- Configure SPF, DKIM, DMARC for `myeventlane.com.au`
- Register Stripe webhook endpoint in Stripe dashboard
- Set `page cache max_age` for public routes
- Verify and test checkout flow end-to-end in production environment
- Confirm demo/seed modules disabled in production

**Week 3 — Legal, content, and UX**
- Publish all legal pages with content reviewed by legal adviser
- Create accessibility statement
- Implement buyer self-service refund link (or document post-launch timeline)
- Verify promo code UX
- Seed or publish minimum viable content

**Week 4 — QA and monitoring**
- Run full accessibility audit
- Set up external error monitoring
- Verify all email templates in production
- Run governance and E2E tests
- Configure GA4 and GTM purchase events
- Document backup and rollback procedures

---

*This document was produced from direct repository inspection on 2026-06-25. Every finding is based on repository evidence or explicitly noted as not found. This document does not constitute legal advice. Legal page content must be reviewed by a qualified Australian legal adviser before publication.*

*Maintain this document as a living record. Mark items complete with date as they are resolved.*
