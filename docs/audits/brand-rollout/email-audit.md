# Email System Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based.

---

## 1. Delivery architecture (evidence)

`myeventlane_messaging` is the email backbone.

| Component | Evidence | Role |
|---|---|---|
| Provider manager | `src/Service/Delivery/DeliveryProviderManager.php` | Selects delivery provider |
| **Postmark provider** | `src/Service/Delivery/PostmarkDeliveryProvider.php` | Primary transactional sender |
| Drupal mail fallback | `src/Service/Delivery/DrupalMailProvider.php` | Fallback |
| Provider interface | `DeliveryProviderInterface.php` | Abstraction (swap-safe) |
| **Postmark inbound webhook** | `tests/.../PostmarkWebhookControllerTest.php`, `myeventlane_messaging.routing.yml` | Delivery/bounce events |
| Message storage | `src/Service/MessageStorage.php` | Message persistence |
| Settings | `src/Form/MessagingSettingsForm.php` | Provider config |
| Contrib | `composer.json` → `drupal/mailsystem ^4.5` | Mail routing |

**Postmark is integrated** (provider + webhook + tests). Provider is abstracted behind `DeliveryProviderInterface`.

---

## 2. Branded email shell — **the brand asset**

`web/modules/custom/myeventlane_messaging/templates/email/mel-email-base.html.twig`:

| Brand property | Value (evidence) |
|---|---|
| Accent | `accent_color \| default('#f26d5b')` — **the MEL coral**, matches the public `:root` token |
| Page bg | `#fef5ec` (warm cream — matches brand) |
| Text / muted | `#293241` / `#5c6370` |
| Card | `#ffffff`, `border-radius:16px` |
| Font | `'Nunito', Arial, …` |
| CTA | table-based **pill** button, `border-radius:9999px`, accent bg |
| **Marketing promo slot** | `marketing.promo_title / promo_body / promo_url / promo_button` block already built in |
| Logo | `logo_path` (140px) with `site_name` fallback |
| Footer | unsubscribe URL, accent bottom bar |
| Patterns documented | `mel-h1` (24/800), `mel-h2` (18/700), `mel-cta` (centered pill) |

> The branded email shell is already coral/cream/Nunito and **already has a marketing-promo block** — i.e. a built-in slot for Guide recommendations. Bright Edition email work is largely a **token/asset re-skin of one base template** plus a logo swap.

---

## 3. Transactional / lifecycle emails (evidence)

| Program | Templates / services |
|---|---|
| **RSVP** | `myeventlane_rsvp/templates/`: `email-rsvp-confirmation`, `email-confirmation`, `email-vendor-copy`, `email-rsvp-waitlist`, `email-rsvp-promotion`, `email-rsvp-cancel`, `mel-rsvp-confirmation-email` |
| **Tickets** | `myeventlane_tickets/src/Service/TicketMailer.php` |
| **Waitlist promotion** | `myeventlane_event_attendees/templates/email-waitlist-promotion.html.twig` |
| **Vendor digest** | `myeventlane_rsvp/templates/mel-vendor-digest-email.html.twig` + `VendorDigestGenerator` |
| **Auth** | `myeventlane_auth` hook_mail; theme `mimemail-message--user--register-no-approval-required.html.twig` |

---

## 4. Digest / marketing infrastructure — **already exists**

`myeventlane_core` ships a complete automated **Category Digest** pipeline:

| Component | Evidence |
|---|---|
| Generator | `src/Service/CategoryDigestGenerator.php` |
| **Queue** | `src/Queue/CategoryDigestQueue.php` |
| **Cron** | `src/Service/CategoryDigestCron.php` |
| Template | `templates/myeventlane-category-digest-email.html.twig` |
| Wiring | `myeventlane_core.module` |

This is a **scheduled, queue-driven, category-targeted digest email system** — the exact infrastructure the new programs need. Subscription/opt-out exists via the notification preferences system (§5) + `mel-email-base` unsubscribe URL.

---

## 5. In-app notifications (related, not email)

`myeventlane_notifications`: full notification system — `NotificationTaxonomy`, `NotificationDomain`, `NotificationSurface`, `NotificationContext`, `NotificationFilter`, inbox + bell templates, **`NotificationPreferencesForm`**, mark-read forms. Provides the **subscription/preference layer** Guide email programs can hang off.

---

## 6. What's required for the three new Guide email programs

| Program | Reuses (evidence) | Net-new work |
|---|---|---|
| **Weekly Hidden Gems** | `CategoryDigestGenerator` + `CategoryDigestQueue` + `CategoryDigestCron` + `mel-email-base` + Postmark | A "Hidden Gems" content source (curated View/term — see `discovery-audit.md`); a new digest template variant (clone of category-digest); a weekly schedule entry; preference opt-in |
| **Guide Recommendations** | `mel-email-base` **marketing block** + `EventRecommendationService` (Phase 5) + `EventSuggestionEngine` (Phase 6) + Postmark | Wire recommendation output into the marketing/promo slot; cadence + preference; Guide voice/copy |
| **Community Digest** | **Category digest IS effectively a community digest** + cron/queue + base template | Re-voice as "Community Digest"; choose cadence; preference opt-in. Largely a re-brand of an existing program. |

**No new delivery, queue, or cron architecture is required.** All three programs are variants on the existing Category Digest pipeline + the marketing block in the base template, fed by the existing recommendation services.

---

## 7. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | Postmark provider + webhook, `DeliveryProviderManager`, `mel-email-base` shell, Category Digest queue/cron/generator, notification preferences, all transactional templates |
| **NEEDS EVOLUTION** | Re-skin `mel-email-base` to Bright Edition (logo, accent token); re-voice subjects/copy to Guide tone; clone digest template for Hidden Gems |
| **ADD (reuse pipeline)** | Hidden Gems content source; Guide Recommendations cadence wiring; Community Digest re-brand; weekly schedule entries |
| **DON'T TOUCH** | Postmark webhook/bounce handling, mail routing (mailsystem), unsubscribe/compliance plumbing |

**Bottom line:** Email is one of the **strongest reuse stories** in the audit. A branded shell with a marketing slot, Postmark delivery, and a queue+cron digest pipeline already exist. The three Guide email programs are **content sources + cadence + copy + re-skin on top of existing infrastructure** — not new systems.
