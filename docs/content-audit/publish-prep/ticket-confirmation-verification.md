# Ticket confirmation, calendar, wallet — product verification

**Date:** 2026-05-22  
**Draft:** `help-article-drafts/ticket-confirmation.md`

## Evidence found

### After ticket purchase

| Item | Evidence |
|------|----------|
| Order confirmation email | Yes — template `order_confirmation` (`myeventlane_messaging.template.order_confirmation.yml`) |
| Email content | Order number, event details, ticket summary, PDF/QR assignment note, **calendar .ics attachment** referenced in template |
| My tickets | Yes — route `myeventlane_checkout_flow.my_tickets` → `/my-tickets`; order detail `/my-tickets/order/{commerce_order}` |
| Checkout complete step | Commerce checkout flow `complete` step; sidebar copy: “Confirmation email sent as soon as you complete booking” (`MelReadinessHelper::customerCheckoutSidebarConfidenceLines`) |
| Calendar hint | “Add to your calendar from My Tickets after booking” (same helper) |
| Event .ics (RSVP) | `myeventlane_rsvp.ics_download` — `/event/{node}/ics` (RSVP/events; order email also attaches calendar files per template) |

### Wallet

| Item | Evidence |
|------|----------|
| Module | `myeventlane_wallet` — Apple + Google Wallet |
| Routes | `/wallet/apple/{order_item_id}`, `/wallet/google/{order_item_id}` |
| Config | `show_wallet_buttons` on confirmation; `show_wallet_in_email` for confirmation emails |
| Access | `WalletDownloadAccessChecker` — authorised buyer access |

### QR / PDF

| Item | Evidence |
|------|----------|
| Order email | Mentions PDF and QR when tickets need assignment |
| Ticket issuance | `myeventlane_tickets` pipeline (referenced in messaging tests) |

### Existing help overlap

- Seed `how_to_access_tickets` exists in YAML; **not found** as published node on staging (only “How to buy tickets” nid 1492 in ticket-related list).
- New article complements seeds; avoid duplicating full “access tickets” guide if that node is later published.

## User-facing behaviour (safe to describe)

- After checkout, buyers should receive a **confirmation email** with order details.
- Email may include **calendar attachments** (.ics) per template copy.
- Buyers can open **My tickets** to view the order and download tickets.
- **Wallet** passes may be available on confirmation and in email when enabled in wallet settings (**Needs verification** on staging UI).

## Unsupported or unverified claims

| Claim | Verdict |
|-------|---------|
| Exact menu label “My tickets” | Route confirmed; menu label **Needs verification** |
| Apple Wallet / Google Wallet always shown | Config-gated — say “if available on your order page or email” |
| Calendar add from confirmation page only | Email + My tickets both mentioned in product copy |
| Instant email | Reasonable; timing **Needs verification** (queue/cron) |
| Wallet for all events | **Needs verification** (eligibility, issued ticket required) |

## Proposed safe article wording (summary)

**Title:** Ticket confirmation emails and receipts  

**Intro:** After you buy tickets, you should get a confirmation email. You can also open My tickets to view your order.

**Steps:** Check inbox and junk → open My tickets → use ticket link or QR → keep email as proof.

**Calendar:** Order emails may include a calendar file (.ics); you can also add from My tickets where offered.

**Wallet:** If your order page or email shows wallet options, you can add passes to your phone — **Needs verification** on staging.

## Publish readiness

**Blocked until product behaviour is verified** (staging QA).

**Reason:** Strong code/template evidence, but wallet buttons, assignment flow, and live email attachments were not browser-tested this pass. Publish after one completed test purchase on staging.

**Next step:** New help article (no canonical node) after QA; cross-link “How to buy tickets” (nid 1492).
