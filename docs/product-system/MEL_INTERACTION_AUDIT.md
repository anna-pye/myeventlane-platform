# MEL Interaction Audit

**Status:** Complete  
**Date:** 2026-07-24  
**Type:** Documentation only  
**Authority:** Product System interaction principles · MEL interaction authority audit · VX2 QA checklists

---

## Intent

Every interaction should feel intentional: hover, focus, loading, saving, success, publishing, deleting, refunding.

---

## State matrix (required behaviour)

| Interaction | Expected behaviour | Current maturity |
| --- | --- | --- |
| **Hover** | Subtle affordance on clickable cards/buttons; no layout jump; disabled under reduced motion for lift | Good on VX2-02A cards; spot-check legacy |
| **Focus** | Visible token focus ring; logical tab order; skip link on shells | Good on new hubs; Door Mode critical |
| **Loading (read)** | Skeleton or polite status — not blank | Mixed — skeletons + feature spinners |
| **Saving** | Saving → Saved / Failed with recovery | Forms generally; Studio async parallel |
| **Success** | Brief confirmation + next step | Publish celebration present; Stripe celebrate partial |
| **Publishing** | Blocked checklist → Publish → Celebrate + Share | Strong Workspace direction |
| **Deleting / Archive** | Confirm; prefer Archive language for tickets | Confirm patterns vary (modal vs native) |
| **Refunding** | Named confirm + irreversibility; success/fail explicit | Entry points exist; parallel refund routes residual risk |
| **Sending message** | Audience confirmed; sending status; Failed visible | VX2-06 improved failure honesty |
| **Check-in** | Immediate visual + SR feedback; undo policy clear if any | Door Mode canonical; QA checklist open |
| **Stripe Connect** | External round-trip explained; return to Needs attention or Ready | Hub health states shipped |

---

## Destructive & money flows

| Flow | Must include | Risk if weak |
| --- | --- | --- |
| Refund | Confirm panel · amount/name · cannot undo · success/fail | Trust + support load |
| Cancel event | Consequence for attendees · confirm | Accidental blast |
| Take offline | Visibility explanation | Confusion with draft |
| Disconnect / payment fix | Why + Fix CTA — never silent | Lost payouts |
| Archive ticket | Capacity/sales impact note | Accidental inventory loss |

---

## Parallel interaction owners (from authority audit)

| Owner | Use for new organiser work? |
| --- | --- | --- |
| MELInteractionSystem (`mel_modal`, `mel_drawer`, governed loading) | **Yes — default** |
| Native `<dialog>` (e.g. event card removal) | Only if already established; prefer migrate later |
| `window.confirm` | Minimal legacy guards only |
| Drupal AJAX dialog | Avoid for primary organiser journeys |
| Feature-local drawers (Studio, AI) | Do not copy; converge over time |
| Notification `role="dialog"` panel | Keep as menu pattern; don’t call it mel-modal |

---

## Per-area notes

### Dashboard

- Queue item click = primary path; hover/focus on queue rows intentional.
- Stripe chip → Payments hub (not orphan settings).

### Workspace

- Next Action is the primary interactive magnet.
- Expandable readiness: disclosure pattern with `aria-expanded`.
- KPI cards: whole-card hit target or explicit CTA — pick one per card, consistently.

### Tickets

- Sticky Add Ticket on mobile — focus must not trap oddly.
- Advanced Ticket Tools: disclosure open telemetry (`advanced_tools_opened`).
- Duplicate/Archive: confirm archive; duplicate may soft-succeed with toast/status.

### Attendees

- Search immediate; filter chips `aria-pressed`.
- Check-in control: busy state while saving; live region for status.
- Message/Export/Refund: clear handoff — do not leave organiser unsure if action started.

### Payments

- Primary CTA changes by health state — intentional, not generic “Submit”.
- Deep links to payouts/refunds preserve hub mental model.

### Messages

- Compose radios for audience; disable send until valid.
- History Failed / Needs attention must be actionable (retry path when exists).

---

## Motion & feedback

| Allowed | Avoid |
| --- | --- |
| Card lift (reduced-motion safe) | Endless spinners on money pages |
| Short success pulse | Parallax on Door Mode |
| Publish celebration (dismissible) | Blocking animation >300ms without progress text |
| Skeleton shimmer (respect reduced motion) | Multiple competing `aria-live` shouts |

---

## Interaction maturity score

| Dimension | Score /10 |
| --- | --- |
| Focus & keyboard on core paths | 8.5 |
| Loading/saving clarity | 7.5 |
| Destructive confirms | 7.5 |
| Publish ceremony | 8.5 |
| Money/refund intentionality | 8.0 |
| Single interaction authority | 6.5 |
| **Overall** | **7.8** |

---

## Hardening checklist (product, not this doc’s runtime work)

1. New confirms → `mel_modal` confirmation.  
2. One polite live region per surface for async status.  
3. Refund + cancel always named + irreversible.  
4. Reduced-motion verified on card lift + skeletons.  
5. Door Mode keyboard path in manual QA.
