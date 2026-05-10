# Product language inventory

This document supports the **product language + navigation harmonisation** pass (branch `feature/product-language-harmonisation`). It records **where terminology surfaces**, **who sees it**, and **what was harmonised** versus **intentionally unchanged internals**.

## Method

Sources inspected (non-exhaustive scan plus targeted edits):

- `web/modules/custom/**/*.routing.yml` — `_title` / `title` where customer or organiser-facing
- `web/modules/custom/myeventlane_account/src/Service/AccountLinksService.php` — customer hub sidebar / dropdown source of truth
- `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` — organiser console shell navigation
- `web/themes/custom/myeventlane_vendor_theme/templates/**/*.twig` — shell chrome, studio CTAs
- `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` — main menu preprocess copy
- `web/themes/custom/myeventlane_radix/templates/includes/header.html.twig` — mobile discovery labels
- `web/modules/custom/myeventlane_escalations_portal/**` — customer + organiser support UI
- `web/modules/custom/myeventlane_help_assistant/**` — assistant fallback + suggestion labels
- `config/sync/myeventlane_ai.prompt.vendor_ai_answer_v1.yml` — organiser AI prompt wording
- `config/sync/myeventlane_messaging.template.boost_confirmation.yml` — transactional email copy

## Classification key

| Column | Meaning |
|--------|---------|
| **Surface** | Where the string appears |
| **Audience** | customer · organiser · staff · mixed |
| **Canonical?** | Whether this string follows post-harmonisation product language |
| **Replace?** | Whether we changed visible copy in this pass |

## Sample inventory table

Representative rows (not every translated string in the codebase):

| String | Surface | Audience | Canonical? | Replace? |
|--------|---------|----------|-------------|----------|
| Organiser dashboard | Route `_title`, sidebar aria-label | organiser | Yes | Yes (was “Vendor dashboard”) |
| Event Manager | Route `_title` `event_workspace` | organiser | Yes | Yes (was “Event workspace”) |
| Event Editor | Route `_title` studio routes; shell nav; Events submenu | organiser | Yes | Yes (submenu was “Event builder”) |
| Promote event | Boost shell `_title`, nav label, studio CTA, suggestions | organiser | Yes | Yes (was “Boost” / “Boost Event”) |
| Ticket holders | Vendor shell nav | organiser | Yes | Yes (was “Attendees”) |
| Check-in | Vendor shell nav (event-scoped) | organiser | Yes | Yes (new IA slot; disabled without event context) |
| Support | Customer + organiser support routes `_title` | customer, organiser | Yes | Yes (was “My Support” / “Vendor Support”) |
| Support request(s) | Escalation portal UI, forms, status messages | customer, organiser | Yes | Yes (was “escalation” phrasing) |
| Discover | Customer hub nav (`AccountLinksService`) | customer | Yes | Yes (new; links to `/events`) |
| Tickets | Customer hub nav label | customer | Yes | Yes (was “My Tickets”) |
| Organisers | Customer hub nav (`Followed organisers` shortened); radix mobile label | customer | Yes | Yes |
| Vendor | Entity machine labels, permissions, admin config | staff / system | N/A (internal) | **No** — entity/API names unchanged |
| Escalation | Route names, entity types, staff modules, access reasons | staff / system | N/A (internal) | **No** — presentation only elsewhere |
| Attendee | Help Centre views (`mel_help_attendee_help`), paragraph types | mixed | Partial | **No** per brief — Help retains “attendee” where scoped |
| Boost | Module IDs, config keys, commerce internals | system | N/A | **No** — product-facing labels harmonised only |

## Customer navigation (canonical order)

Implemented via **`AccountLinksService::buildNavigationItems()`** definition order for sidebar-visible items:

1. Discover  
2. Tickets  
3. Saved events  
4. Categories  
5. Organisers  
6. Support  
7. Notifications  
8. Settings  

Dashboard remains in the service for route matching / dashboard context but **`show_in_sidebar` is FALSE** so the hub sidebar matches the IA above. Log out is **`show_in_sidebar` FALSE**.

## Organiser navigation (canonical order)

Implemented via **`_myeventlane_vendor_theme_build_full_vendor_shell_nav_items()`**:

1. Dashboard  
2. Events (submenu: **Event Editor** when an event context exists)  
3. Event Editor (global `/vendor/studio`)  
4. Orders (enabled when an event context exists on the route match)  
5. Ticket holders  
6. Check-in (enabled when an event context exists and access allows)  
7. Payouts  
8. Promote event  
9. Messaging  
10. Analytics  
11. Support (`/vendor/support`)  
12. Organiser settings  

**Refund requests** remain **event-scoped** and append when the refunds module and permissions allow (same behaviour as before; not in the 12-item headline list).

**Audience** and **Notifications** were removed from the organiser **sidebar** list in favour of the canonical IA; notifications remain available via the existing header bell pattern.

## Empty states touched

| Location | Before | After |
|----------|--------|-------|
| Customer support list (`CustomerEscalationController`) | “You have no support escalations yet.” | Calm, actionable empty copy referencing future conversations |

Further empty-state harmonisation (Views, generic Drupal tables) is **out of scope** for this single pass unless product assigns priority surfaces.

## Residual legacy language (intentional)

- Paths such as `/vendors`, `/vendor/*`, route names (`myeventlane_vendor.*`), permissions (`view vendor escalations`), entity type `myeventlane_vendor`
- Staff/admin Views and PCC labels that reference operational terms (“Vendor orders”, admin queues)
- Help Centre installs that retain **Attendee** audience naming per brief

## Follow-up candidates (not done here)

- Broad sweep of Twig templates for “No results found” / Drupal-default empties  
- Main navigation YAML (`system.menu.*`) if menu content overrides storefront labels independently of preprocess  
- RSVP-specific routes still titled “RSVP Check-In” — evaluate alignment with “Check-in” for ticketed paths only  
