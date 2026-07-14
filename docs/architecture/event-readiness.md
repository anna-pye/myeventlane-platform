# Event readiness (ACE Phase 5)

Customer-facing **Event Readiness** panel that answers: am I ready, what happens next, where do I go, what do I need, has anything changed, who do I contact.

Presentation and orchestration only. Does **not** change Commerce, payments, QR generation, ticket validation, or reminder scheduling.

Related:

- [account-dashboard-bookings-architecture.md](./account-dashboard-bookings-architecture.md) (ACE Phase 3)
- [digital-ticket-experience.md](./digital-ticket-experience.md) (ACE Phase 4)
- [../phase-10-reminders.md](../phase-10-reminders.md) (existing messaging reminders)

---

## Ownership map

| Purpose | Owner | Route / entry | Presenter / builder | Template | Duplicate risk |
|---------|-------|---------------|---------------------|----------|----------------|
| Event Readiness panel | `MyTicketsOrderViewModelBuilder` → `order.readiness` (+ `detail_items` for accordion) | `/my-tickets/order/{commerce_order}` | Same builder + `MelReadinessHelper` copy | Collapsed `<details>` under digital pass in `myeventlane-order-detail.html.twig` | **Do not** add a second readiness dashboard |
| Digital pass | Phase 4 — `ticket.pass` | Same route | `UniversalTicketViewModelBuilder` + order builder enrich | Same Twig | Low if pass stays QR owner |
| Reminder scheduling | `myeventlane_messaging.event_reminder_scheduler` | Cron / queues `event_reminder_7d`, `event_reminder_24h` | `EventReminderScheduler` | Messaging templates | **Do not revive** legacy `Scheduler\EventReminderScheduler` for this |
| Reminder emails | `myeventlane_messaging` | Queue workers | `MessagingManager` + install templates | Email HTML | Keep separate from in-app notifications |
| In-app notifications | `myeventlane_notifications` | `/my-notifications` | Domain triggers | Inbox Twig | Distinct from email reminders |
| Venue / location | Event node `field_venue_name` / `field_location` | Event page | Theme preprocess + ticket VM location | `node--event--full.html.twig` | Do not invent fields |
| Maps / directions | Event page pattern `maps.google.com/?q=` | Event page + readiness CTA when location exists | Order builder `directionsUrl()` | Theme / readiness Twig | Reuse query pattern only |
| Accessibility | `field_accessibility*` | Event page | Projected when non-empty | Event full Twig | No public-transport field — use directions copy when present |
| Organiser contact | `field_contact_email` or event page | mailto / event URL | Order builder context | Event full Twig | **No** invented `/contact-organiser` |
| Refund policy | `LegalSettingsService` + optional `field_refund_policy` | Help Centre / platform URL | Help links already on pass | Help Centre | Same URLs as Phase 4 `order.help` |
| Help Centre | `myeventlane_help_centre` | `/help` | Existing routes | Help templates | Link only |
| Customer hub | `myeventlane_account` | `/my-account` | `CustomerHubDataBuilder` | Account Twig | Do not grow `/my-events` |
| Publish / vendor readiness | `EventReadinessService` / Studio | Vendor | Studio readiness | Vendor Twig | **Out of scope** for customer ACE |
| ACE copy vocabulary | `myeventlane_surface.state_readiness_helper` → `MelReadinessHelper` | Consumed | Helper methods | — | Extend; do not fork |

---

## Attendee journey

```
Dashboard (/my-account)
    ↓
My bookings (/my-tickets)
    ↓
Booking / digital pass (/my-tickets/order/{order})
    ↓
Event Readiness panel (same page — answers “am I ready?”)
    ↓
View ticket / QR · Get directions · Contact · Help
    ↓
Arrival — show QR on pass
    ↓
Staff check-in (canonical scanner)
```

### Journey findings (this phase)

