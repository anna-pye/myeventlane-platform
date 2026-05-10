# Product language governance — MyEventLane

This document defines **canonical customer- and organiser-facing language** after the harmonisation pass, what **must stay legacy internally**, and **rules for future copy**.

---

## Canonical product language

| Legacy (public copy) | Canonical (public copy) |
|----------------------|-------------------------|
| Vendor (user-facing) | Organiser |
| Vendor Dashboard | Organiser Dashboard |
| Vendor Settings | Organiser Settings |
| Event Workspace | Event Manager |
| Studio (product UX) | Event Editor |
| Escalation (customer/organiser UX) | Support / Support request |
| Boost Event / Boost (promotional UX) | Promote event |

---

## Customer terminology

- **Discover** — browsing events (`/events` from the account hub; storefront discovery may use other entry points).
- **Tickets** — purchased or assigned tickets (`My Tickets` retired in hub labels).
- **Saved events**, **Categories**, **Organisers** — saved discovery preferences.
- **Support** — human help; individual items are **support requests**, not “escalations”.
- **Notifications**, **Settings** — unchanged concepts; labels kept concise.

Single source for hub navigation order and labels: `AccountLinksService::buildNavigationItems()`.

---

## Organiser terminology

- **Organiser dashboard**, **Organiser settings** — console chrome.
- **Event Manager** — per-event workspace shell (`event_workspace` route title).
- **Event Editor** — studio / editor experience (`/vendor/studio`, event editor routes, Events submenu).
- **Ticket holders** — replaces “Attendees” in console navigation for consistency with ticketing (RSVP-specific screens may still say RSVP).
- **Check-in** — ticket check-in tool; surfaced when event context + access permit.
- **Promote event** — paid visibility/boost flows.
- **Messaging** — organiser messaging brand settings entry.
- **Support** — `/vendor/support` escalation portal (presentation name only).

Single source for console sidebar structure: `_myeventlane_vendor_theme_build_full_vendor_shell_nav_items()` in `myeventlane_vendor_theme.theme`.

---

## Staff / internal terminology

Staff tools, Drupal admin, analytics, and risk systems **may continue to say**:

- **Vendor** (entity, Connect, ownership)
- **Escalation** (case workflow, SLA, breach language)
- **Boost** (internal product/module naming)

Do **not** reuse internal abbreviations or machine names in customer-facing sentences.

---

## Navigation hierarchy

### Customer hub (sidebar)

Discover → Tickets → Saved events → Categories → Organisers → Support → Notifications → Settings  

### Organiser console (sidebar)

Dashboard → Events → Event Editor → Orders → Ticket holders → Check-in → Payouts → Promote event → Messaging → Analytics → Support → Organiser settings  

(Event-scoped items may appear disabled until an event context exists on the route.)

---

## CTA standards

- **One primary action** per region; verb-first (“Submit support request”, “New support request”).
- Avoid fear-based wording; prefer **clear next steps** (“When you contact us, your conversations will appear here”).
- Promotional actions use **Promote event**, not “Boost Event”, in organiser-facing UI.

---

## Empty state standards

- Replace Drupal-default **“No results”** / **“No content”** with short, **action-oriented** guidance when touching a surface.
- Do not remove operational meaning (e.g. permission or filtering context).
- No marketing fluff; maintain **screen reader clarity**.

---

## Breadcrumb standards

- Breadcrumb text should match **route `_title`** and **shell headings** where breadcrumbs are derived from titles.
- Avoid exposing internal route machine names or admin paths in labels.

---

## AI language standards

- Customer and organiser assistants should say **organiser**, **ticket holder** (where relevant), **support request**, **Event Editor**, **Promote event**.
- Prompt templates (`config/sync/myeventlane_ai.prompt.*`) may be updated for **wording only**; do not widen retrieval scope or remove grounding rules.
- Staff-only prompts retain operational vocabulary (**escalation**, **vendor**) when accuracy depends on it.

---

## Help Centre language standards

- Public Help articles may keep **Attendee** sections as-is unless editorially consolidated.
- Do not expose staff playbooks or internal escalation mechanics in customer help.

---

## Support terminology rules

| Context | Term |
|---------|------|
| Customer organiser-facing UI | Support, Support request |
| Staff / PCC / SLA tooling | Escalation (allowed) |
| URLs | Unchanged (`/my/support/escalations/*`, `/vendor/support/*`) |

---

## Future copy governance rules

1. **No mass search/replace** — change strings where the surface is owned (service, route title, template).
2. **Do not rename** modules, services, routes, permissions, or entities for copy reasons alone.
3. **Preserve access checks** — navigation is presentation; routing and `_custom_access` stay authoritative.
4. **Translation-safe** — user-visible strings use `t()` / `TranslatableMarkup`.
5. **Document drift** — when adding a new hub link, update `AccountLinksService` or vendor shell builder first, then menus/templates.

---

## What changed in this pass (summary)

- Customer hub IA order + labels (`AccountLinksService`, account headings).
- Organiser shell nav order, labels, and active-section mapping (`myeventlane_vendor_theme.theme`).
- Route `_title` harmonisation for key console and support routes (`myeventlane_vendor.routing.yml`, `myeventlane_escalations_portal.routing.yml`).
- Support portal presentation strings (controllers + customer form).
- Help Assistant fallback + suggestion labels; organiser AI prompt config; boost confirmation email footer line.
- Minor storefront/mobile/chrome Twig (`radix` header, PCC utility bar, vendor sidebar aria).

---

## What intentionally remains legacy

- Machine names: `myeventlane_vendor`, `escalation`, `vendor_*` permissions, `/vendor` paths.
- Admin Views and entity field labels using “Vendor” or “Attendee” where editor-facing or historical.
- Internal logs and access denial reason strings aimed at developers.

---

## What must never be exposed publicly

- Staff playbook content, internal SLA breach messaging, raw escalation levels on customer pages.
- Internal route names or module paths in user-visible headings or AI replies.
- Unsanctioned PII in support or assistant copy.
