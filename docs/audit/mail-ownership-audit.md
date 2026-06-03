# MEL Mail Ownership Audit

**Date:** 2026-06-03  
**Mode:** Repository audit (Phase 0)  
**Decision context:** Option B — Postmark inside `myeventlane_messaging` provider architecture.  
**Scope:** Custom modules + committed config. Drupal core user mail included as a system entry point.

## Summary

| Metric | Count |
|--------|------:|
| **Total email entry points** | **40** |
| **Category A — MessagingManager** | **26** |
| **Category B — direct MailManager / core mail** | **13** |
| **Category C — mixed / orphaned / parallel** | **4** |
| **Estimated Postmark coverage (Option B, wiring only)** | **~65% of entry points** |

Postmark activation via `delivery_provider: postmark` affects only the **MessagingManager → DeliveryProviderManager → provider** path. All Category B paths and Drupal core auth mail remain on `mime_mail` → `sendmail` unless separately migrated.

**Transport (sync config, unchanged by this audit):** `system.mail` → `mime_mail`; `mailer_dsn.scheme: sendmail` (`config/sync/system.mail.yml`, `config/sync/mailsystem.settings.yml`).

---

## Master inventory

| File | Template / mail key | Purpose | User-facing | Transactional | Uses MessagingManager | Uses MailManager |
|------|---------------------|---------|:-----------:|:-------------:|:---------------------:|:----------------:|
| **Category A — MessagingManager** |
| `web/modules/custom/myeventlane_messaging/src/EventSubscriber/OrderPlacedSubscriber.php` | `order_confirmation`, `boost_confirmation` | Order placed → confirmation + boost receipt | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/EventSubscriber/OrderPaidInvoiceSubscriber.php` | `order_invoice` | Tax/payment invoice after order paid | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/EventSubscriber/OrderPaidConfirmationPdfRecoverySubscriber.php` | `order_confirmation` | Re-queue confirmation when PDF ready | Yes | Yes | Yes (via `OrderConfirmationQueueBuilder`) | No |
| `web/modules/custom/myeventlane_messaging/src/Controller/ResendOrderEmailController.php` | `order_confirmation` | Admin resend order confirmation | Yes | Yes | Yes (via `OrderConfirmationQueueBuilder`) | No |
| `web/modules/custom/myeventlane_messaging/src/Service/OrderConfirmationQueueBuilder.php` | `order_confirmation` | Builds context; queues confirmation | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Scheduler/CartAbandonedScheduler.php` | `cart_abandoned` | Abandoned cart reminder | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Scheduler/BoostReminderScheduler.php` | `boost_reminder` | Boost renewal reminder | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Scheduler/EventReminderScheduler.php` | `event_reminder` | Legacy event reminder scheduler | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Service/EventReminderScheduler.php` | `event_reminder_24h`, `event_reminder_7d` | Order-based event reminders | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Plugin/QueueWorker/EventReminder24hWorker.php` | `event_reminder_24h` | 24h reminder queue worker | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Plugin/QueueWorker/EventReminder7dWorker.php` | `event_reminder_7d` | 7d reminder queue worker | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_rsvp/src/Service/RsvpMailer.php` | `rsvp_confirmation`, `rsvp_vendor_copy` | RSVP attendee + vendor copy | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_refunds/src/Service/RefundProcessor.php` | `refund_*` (12 templates) | Refund lifecycle notifications | Yes | Yes | Yes (`queue` + sync `sendMessage`) | No |
| `web/modules/custom/myeventlane_refunds/src/Form/VendorCancelEventForm.php` | `event_cancelled` | Event cancellation notices | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_commerce/src/Plugin/QueueWorker/TicketTierWaitlistOfferMailWorker.php` | `ticket_tier_waitlist_offer` | Tier waitlist offer email | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_admin_dashboard/src/Form/AdminApproveDeployEventForm.php` | `vendor_event_update` | Admin deploy approval notice | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_messaging/src/Form/VendorNotifyForm.php` | Dynamic (`vendor_event_*`, etc.) | Vendor-initiated attendee comms | Yes | Mixed | Yes | No |
| `web/modules/custom/myeventlane_vendor_comms/src/Plugin/QueueWorker/VendorEventCommsWorker.php` | `vendor_event_{type}` | Queued vendor event broadcasts | Yes | Mixed | Yes | No |
| `web/modules/custom/myeventlane_pro/src/Service/ProSubscriptionLifecycleScheduler.php` | `pro_subscription_*` | Pro subscription payment failures / renewal | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php` | `pro_cart_abandoned_w1`, `pro_cart_abandoned_w2` | Pro abandoned cart (primary path) | Yes | Operational | Yes | No (fallback in Cat. C) |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/Reminder24hWorker.php` | `event_reminder_24h` | Automation 24h reminder | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/Reminder2hWorker.php` | `event_reminder_2h` | Automation 2h reminder | Yes | Operational | Yes | No |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/EventCancelledWorker.php` | `event_cancelled` | Automation cancellation notice | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/WaitlistInviteWorker.php` | `waitlist_invite` | Automation waitlist invite | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/SalesOpenWorker.php` | `sales_open` | Sales open vendor notice | Yes | Yes | Yes | No |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/ExportReadyWorker.php` | `export_ready_csv`, `export_ready_ics` | Export ready vendor notice | Yes | Yes | Yes | No |
| **Category B — direct MailManager / core** |
| `web/modules/custom/myeventlane_messaging/src/Service/Delivery/DrupalMailProvider.php` | `myeventlane_messaging:generic` | Transport adapter for MessagingManager | Yes | Yes | N/A (internal) | Yes |
| Drupal core `user` module | `password_reset`, `register_*`, `status_*`, `cancel_confirm` | Account verification, password reset, registration | Yes | Yes | No | Yes |
| `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryCron.php` | `myeventlane_boost:boost_expired` | Boost expired vendor notice | Yes | Yes | No | Yes |
| `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryReminderCron.php` | `myeventlane_boost:boost_expiring` | Boost expiring ~24h notice | Yes | Operational | No | Yes |
| `web/modules/custom/myeventlane_core/src/Service/CategoryDigestGenerator.php` | `myeventlane_core:category_digest` | Weekly category digest | Yes | Marketing | No | Yes |
| `web/modules/custom/myeventlane_event_attendees/src/Service/WaitlistNotificationService.php` | `myeventlane_event_attendees:waitlist_promotion` | Attendee waitlist promotion | Yes | Yes | No | Yes |
| `web/modules/custom/myeventlane_venue/src/Form/VenueIssueForm.php` | `myeventlane_venue:venue_issue_reported` | Venue issue report to admins | No (staff) | Operational | No | Yes |
| `web/modules/custom/myeventlane_escalations_portal/src/Service/EscalationMailer.php` | `escalation_*` (5 keys) | Escalation lifecycle emails | Yes | Operational | No | Yes |
| `web/modules/custom/myeventlane_escalations_policy/src/Service/PolicyActionHandler.php` | `myeventlane_escalations_policy:policy_review` | Weekly vendor policy review (staff) | No (staff) | Operational | No | Yes |
| `web/modules/custom/myeventlane_escalations_sla/src/Service/SlaEnforcer.php` | `myeventlane_escalations_sla:sla_breach` | SLA breach alert to site mail | No (staff) | Operational | No | Yes |
| `web/modules/custom/myeventlane_launch/src/Plugin/QueueWorker/ReliableEmailQueueWorker.php` | `myeventlane_launch:reliable_delivery` | Launch reliable mail queue | Yes | Yes | No | Yes |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/AutomationDispatchQueueWorker.php` | `myeventlane_automation:automation_*` | Legacy automation organiser/attendee reminders | Yes | Operational | No | Yes |
| `web/modules/custom/myeventlane_tickets/src/Service/TicketMailer.php` | `myeventlane_tickets:ticket_ready` | Assigned ticket PDF to holder | Yes | Yes | No | Yes |
| `web/modules/custom/myeventlane_rsvp/src/Service/VendorDigestGenerator.php` | `myeventlane_rsvp:vendor_digest` | Daily vendor RSVP digest | Yes | Operational | No | Yes |
| **Category C — mixed / orphaned / parallel** |
| `web/modules/custom/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php` | `myeventlane_pro:myeventlane_pro_abandoned_cart` | Fallback when messaging template disabled | Yes | Operational | Primary: Yes | Fallback: Yes |
| `web/modules/custom/myeventlane_automation/src/Plugin/QueueWorker/WeeklyDigestWorker.php` | (delegates to `CategoryDigestGenerator`) | Weekly digest dispatch | Yes | Marketing | Injected, unused for send | Yes (via digest generator) |
| `web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionSaver.php` | `mel_rsvp_*`, `rsvp_promotion` | Legacy RSVP mail | Yes | Yes | No | Yes |
| Parallel waitlist paths | `waitlist_invite` vs `waitlist_promotion` | Two modules, two transports | Yes | Yes | Partial | Partial |

---

## Category A — MessagingManager (future Postmark coverage)

All paths above call `MessagingManager::queue()`, `MessagingManager::sendMessage()`, or `OrderConfirmationQueueBuilder::queue()` which delegates to `MessagingManager::queue()`.

**Send chain when Postmark is wired:**

```text
Trigger → MessagingManager::queue() / sendMessage()
       → queue myeventlane_messaging
       → DeliveryProviderManager::getProvider()
       → PostmarkDeliveryProvider (when configured)
