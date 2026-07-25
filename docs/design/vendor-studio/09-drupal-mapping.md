# Vendor Studio — Drupal Mapping

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Map Design Operating System concepts to the existing **Drupal 11 + Commerce 3** structure so implementation extends the right homes.

## Scope

Architecture mapping only — **no implementation in this pack**. Paths and plugin IDs below are **targets or confirmed homes** based on repository conventions. If a specific template name cannot be confirmed at implementation time, discover it in-repo — do not invent.

## Audience

Technical Authority, theme engineers, module developers.

## Related documents

- [02-information-architecture.md](02-information-architecture.md)
- [03-layout-system.md](03-layout-system.md)
- [05-component-library.md](05-component-library.md)
- [11-design-tokens.md](11-design-tokens.md)
- [10-roadmap.md](10-roadmap.md)
- [README.md](README.md) — governance

---

## Why mapping matters

Design without Drupal homes creates parallel Twig/SCSS trees. Mapping keeps Vendor Studio maintainable and Commerce-safe.

---

## 1. Runtime homes

| Concern | Location |
| --- | --- |
| Organiser console theme | `web/themes/custom/myeventlane_vendor_theme` |
| Public MEL theme (do not fork ops UI here) | `web/themes/custom/myeventlane_theme` |
| Vendor domain / routing / builders | `web/modules/custom/myeventlane_vendor` (+ related `myeventlane_*` modules) |
| Config sync | `config/sync` (export only when intentionally changing config) |
| Brand tokens (docs) | `docs/brand/design-tokens.md` |
| Studio tokens (docs) | [11-design-tokens.md](11-design-tokens.md) |
| Layout convergence authority | `docs/implementation/vx2-02a-workspace-layout-convergence.md` |

Theme base: `stable9`. Body/console scoping: `.mel-vendor`.

---

## 2. Theme regions → design chrome

| Design region | Theme region (`myeventlane_vendor_theme.info.yml`) |
| --- | --- |
| Sidebar global nav | `sidebar` |
| Header left / centre / right | `vendor_header_left`, `vendor_header_center`, `vendor_header_right` |
| Main workspace | `content` |
| Help panels | `sidebar_help` |
| System messages | `highlighted` |
| Footer zones | `vendor_footer_*` |

---

## 3. Layout intents → SCSS / classes

| Design intent | Class (product) | Token / SCSS home |
| --- | --- | --- |
| Form | `.mel-layout--form` | `--mel-layout-form` · `layout/_container.scss` · tokens spacing |
| Reading | `.mel-layout--reading` | `--mel-layout-reading` |
| Workspace | `.mel-layout--workspace` | `--mel-layout-workspace` |
| Dashboard | `.mel-layout--dashboard` | `--mel-layout-dashboard` |
| Marketing / wide | `.mel-layout--wide` / `--marketing` | `--mel-layout-wide` |
| Shell tokens | `.mel-vendor` | `_root-tokens.scss` |

**Rule:** Twig applies classes; SCSS owns widths. No hardcoded max-widths in templates. Numbers: [11](11-design-tokens.md). Decision: [DDR-003](decisions/DDR-003-layout-intents.md).

---

## 4. Design language → tokens / partials

| Design concept | Likely SCSS / token surfaces |
| --- | --- |
| Colour / surface | `tokens/_colors.scss`, `_root-tokens.scss` |
| Typography | `tokens/_typography.scss` |
| Shadows | `tokens/_shadows.scss` |
| Spacing / gutters | `tokens/_spacing.scss` (and root layout vars) |
| Buttons | `components/_buttons.scss` |
| Forms | `base/_forms.scss`, `components/_forms.scss`, `components/_form.scss` |
| Cards / panels | `components/_cards.scss` |
| Badges | `components/_badges.scss` |
| Alerts | `components/_vendor-alert.scss` |
| Empty states | `components/_empty-states.scss` |
| Navigation shell | `layout/_navigation.scss` |
| Workspace sheets | `workspace.scss`, page partials under `pages/` |

---

## 5. Components → Drupal building blocks

