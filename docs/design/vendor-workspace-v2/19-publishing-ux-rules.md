# Vendor Workspace v2 — Publishing UX Rules

**Status:** Product design (Sprint 3B) — documentation only  
**Date:** 2026-07-25  
**Authority:** PDS 07 · 08 · audit `16` · state model `17` · wireframes `18`  
**Runtime anchors:** `EventStudioPublishController`, `mel-event-studio-shell.js`, `buildPublishSuccessHandoff`

---

## 1. Interaction principles

1. One primary action at a time.
2. Fail loud with recovery — never silent publish failure.
3. Prefer inline calm messaging over modal spam; confirm only for destructive/irreversible.
4. Respect `prefers-reduced-motion`.
5. Australian English.

---

## 2. Loading

| Moment | Behaviour |
| --- | --- |
| Publish request starts | Primary CTA → **Publishing…**; `disabled`; `aria-busy="true"` |
| Page body | Launch checklist frozen; no competing clicks |
| Duration | Expect &lt;2s typical; after 5s keep spinner + “Still working…” |
| Network offline | Immediate failure message + Retry |

Do not navigate away during request.

---

## 3. Publishing in progress

- Button class pattern may keep `is-publishing` (existing shell).
- Announce to screen readers: “Publishing your event” via `aria-live="polite"` region.
- Block double-submit (ignore further clicks).

---

## 4. Success

| Channel | Content |
| --- | --- |
| Hero | Status → Live; CTA → Share event |
| Inline Launch Centre | Swap to live narrative (`20`) |
| Live region | “Your event is now live” |
| Optional toast | Quiet, auto-dismiss 5s — **not** required if inline success is visible |
| Handoff payload | Reuse `buildPublishSuccessHandoff` fields |

No full-page redirect required for AJAX path (current behaviour). Celebrate query path remains supported for form handoffs.

---

## 5. Failure

Map existing codes to organiser language:

| Code / HTTP | Organiser message | Recovery |
| --- | --- | --- |
| `unsaved_changes` 409 | Save this section before changing publish state | Save / stay |
| `stale_state` 409 | This event changed — refresh to continue safely | Refresh |
| `autosave_draft` 409 | Autosaved draft waiting in {section} | Restore or save |
| `cannot_publish` 422 | Cannot launch yet — {first reason} | Continue setup / Fix |
| `publish_failed` 500 | Publish failed. Try again shortly | Retry |
| `cannot_unpublish` / `unpublish_failed` | Parallel unpublish copy | Retry / Support |

Inline alert (role=`alert` for failures). Keep checklist visible when readiness-related.

---

## 6. Retry

- Primary failure CTA: **Try again** (re-invoke same publish).
- If readiness: **Continue setup** instead of retry.
- After 2 hard 500s: suggest refresh + Support link (help audience: vendor).

---

## 7. Warnings

| Type | Treatment |
| --- | --- |
| Recommendations (cover, summary) | Non-blocking; secondary list “Nice to have” |
| Stripe / profile blockers | Blocking checklist rows |
| Live + readiness regression | Warning banner on Launch Centre + Hero Continue setup |

Never mark Ready/green if eligibility would deny.

---

## 8. Confirmation

| Action | Confirm? | Pattern |
| --- | --- | --- |
| Publish | No modal if Ready (deliberate Hero click is consent) | Optional one-line consequence already visible |
| Unpublish | **Yes** | Modal or disclose panel: public page hidden; share links break; tickets/RSVP implications |
| Visibility → private/passcode while live | Soft confirm | “Guests may lose access” |

---

## 9. Toast vs inline

| Prefer inline | Prefer toast |
| --- | --- |
| Launch success narrative | Brief “Saved” / “Link copied” |
| Publish blockers | — |
| Unpublish result | Optional short toast after inline state change |

Toasts: max one; do not stack with celebrate panel.

---

## 10. Animations

| Motion | Spec |
| --- | --- |
| Success enter | Soft fade/slide ≤200ms |
| Checklist check | Subtle check morph ≤150ms |
| Confetti / emoji burst | **Optional** and **off** when reduced motion — prefer calm checkmark |
| Sticky CTA | No bounce |

---

## 11. Reduced motion

```css
@media (prefers-reduced-motion: reduce) {
  /* no celebrate motion; instant state swap; no spinner animation — use static “Publishing…” text */
}
```

Shell JS must not rely on animation completion for state.

---

## 12. Accessibility (Stage 10 requirements)

| Area | Requirement |
| --- | --- |
| Keyboard | All Launch actions reachable; focus order: narrative → checklist links → secondary → sticky CTA |
| Focus | After publish success, move focus to success heading (“Your event is now live”) |
| After failure | Focus to alert |
| Screen readers | `aria-live="polite"` for progress/success; `alert` for failures |
| ARIA | Primary CTA `aria-busy` while publishing; panels `aria-hidden` when unused (existing) |
| Touch targets | ≥44×44px @390 |
| Contrast | WCAG AA on status badges and checklist icons |
| 390 layout | Sticky primary only; no horizontal overflow |
| Dual Publish | Forbidden — avoids ambiguous accessible name collision |

---

## 13. Copy snippets (approved direction)

| Situation | Copy |
| --- | --- |
| Ready | “You’re ready to go live.” |
| Blocked | “Almost there — finish the checklist to launch.” |
| Publishing | “Publishing…” / “Going live — this usually takes a moment.” |
| Success | “Your event is now live” |
| Unpublish confirm | “Unpublish this event? Your public page will no longer be available.” |
| Retry | “Something went wrong. Try again.” |

Avoid: exclusive, VIP, FOMO, “Publish node”, “moderation”.

---

## 14. Mission Control interaction

- Publishing AJAX may refresh Mission Control payload (existing) — **presentation only**.
- Do not change MC information architecture in this sprint’s implementation phase either without PO + unfreeze.
- Launch Centre owns launch narrative; MC owns Home ops narrative.
