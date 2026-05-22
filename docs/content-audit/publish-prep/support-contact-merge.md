# Support contact — merge prep (nid 1498)

**Date:** 2026-05-22  
**Canonical node:** 1498 — “Contacting support”  
**Draft source:** `help-article-drafts/support-contact.md`

## Existing node summary

| Field | Value |
|-------|--------|
| nid | 1498 |
| Title | Contacting support |
| Audience | `public` |
| Published (node status) | Yes |
| `field_help_status` | `published` |
| `field_help_ai_allowed` | true |
| Article type | Guide |
| Summary | How to get help |
| Body (current) | Single short paragraph: check event page first; organiser for event issues; MEL support for platform help. |
| Related articles | None |
| Path alias | `/node/1498` (no friendly alias) |
| Safe for public | Yes |
| Needs updating | **Yes** — body is too thin for hub/Assistant quality |

## Draft summary

The draft adds booking-specific framing (tickets, payment, refund, access), numbered steps, organiser vs platform split, and “if it still does not work”. It correctly flags signed-in support path and anonymous flow as **Needs verification**.

## Conflicts

| Topic | Node | Draft | Resolution |
|-------|------|-------|------------|
| Title | Contacting support | Contacting support about a booking | Keep shorter canonical title; use booking context in summary and intro |
| Support URL | Not stated | `/my/support` | **Confirmed** for signed-in users with `view own escalation` |
| Anonymous support | Not stated | Help Centre Contact support | **Confirmed:** anonymous users without escalation permission land on Help Centre (`HelpCentreController` / `_myeventlane_help_centre_contact_support_url`) |
| SLA / response time | None | None | Do not add |
| Email address | None | None | Do not invent |

## Missing facts (do not invent)

- Public email for support (none confirmed in code audit).
- Whether anonymous users can open escalations without signing in.
- Exact menu label for “My tickets” (route exists: `/my-tickets`).

## Proposed final title

**Contacting support**

(Optional subtitle in summary only: “about a booking or ticket”.)

## Proposed final summary

How to get help with a booking, payment, or ticket — and when to contact the event organiser instead.

## Proposed final body

```html
<p>Start with the event page if your question is about the venue, schedule, or what to bring. The organiser is best placed to answer those questions when contact details are listed there.</p>

<p>For booking, payment, refunds, or problems accessing your tickets on MyEventLane, use the steps below.</p>

<h2>What to do next</h2>
<ol>
  <li>Find your confirmation email or sign in and open <strong>My tickets</strong> at <a href="/my-tickets">/my-tickets</a>.</li>
  <li>Note the event name, date, and your order or ticket reference if you have one.</li>
  <li>If you are signed in, open <a href="/my/support">Support</a> to view or add a support request.</li>
  <li>Describe what happened and what you need (for example a missing email, wrong ticket, or refund question).</li>
  <li>If you are not signed in, go to the <a href="/help">Help Centre</a> and use <strong>Contact support</strong> from there.</li>
</ol>

<h2>If it still does not work</h2>
<p>If you cannot use the support area, check your junk folder for confirmation mail. For event-day questions, use the organiser contact on the event page. Do not submit multiple payments unless you are sure no confirmation arrived.</p>
```

## Recommended field values

| Field | Recommended value |
|-------|-------------------|
| `field_audience` | `public` |
| `field_help_status` | `published` (keep) |
| `field_help_ai_allowed` | `true` (keep) |
| `field_help_article_type` | Guide (keep) |
| Path alias | **Needs verification** — suggest `/help/contacting-support` when aliases are standardised |

## Publish readiness

**Ready for editorial update.**

**Reason:** Routes `/my/support` and `/my-tickets` are confirmed in code. No duplicate node should be created. Update nid 1498 body and summary in Drupal when publishing is authorised; reindex `mel_content` after save.