```

**Templates in sync config (40):** `config/sync/myeventlane_messaging.template.*.yml`

**Internal (not counted as product entry points):**

| File | Notes |
|------|-------|
| `MessagingQueueWorker.php` | Processes queue; calls `sendMessage()` |
| `MessagingCommands.php` | Drush test commands |
| `TemplateTestForm.php` | Admin template test UI |

---

## Category B — direct Drupal mail (not covered by Postmark provider switch)

These call `MailManagerInterface::mail()` (or Drupal core user mail) directly, bypassing `MessagingManager`.

**Shared transport today:** Mail System → Mime Mail → sendmail (`config/sync/system.mail.yml`).

**Critical launch gaps if only Option B is implemented:**

| Path | Risk |
|------|------|
| Drupal core user mail | Password reset, registration, account emails stay on sendmail |
| `TicketMailer` | Assigned ticket emails bypass messaging |
| `BoostExpiryCron` / `BoostExpiryReminderCron` | Boost expiry notices bypass messaging (separate from `boost_reminder` in Cat. A) |
| `ReliableEmailQueueWorker` | Launch reliable mail bypasses messaging |
| `EscalationMailer` | Support escalation emails bypass messaging |
| `CategoryDigestGenerator` / `VendorDigestGenerator` | Digest emails bypass messaging |

---

## Category C — unknown or mixed ownership

| Item | Finding | Evidence |
|------|---------|----------|
| `ProAbandonedCartJob` | Tries MessagingManager first; falls back to direct mail if template disabled | `ProAbandonedCartJob.php` lines 212–241 |
| `WeeklyDigestWorker` | Injects `MessagingManager` but sends via `CategoryDigestGenerator` (direct mail) | `WeeklyDigestWorker.php` lines 36, 89 |
| `RsvpSubmissionSaver` | Registered in services.yml but **no callers found** in repository | `myeventlane_rsvp.services.yml`; grep shows no injection |
| Waitlist promotion | **Two paths:** `WaitlistNotificationService` (direct) vs `WaitlistInviteWorker` / `TicketTierWaitlistOfferMailWorker` (messaging) | Separate modules |

---

## Postmark coverage estimate

**Methodology:** Count distinct production **entry-point files** (one row per file in master table, excluding internal messaging infrastructure and `DrupalMailProvider` adapter).

| Category | Files | Postmark when Option B wired? |
|----------|------:|:-----------------------------:|
| A | 26 | Yes |
| B (custom) | 12 | No |
| B (core user) | 1 | No |
| C (counted in A or B above) | — | Partial |

**Coverage:** 26 ÷ (26 + 12 + 1) = **26/39 ≈ 67%** of entry-point files.

**Critical transactional coverage (qualitative):**

| Covered by Option B | Not covered |
|---------------------|-------------|
| Order confirmation, invoice | Password reset, registration |
| RSVP confirmation | Core account cancellation |
| Refund lifecycle | Ticket assignment (`ticket_ready`) |
| Cart/abandonment (messaging templates) | Boost expiry cron emails |
| Event reminders (messaging/automation templates) | Escalation emails |
| Pro subscription failures | Launch reliable mail |
| Vendor event comms (messaging templates) | Category/vendor digests |

---

## Files involved

**Messaging hub**

- `web/modules/custom/myeventlane_messaging/src/Service/MessagingManager.php`
- `web/modules/custom/myeventlane_messaging/src/Service/Delivery/DeliveryProviderManager.php`
- `web/modules/custom/myeventlane_messaging/src/Service/Delivery/DrupalMailProvider.php`
- `web/modules/custom/myeventlane_messaging/src/Service/Delivery/PostmarkDeliveryProvider.php` (not wired)
- `web/modules/custom/myeventlane_messaging/myeventlane_messaging.services.yml`

**Mail transport config**

- `config/sync/system.mail.yml`
- `config/sync/mailsystem.settings.yml`
- `config/sync/mimemail.settings.yml`
- `config/sync/myeventlane_messaging.settings.yml`
- `config/sync/system.site.yml`
- `config/sync/user.mail.yml`
- `config/sync/user.settings.yml`

**All Category A/B/C caller files** — see master inventory table above.

---

## First failure point (ownership unclear)

**Earliest ambiguity:** parallel waitlist and boost email paths.

1. **Waitlist:** `WaitlistNotificationService` sends `waitlist_promotion` via direct mail while `WaitlistInviteWorker` and `TicketTierWaitlistOfferMailWorker` use MessagingManager templates (`waitlist_invite`, `ticket_tier_waitlist_offer`). Without runtime tracing, it is unclear which path handles a given waitlist scenario.

2. **Boost:** `BoostExpiryCron` / `BoostExpiryReminderCron` use direct mail (`boost_expired`, `boost_expiring`) while `BoostReminderScheduler` uses MessagingManager (`boost_reminder`). Expiry vs reminder are different triggers but overlapping product concern.

3. **`RsvpSubmissionSaver`:** appears orphaned — service registered, no injectors — legacy dead path unless invoked dynamically (not found in repo).

**Recommendation before Postmark cutover:** product/engineering confirmation of which waitlist and boost triggers are live in production.

---

## Search evidence

Repository searches performed:

- `plugin.manager.mail`, `MailManagerInterface`, `mailManager->mail(`
- `MessagingManager`, `myeventlane_messaging.manager`, `->queue(`
- `function *_mail(` in custom `.module` files
- `config/sync/myeventlane_messaging.template.*.yml` (40 templates)

No Drupal Postmark contrib module present in repository.
