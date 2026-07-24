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

## Explicit non-goals

- No second Stripe configuration UI
- No Commerce / Store / Gateway labels in organiser UI
- No new notification channels invented
- Event archive remains Event Settings Danger Zone