| Issue | Treatment |
|-------|-----------|
| Dead end after pass (unclear next step) | One primary action on readiness |
| Venue / a11y / contact buried on event page | Checklist rows only when data exists; deep-link event page |
| Repeated help copy | Reuse same refund / Help Centre URLs as Phase 4 `order.help` |
| Conflicting CTAs | Single primary CTA; pass keeps secondary tools |
| Reminder opacity | Presentation note only — existing 7d / 24h messaging scheduler |

---

## Reuse decisions

| Need | Decision |
|------|----------|
| Ticket / QR / PDF | Reuse Universal ticket VM — no new ticket logic |
| Order aggregation | Extend `MyTicketsOrderViewModelBuilder` with `readiness` |
| Status language | Extend `MelReadinessHelper` (`customerEventReadiness*`) next to hub / pass labels |
| Directions | Reuse Google Maps query pattern from event theme — only if location string exists |
| Reminders | Reuse messaging scheduler timings in copy; **no new scheduler** |
| Help / refund | Reuse `LegalSettingsService` + Help Centre route |
| Styles | Extend `_user.scss` beside `.mel-ticket-pass*` |
| Routes | **None new** — same order detail route |

### New vs extended

| Component | Status | Justification |
|-----------|--------|---------------|
| `order.readiness` payload | Extended on existing builder | Avoids a second presenter / route |
| `.mel-event-readiness*` SCSS | New class block in existing partial | No existing customer readiness panel styles |
| `docs/architecture/event-readiness.md` | New | Phase 5 ownership map |
| Reminder scheduler / queue / email | Unchanged | Already owns delivery |

---

## Readiness states

Presentation keys only — never expose Commerce state ids in Twig.

| Key | Source signals |
|-----|----------------|
| `payment_pending` | Order not in My Tickets completed states |
| `ticket_ready` | Issued ticket ready; upcoming event |
| `today` / `tomorrow` | Event start day overlay (hub vocabulary) |
| `checked_in` | Issued ticket / scanner signal |
| `cancelled` / `expired` | Ticket operational status |
| `completed` | Event start in the past |
| `confirmed` | Booking confirmed without ticket-ready signals |

Labels: `MelReadinessHelper::customerEventReadinessStatusLabel()`.

---

## Primary action rules

Exactly one `primary_action` when a usable URL exists:

1. Cancelled / expired → Help Centre (else event page)
2. Checked in → View event
3. Today + QR → View ticket (`#mel-pass-entry`)
4. Today + venue only → Get directions
5. QR / ticket → View ticket or Download ticket
6. Venue → Get directions
7. Contact email → Contact organiser (`mailto:`)
8. Event page → View event
9. Else Help Centre

Actions that cannot succeed (no URL / no data) are omitted. Waitlist / Complete Payment are **not** shown unless a confirmed customer URL exists on this surface (My Tickets is completed-order scoped).

---

## Extension points

1. **`order.readiness`** on My Tickets order models — presentation only.
2. **`MelReadinessHelper` customer Event Readiness methods** — shared ACE copy.
3. **Reminder note** — keep pointing at messaging 7d/24h; do not add customer-facing send status without a real API.
4. **Hub deep-link** — future: surface readiness summary on `/my-account` next booking card by consuming the same builder keys (no second checklist builder).

---

## Future roadmap (documented only)

| Item | Notes |
|------|-------|
| Public transport field | Not in schema — do not invent |
| Wallet primary CTA | When `WalletPresentationGate` allows |
| Reminder delivery status | Only if messaging exposes a customer-safe signal |
| Consolidate `/my-events` | Already constrained by Phase 3 |
| Vendor “attendee readiness” ops | Separate from this customer panel |

---

## Validation checklist

```bash
git diff --check
composer validate
ddev drush cr
npm run mel:lint
npm run mel:build
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_checkout_flow/tests/src/Kernel/MyTicketsOrderViewModelBuilderTest.php \
  --do-not-cache-result
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_surface/tests/src/Unit/MelReadinessHelperCustomerTest.php \
  --do-not-cache-result
```

Confirm:

- No duplicated reminder scheduler
- No duplicated ticket / QR / PDF builders
- No new customer dashboard or route
- No Commerce / payment / validation changes
