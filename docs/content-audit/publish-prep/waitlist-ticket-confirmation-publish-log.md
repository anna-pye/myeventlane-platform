# Waitlist and ticket confirmation — publication log

**Date:** 2026-05-22  
**Branch:** `feature/help-publish-waitlist-ticket-confirmation`  
**Drupal:** database-only (nodes + path aliases); not exported to `config/sync` in this pass.

---

## Duplicate search

| Query terms | Result |
|-------------|--------|
| waitlist, sold out | **No** existing `help_article` |
| confirmation, ticket confirmation, my tickets, wallet, calendar, booking confirmation | **No** exact duplicate |
| Related | nid **1497** “What happens after booking” — thin overlap; **updated in place** (not a second node) |
| Related | nid **1501** “Adding events to your calendar” — calendar only; kept separate |
| Related | nid **1492** “How to buy tickets” — cross-linked |

---

## Published articles

### Waitlist — **new**

| Field | Value |
|-------|-------|
| **nid** | **1669** |
| **Title** | Joining a waitlist |
| **Alias** | `/help/attendees/joining-a-waitlist` |
| **Audience** | `field_audience`: public |
| **Article type** | Guide (tid 70) |
| **Topic** | Buying Tickets (tid 79) |
| **`field_help_status`** | published |
| **`field_help_ai_allowed`** | true |
| **Summary** | When a paid ticket type is sold out and the organiser has enabled a waitlist, you can register your interest on the event booking page. Joining does not book or buy a ticket. |

### Ticket confirmation — **updated (merge)**

| Field | Value |
|-------|-------|
| **nid** | **1497** (was “What happens after booking”) |
| **Title** | After you book a ticket |
| **Alias** | `/help/attendees/after-you-book-a-ticket` |
| **Audience** | `field_audience`: public |
| **Article type** | Guide (tid 70) |
| **Topic** | Tickets (tid 45) |
| **`field_help_status`** | published |
| **`field_help_ai_allowed`** | true |
| **Summary** | Where to find your booking confirmation, My Tickets, calendar links, and optional wallet passes after you buy a ticket. |

**Merge rationale:** Avoid duplicating “after booking” content. Expanded nid 1497 instead of creating a second node.

---

## Wording deliberately kept conditional

| Topic | Constraint in published body |
|-------|------------------------------|
| Waitlist offers | “If the organiser has enabled automatic offers… MyEventLane **may** give you a way to complete purchase”; “Offers are not automatic on every event”; no promise of email |
| Waitlist ticket | “does **not** book or buy a ticket”; “does **not** guarantee” |
| RSVP waitlist | Separate from paid ticket waitlist on book page |
| Confirmation email | “may receive”; “can take a few minutes”; check junk |
| Wallet | “may appear”; “optional”; “not available on every order”; signed-in buyer only |
| QR/PDF | “Some events”; “may arrive in a later email”; assign-tickets step |
| Calendar | “Where supported”; depends on event/device |
| My Tickets / wallet URLs | Sign-in required; anonymous cannot use buyer wallet links |

---

## Commands run

```bash
ddev drush php:script   # one-off publish script (DB-only; not committed)
ddev drush search-api:index mel_content
ddev drush search-api:status mel_content
ddev drush cr
ddev drush php:eval   # HelpRetriever anonymous checks

composer validate
npm run mel:lint
npm run mel:build
```

---

## Validation results

| Check | Result |
|-------|--------|
| `mel_content` index | **100%** (61/61) — 3 items indexed (1669 new, 1497 updated, possible related) |
| Anonymous HTTP `/help/attendees/joining-a-waitlist` | **200** |
| Anonymous HTTP `/help/attendees/after-you-book-a-ticket` | **200** |
| `composer validate` | Pass |
| `npm run mel:lint` | Pass |
| `npm run mel:build` | Pass |

---

## Access verification

| Node | Anonymous HTTP | `field_audience` | Help Assistant (anonymous) |
|------|----------------|------------------|----------------------------|
| 1669 | 200 | public | Retrieved for “join waitlist sold out tickets” |
| 1497 | 200 | public | Retrieved for “ticket confirmation email my tickets”, “apple wallet after booking” |
| 1510 (control) | — | vendor | **Not** returned for “stripe payouts vendor fees” |

---

## Remaining risks

| Risk | Notes |
|------|-------|
| Waitlist offer / claim E2E | Article uses conditional offer language; not re-tested in this pass |
| nid 1497 merge | Old title “What happens after booking” replaced; bookmarks to old title text only |
| Path aliases | Manual aliases; not pathauto |
| Drupal content not in git | Replicate via same editorial copy or export workflow if needed on other envs |
| `how_to_access_tickets` seed | Still not a published node; ticket article covers My Tickets without duplicating access guide |
