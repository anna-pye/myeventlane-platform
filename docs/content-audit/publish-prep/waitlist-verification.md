# Waitlist — product verification

**Date:** 2026-05-22  
**Draft:** `help-article-drafts/waitlist.md`

## Evidence found

### Paid ticket waitlist (primary public path)

| Item | Evidence |
|------|----------|
| Exists | Yes — `TicketTierWaitlistService`, entity `mel_ticket_waitlist_entry` |
| Public-facing UI | Yes — `TicketSelectionForm` on event book flow: email, quantity, **Join waitlist** per sold-out paid tier |
| Scope | **Paid ticket tiers only** — `joinWaitlist()` throws if not paid tier or tier not sold out |
| Join | `joinWaitlist()` creates `STATUS_WAITING` entry; shows position message |
| Auto-invite / offer | **Tier-level** `auto_promote_waitlist` on `mel_ticket_type`; `processAutoPromotions()` creates `STATUS_OFFERED` with token + expiry |
| Offer email | Queue `mel_ticket_waitlist_offer_mail` → `TicketTierWaitlistOfferMailWorker` |
| RSVP waitlist | Separate — `myeventlane_rsvp` waitlist for free RSVP capacity (`RsvpPromotionManager`, settings `auto_promote`) |

**Code paths:**

- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php` — waitlist form + `joinWaitlistSubmit`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketTierWaitlistService.php` — join, offer, `processAutoPromotions`, cron hook at ~550
- `web/modules/custom/mel_ticket/src/Entity/TicketWaitlistEntry.php`
- `web/modules/custom/myeventlane_rsvp/` — RSVP waitlist (different product surface)

## User-facing behaviour (safe to describe)

- On a **sold-out paid ticket** type where the organiser enabled waitlist, buyers can submit email + quantity on the event booking page.
- Joining does **not** guarantee a ticket.
- When auto-promotion is enabled on that tier and capacity opens, the system may create a **time-limited offer** and queue an email (implementation present; copy not verified on staging).

## Unsupported or unverified claims (draft review)

| Claim | Verdict |
|-------|---------|
| “Event may offer a waitlist” | OK if sold-out + tier waitlist enabled |
| “You may receive an offer to complete purchase” | OK with qualifier: **when the organiser enabled auto-promotion and a spot opens** |
| “Offers may expire” | OK — `OFFER_TTL` in service |
| “Message from organiser or MyEventLane” | Prefer **email from MyEventLane** for ticket offers; organiser message not confirmed |
| RSVP / free event waitlist in same article | **Avoid** unless clearly split into two sections — different module |
| Guaranteed ticket | **Remove** — never promise |

## Proposed safe article wording (summary)

**Title:** Joining a waitlist when tickets are sold out  

**Intro:** If a paid ticket type is sold out, you may be able to join a waitlist on the event page. This records your interest; it does not guarantee a ticket.

**Steps:** Open event → sold-out tier → enter email → Join waitlist → watch email.

**If spot opens:** If enabled for that ticket type, you may receive an email with a link to complete purchase within a limited time.

**RSVP:** Optional one-line cross-link: free RSVP events may use a separate waitlist — **Needs verification** for public CTA copy on RSVP form.

## Publish readiness

**Blocked until product behaviour is verified** (staging QA).

**Reason:** Code supports paid-tier waitlist and offer emails, but this pass did not confirm on staging: visible “Join waitlist” on a real sold-out event, offer email content, claim link URL, or RSVP vs paid wording. Recommend browser test on one sold-out paid event before publishing.

**Next step:** Staging QA checklist → then editorial update as **new** help article (no duplicate node found) or section in “How to buy tickets”.
