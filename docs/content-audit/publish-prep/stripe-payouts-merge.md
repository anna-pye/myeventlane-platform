# Stripe payouts — merge prep (nid 1510)

**Date:** 2026-05-22  
**Canonical node:** 1510 — “Payouts and fees”  
**Draft source:** `help-article-drafts/stripe-payouts.md`

## Existing node summary

| Field | Value |
|-------|--------|
| nid | 1510 |
| Title | Payouts and fees |
| Audience | `vendor` |
| Published (node status) | Yes |
| `field_help_status` | `published` |
| `field_help_ai_allowed` | true |
| Article type | FAQ |
| Summary | Payments and fees |
| Body (current) | One sentence: payments processed securely and paid out to your account. |
| Related articles | None |
| Path alias | `/node/1510` |
| Safe for vendor | Yes (vendor audience; node access enforced) |
| Needs updating | **Yes** — lacks Stripe Connect onboarding and fee context |

## Draft summary

Draft explains Stripe connected accounts, onboarding before paid sales, dashboard/payout checks, and avoids fixed payout schedules. Uses “organiser” in user-facing text. Flags exact timing and dashboard labels as **Needs verification**.

## Conflicts

| Topic | Node | Draft | Resolution |
|-------|------|-------|------------|
| Title | Payouts and fees | Connecting Stripe and receiving payouts | **Keep** “Payouts and fees” (already live); expand body to cover connection + payouts |
| Audience | vendor | vendor | Keep `vendor` (internal); user-facing copy says “organiser” |
| Article type | FAQ | guide | Keep FAQ unless editorial prefers Guide |
| Fee detail | None | Implied | Do not invent fee percentages; refer to dashboard/Stripe |

## Missing facts (do not invent)

- Exact payout delay (days) in Stripe dashboard.
- Exact UI labels for Stripe section in vendor dashboard (**Needs verification** on staging).
- Whether `charges_enabled` gate blocks publish (referenced in audits — confirm label shown to organisers).

## Evidence (code)

- Vendor Stripe Connect route: `myeventlane_vendor.stripe_connect` → `/stripe/connect`
- Callback: `/stripe/connect/callback`
- Payments via `stripe_connect` Commerce payment gateway

## Proposed final title

**Payouts and fees**

## Proposed final summary

How organisers connect Stripe, sell paid tickets, and where to check payouts and fees.

## Proposed final body

```html
<p>Paid ticket sales on MyEventLane are processed through Stripe. You need a connected Stripe account before you can sell paid tickets and receive payouts.</p>

<h2>Connect Stripe</h2>
<ol>
  <li>Sign in to your organiser dashboard.</li>
  <li>Open the Stripe or payments area and start Connect — the path is usually <a href="/stripe/connect">Connect Stripe</a>.</li>
  <li>Complete Stripe’s verification steps (business and bank details as required).</li>
  <li>Return to your event and confirm paid ticket types can be published.</li>
</ol>

<h2>Payouts and fees</h2>
<p>When tickets sell, funds are handled according to Stripe’s rules and your account status. Payout timing and fees are shown in Stripe and in your organiser dashboard — they can vary by account and verification state.</p>
<p>Platform and processing fees, if applicable, are described in your dashboard or fee schedule. <strong>Needs verification:</strong> exact fee wording on staging.</p>

<h2>If something looks wrong</h2>
<p>If onboarding stalls or a payout does not match what you expect, note any message from Stripe and contact MyEventLane support with your event name. Do not share secret keys or full bank details in support messages.</p>
```

## Recommended field values

| Field | Recommended value |
|-------|-------------------|
| `field_audience` | `vendor` |
| `field_help_status` | `published` |
| `field_help_ai_allowed` | `true` |
| `field_help_article_type` | FAQ (keep) or Guide (editorial choice) |
| Path alias | **Needs verification** — e.g. `/help/payouts-and-fees` |

## Publish readiness

**Ready for editorial update** (with staging label check).

**Reason:** Core behaviour (Stripe Connect route, connected payments) is confirmed. Do not publish exact payout calendars until verified on staging. Merge into nid 1510; do not create a second article.
