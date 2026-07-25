# VX2-10 — Workspace Settings & Support surface inventory (Stage 1)

**Epic:** VX2-10 Settings & Support  
**Date:** 2026-07-24  
**Branch:** `feature/vx2-settings`  
**Authority:** MEL Product System · Vendor Experience Convergence · Screen Spec A10/A11

## Target IA

```text
/vendor/settings          Workspace Settings hub + Workspace Health
/vendor/settings/profile  Profile form (organisation, logo, contact, team, notifications)
/vendor/payments          Payments (deep link — no duplicate Stripe UI)
/vendor/settings/venues*  Venues library
/vendor/questions*        Guest questions library
/vendor/support           Support hub (Help entry + open requests + policies)
/help                     Help Centre
/help/organisers          Organiser help (canonical; /help/vendors → 301)
```

## Disposition table

| Surface | Path | Disposition | Notes |
| --- | --- | --- | --- |
| Organiser settings form | was `/vendor/settings` | **RENAME** + **MOVE** | Now hub at `/vendor/settings`; form at `/vendor/settings/profile` |
| Pro branding | `/vendor/settings/branding` | **KEEP** · hub deep-link | Under Settings · Brand |
| Messages brand | `/vendor/dashboard/messaging/brand` | **KEEP** · hub deep-link | Under Settings · Brand |
| Venues library | `/vendor/settings/venues*` | **KEEP** | Form placeholder **RETIRED** → link |
| Guest questions library | `/vendor/questions*` | **KEEP** · **RENAME** in UI | Settings · Guest Questions |
| Payments hub | `/vendor/payments` | **KEEP** | Settings · Payments deep-link only |
| Notification prefs (form) | profile `#notifications` | **MERGE** into Profile | Existing booking/RSVP/digest only |
| Inbox prefs | `/my-notifications/settings` | **KEEP** · link | Documented missing: SMS, quiet hours |
| Support list | `/vendor/support` | **KEEP** · **RENAME** chrome | Support hub theme |
| Support refunds | `/vendor/support/refunds` | **REDIRECT** | → `/vendor/payments#refunds` (D-H06) |
| Help Centre | `/help*` | **KEEP** | Settings · Help Centre |
| `/help/vendors` | legacy | **REDIRECT** 301 | → `/help/organisers` (D-L01) |
| Policies | `/help/policies`, `/terms`, `/privacy`, `/refund-policy` | **KEEP** | Settings · Policies |
| Event Studio settings/branding/questions | event-scoped | **KEEP** | Not global Settings |
| System status | — | **FUTURE** placeholder | Documented on Support hub only |

## Design debt closed in this epic

| ID | Resolution |
| --- | --- |
| D-H05 | Settings payment jargon stripped; Payments deep-link only |
| D-H06 | Support refunds redirects to Payments · Refunds |
| D-L01 | `/help/vendors` → `/help/organisers` |

## Stage 1B — Product consistency (genuine)

| Lens | Settings / Support finding |
| --- | --- |
| Cards / spacing | Hub uses `.mel-card` + `.mel-layout--form`; aligns with Payments/Messages hubs |
| Buttons | Primary per section; Danger Zone secondary |
| Forms | Profile moved to `/vendor/settings/profile`; hub is navigation, not a mega-form |
| Hierarchy | Workspace Health first, then section cards |
| Empty / loading | Health checklist covers incomplete states; Support open-requests empty is calm |
| Nav | Jump nav + shell Settings item; Support is its own shell product |
| A11y | Jump nav labelled; checklist SR prefixes; 44px targets; reduced motion in SCSS |

Residual inconsistency (not VX2-10 blockers): card header treatments still vary across live-ops vs hub cards (D-M02).

## Stage 1C — Design debt closed here

See Closed table (D-H05, D-H06, D-L01). No new Critical debt introduced.

## Stage 1D — Launch readiness (Settings slice)

| Area | Status |
| --- | --- |
| Settings hub | **NEEDS WORK** — spine Ready; manual QA / a11y sign-off open |
| Support hub | **NEEDS WORK** — same |
| Help Centre redirects | **READY** for language path (`/help/organisers`) |
| Policies links | **READY** |

## Stage 1E — Workspace Health pattern

Documented in `docs/product-system/MEL_UX_PATTERNS.md` §4A — Health → Issues → Recommended Action. Applied on Settings; mirrored by Payments / Analytics / Marketing hubs.

## Stage 1F — Maturity (Settings contribution)

| Dimension | Score | Note |
| --- | --- | --- |
| Product | 8.5 | One Settings + Support mental model |
| Design | 8.0 | Hub chrome consistent; visual QA open |
| Interaction | 8.0 | Jump nav + deep links; no unfinished toggles exposed |
| Accessibility | 7.5 | Patterns shipped; moderated pass open |
| Trust | 8.0 | Payments not duplicated; refunds owned by Payments |
| Launch confidence (Settings) | 7.5 | Enter Launch Polish with QA checklist |

## Explicit non-goals

- No second Stripe configuration UI
- No Commerce / Store / Gateway labels in organiser UI
- No new notification channels invented
- Event archive remains Event Settings Danger Zone
