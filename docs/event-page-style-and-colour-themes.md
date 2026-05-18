# Event page style and colour themes

## Product decision

- **MEL Classic** is the default public event page style for all vendors.
- **MEL Immersive** is a premium layout for organisers with Pro capability (or admin).
- Vendors choose a **safe colour preset** (no custom hex in this slice).
- Billing, checkout, and subscription purchase are **out of scope** here; capability is gated via a dedicated service seam.

## Classic vs Immersive

| | MEL Classic | MEL Immersive |
|---|-------------|----------------|
| Access | All vendors | Pro (`event_immersive_style`) or `administer nodes` |
| Feel | Warm, community-first, bright cards | Dark cinematic hero, bold contrast |
| Best for | Workshops, markets, community | Music, nightlife, launches |

## Fields (config/sync)

| Field | Machine name | Default |
|-------|----------------|---------|
| Event page style | `field_mel_page_style` | `classic` |
| Event theme colour | `field_mel_theme_colour` | `coral` |

Allowed style values: `classic`, `immersive`.  
Allowed colours: `coral`, `purple`, `mint`, `gold`, `blue`.

## Capability service

- **Service ID:** `myeventlane_event_studio.event_style_access`
- **Class:** `Drupal\myeventlane_event_studio\Service\EventStyleAccessManager`
- **Method:** `canUseImmersiveStyle(NodeInterface $event, AccountInterface $account): bool`
- **Pro feature key:** `event_immersive_style` (listed in `ProAccessService` gated features)
- **Admin bypass:** `administer nodes`
- Future: subscription state, boost purchase, or admin override can extend this service without changing Event Studio save paths.

## Public render resolver

- **Service ID:** `myeventlane_event_studio.event_page_style_resolver`
- **Class:** `Drupal\myeventlane_event_studio\Service\EventPageStyleResolver`
- Sanitizes stored values; builds modifier classes for Twig.
- **This slice:** public pages trust stored style when valid; Studio blocks unauthorized Immersive saves. When Pro expires, a future job should downgrade stored `field_mel_page_style` using entitlement state.

## Event Studio UX

- **Form:** `EventBrandingForm` (Branding workspace)
- **Probe:** “What feeling should this event page have?”
- **Present:** Classic and Immersive option cards (`#mel_option_cards`)
- **Listen:** Selected style + colour radios reflect current node values
- **Ask:** Save writes `field_mel_page_style` and `field_mel_theme_colour` via `EventStudioSaveService::saveBrandingHero()`
- **Invite:** Non-Pro vendors see Immersive disabled with upgrade copy (no billing link in this slice)
- **Validation:** Unauthorized Immersive submission returns a form error (not silent downgrade)

## Public CSS / classes

Applied on the full event `<article>` (single template: `node--event--full.html.twig`):

- `mel-event-page--classic` | `mel-event-page--immersive`
- `mel-event-page--colour-{coral|purple|mint|gold|blue}`

CSS variables per colour:

- `--mel-event-accent`
- `--mel-event-accent-soft`
- `--mel-event-accent-contrast`

SCSS: `web/themes/custom/myeventlane_theme/src/scss/components/_event-page-themes.scss`

## Accessibility

- WCAG-minded contrast on Immersive cards and CTAs
- Minimum **44px** tap targets on primary actions
- `prefers-reduced-motion: reduce` respected (no motion-heavy effects)

## QA checklist

- [ ] Vendor without Pro: Classic + colours save; Immersive locked; tampered POST rejected
- [ ] Pro vendor: Immersive saves and public page shows Immersive classes
- [ ] Admin: Immersive available
- [ ] Invalid style/colour values sanitize to classic/coral on public render
- [ ] Each colour preset updates CTA/accent on Classic and Immersive
- [ ] `npm run mel:lint` and `npm run mel:build` pass
- [ ] `config:status` clean after `drush cim`

## Non-goals (this slice)

- No duplicate event Twig templates per style
- No custom colour picker / arbitrary hex
- No billing or checkout for Pro upgrade
- No stock, warehouse, shipping, scanner, QR, entitlement, cart, or order changes

## Future work

- Persist event-level entitlement when subscription/boost ends and downgrade stored style
- Cross-link: prior audit notes in `docs/event-page-style-and-colour-audit.md` when present on branch