| Component | Twig / theme | Data / logic | Library (examples in `*.libraries.yml`) |
| --- | --- | --- | --- |
| Workspace Hero | Console / workspace header Twig | Preprocess / section view models | `vendor-workspace`, page libraries |
| Metric Cards | Card Twig partials | Dashboard / analytics builders | `dashboard`, `analytics` |
| Action Cards / Task Lists | List/alert Twig | `VendorActionQueueBuilder` (and successors) | `dashboard` |
| Data Tables | Table Twig / Views templates | Views or custom builders | page-specific |
| Forms / Inputs | Form API theme suggestions | Form classes / workspace section forms | `form-protection`, `event-form`, wizard libs |
| Buttons | Shared btn partials | Render `#type` => link/submit | `global` |
| Badges | Status partial | Field formatters / view model flags | `global` |
| Charts | Analytics Twig | Analytics services | `chartjs`, `analytics` |
| Notifications | Messenger + custom | JS behaviours | `mel_notifications` |
| Help panels | `sidebar_help` + support Twig | Help Centre services (organiser audience) | support / help libraries |
| Door Mode UI | Check-in templates | Attendee / check-in modules | `mel-checkin` related SCSS/JS |

Exact template filenames vary by surface — locate via theme registry / module templates at implementation time.

---

## 6. IA destinations → routing patterns

Product path patterns (from VX2 IA; confirm in `*.routing.yml` before changing):

| IA item | Path pattern |
| --- | --- |
| Dashboard | `/vendor/dashboard` |
| Events | `/vendor/events` |
| Event Workspace | `/vendor/events/{id}` · `/vendor/events/{id}/{section}` |
| Create | `/vendor/events/create` (and established create gateways) |
| Orders / Attendees / Messages / Payments / Analytics / Marketing / Settings / Support | `/vendor/{area}` hubs |

Nav assembly: `VendorNavBuilder` (or successor) → sidebar render array → theme.

**Access:** Workspace ownership and permissions remain server-side. Design never treats UI absence as security.

---

## 7. Preprocess, theme hooks, render arrays

| Design need | Drupal mechanism |
| --- | --- |
| Attach layout class per page | Preprocess `page` / specific controllers setting `#attributes` |
| Primary CTA in header | Render array variables to header Twig |
| Action queue | Builder service → page variable → Twig |
| Section body in Event Workspace | Workspace section plugins / controllers → embed form or view |
| Cache correctness | Cache contexts (user, route, vendor workspace) + tags on entities |
| Assets | `#attached` libraries; avoid global JS on unrelated routes |

Business rules stay in services/forms — **not** Twig.

---

## 8. Commerce boundaries

| Organiser concept | Commerce reality (hidden) |
| --- | --- |
| Ticket | Product variation / `mel_ticket_type` abstractions |
| Order | Commerce order |
| Payment / payout | Payment gateway + Stripe Connect account state |
| Refund | Commerce/Stripe refund flows |

Design copy uses organiser language ([15](15-copywriting-guide.md)). Implementation keeps Commerce entities and states authoritative. UI must not claim paid/refunded until state confirms.

---

## 9. Libraries strategy

| Principle | Practice |
| --- | --- |
| Global shell | `global` + `vendor-workspace` |
| Heavy page JS | Attach only on routes that need it (`analytics`, `dashboard`, wizard, notifications) |
| No exploit-laden “admin” scripts | Follow MEL security rules |
| Build pipeline | Vendor theme Vite/SCSS via repo `npm run mel:lint` / `mel:build` when implementing |

---

## 10. What this pack must not change (until a coded phase)

- Twig, SCSS, PHP, JS, YAML config — unchanged by documentation-only work
- Checkout flows, payment gateways, order ownership
- Role permissions (`user.role.vendor` etc.) without explicit access review
- Help audience boundaries

---

## Design implications

- Implementation PRs update an “Implemented mapping” note under the relevant phase when code lands
- Prefer extending existing partials over parallel `vendor-studio-*` component trees ([DDR-004](decisions/DDR-004-component-philosophy.md))

## Future considerations

- Confirm template filenames in-repo at coding time — do not invent
- Dark mode token remap homes at Phase 12

## Related references

- [02](02-information-architecture.md) · [03](03-layout-system.md) · [05](05-component-library.md) · [11](11-design-tokens.md) · [10](10-roadmap.md) · [16](16-design-review-checklist.md)
