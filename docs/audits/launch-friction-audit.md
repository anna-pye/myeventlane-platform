# Launch Friction Audit

**Date:** 2026-06-24  
**Scope:** Visitor, customer, and organiser journeys — copy, empty states, duplicate CTAs, mobile IA, terminology, trust gaps.  
**Method:** Repository inspection of routes, templates, governed copy (`MelReadinessHelper`, `MelCustomerContinuityPresenter`), and cross-reference with `docs/audits/workflow-experience-audit.md`. No invented routes, fields, or services.

---

## Prompt review (pre-implementation)

| Risk | Mitigation |
|------|------------|
| 300-line / 15-file cap vs broad audit | Document all journeys; implement highest-impact copy + duplicate CTA fixes only |
| Terminology pass touching machine names | Change only `$this->t()` / Twig `\|t` strings; no route/permission/config renames |
| Duplicate “Create event” CTAs | Dashboard empty panel owns primary CTA; secondary empty states defer to shell header or single button |
| Help centre Organiser vs Vendor paths | Both routes remain; public IA consolidates to “Organiser help” label |
| Checkout / Stripe / Commerce | Out of scope — already governed via `MelCustomerContinuityPresenter` |
| Accessibility | Preserve landmarks, `aria-*`, 44px targets; copy-only changes |
| Deployment | String changes only — cache rebuild sufficient; no config import |

---

## Phase 1 — Journey friction map

### Visitor

| Screen | Primary action | Friction found | Severity |
|--------|----------------|----------------|----------|
| Homepage | Find an event | Multiple host CTAs (“Create event” in header + footer + hero) — acceptable for acquisition; discovery rails are clear | Low |
| Search | Pick a result | Group label “Vendors” vs brand “Organisers”; empty hint uses “vendors” | Medium |
| Calendar | Open an event | Empty states use governed browse recovery (`mel-browse-empty-recovery`) | Low |
| Event page | Book / RSVP | Sticky CTA + sidebar can duplicate; sticky is primary on mobile — by design | Low |

### Customer

| Screen | Primary action | Friction found | Severity |
|--------|----------------|----------------|----------|
| RSVP | Confirm spot | Governed thank-you via `MelCustomerContinuityPresenter` | Low |
| Ticket purchase | Complete checkout | Commerce steps unchanged (high-risk) | — |
| Confirmation | View booking / calendar | Primary CTA → `/my-tickets/order/{id}` (implemented) | Resolved |
| My Tickets | View booking | “View booking” label; governed empty state wired | Low |
| My Events (`/my-events`) | Browse when empty | Governed empty via `GovernedOperationalTemplates::customerMyEventsDashboardUpcomingEmpty()` | Resolved |
| My Account nav | Tickets / saved | Nav item “Dashboard” for `/my-account` — ambiguous vs organiser Home | Medium |

### Organiser

| Screen | Primary action | Friction found | Severity |
|--------|----------------|----------------|----------|
| Create event | Start draft | Gateway + header + footer CTAs — expected for acquisition | Low |
| Publish | POST publish | Stripe gate copy exists in readiness helper | Low |
| Dashboard (0 events) | Create first event | Hero duplicated empty message + CTA — **fixed**: dedicated empty panel, hero CTA removed | Resolved |
| Dashboard (has events) | Operate current / next event | Current-event surface gated to in-session events; next-event panel when idle | Resolved |
| Attendees | Share / export | Governed empty slots (`vendorAttendeeOperations*`) | Low |
| Insights | View performance | Labels still mixed “Analytics” / “View analytics” in places | Medium |

---

## Phase 2 — Duplicate actions

| Duplicate | Location | Resolution |
|-----------|----------|------------|
| Create event in dashboard hero + empty table | `dashboard.html.twig` | Empty panel owns CTA; hero no longer repeats empty copy |
| Organiser help + Vendor help cards | `/help` home, `HelpCentreController` IA | Consolidate public label to Organiser help; remove redundant card |
| Dashboard vs Home (organiser) | Sidebar group, organiser context block | Align to “Home” (matches `VendorNavBuilder`) |
| View analytics links | Event performance cards | Rename to “View insights” |

**Intentionally kept:** Header “Create event” (public acquisition), shell header primary action on events list empty state.

---

## Phase 3 — Dead ends

| State | What happened | What it means | Next step | Status |
|-------|---------------|---------------|-----------|--------|
| No events (organiser) | No published drafts | Ready to publish first event | Create event (empty panel) | Resolved |
| No tickets (customer) | No orders | Bookings appear after checkout | Browse events | Governed |
| No upcoming (My Events) | No RSVP/ticket rows | Same account email ties guest checkout | Browse events | Governed |
| No attendees | No rows yet | Event is live | Share event link | Governed |
| No performance data | Events exist, no views yet | Insights accumulate after traffic | Create/share (when no events) | Copy improved |
| Placeholder Studio sections | Section deferred | Feature roadmap | Studio empty state builder | Documented only |

---

## Phase 4 — Mobile friction

| Area | Finding |
|------|---------|
| Bottom nav | Events + Account tabs — primary discovery reachable in one thumb |
| Mobile drawer | Secondary: Organisers, Support, Help — no duplicate browse |
| Account menu | Overlay sheet; ticket link in quick nav when logged in |
| Event CTA | Sticky booking bar on event page |
| Organiser dashboard | Collapsible utility `<details>` for insights/account — reduces scroll; tertiary sections de-emphasised |

**Residual:** Account dropdown on desktop requires hover — keyboard/focus-within supported.

---

## Phase 5 — Terminology inventory

| Machine / route (unchanged) | Target user language | Action this pass |
|-----------------------------|---------------------|------------------|
| `myeventlane_vendor.console.dashboard` | Home | Sidebar group, organiser context block |
| `myeventlane_account.dashboard` | Account | Customer hub nav |
| Analytics modules / Studio section | Insights | Studio section plugin, task tab, performance links |
| Promotion readiness row | Homepage promotion | Studio workspace presentation label |
| Vendor entity (public) | Organiser | Search group title |
| Vendor help route | Organiser help | Help centre copy |

**Not changed:** Admin/staff “Vendors”, Stripe Dashboard, entity labels in config, route paths.

---

## Phase 6 — Trust gaps (copy-only)

| Gap | Improvement |
|-----|-------------|
| Checkout confirmation | Governed hero + organiser trust band (`MelCustomerContinuityPresenter`) |
| Order status on My Tickets | `state_customer_presentation` shown |
| Empty organiser dashboard | Explicit “You're ready to publish your first event.” panel |
| Help centre dual organiser paths | Single public card label |
| Event Studio default title | “Event Studio” instead of “Vendor workspace” |

**Deferred (needs product, not copy):** Pro-gated insights upsell, placeholder manage-event routes, checkout flow config drift.

---

## Changes applied (this pass)

See git diff. Summary:

1. Customer account nav: Dashboard → Account  
2. Organiser context + sidebar group: Dashboard → Home  
3. Shell default title: Vendor workspace → Event Studio  
4. Search: Vendors → Organisers (group + empty hint)  
5. Help centre: remove duplicate Vendor help card; Organiser help labelling  
6. Studio: Promotion → Homepage promotion; Analytics section → Insights  
7. Event performance / table: View insights, Grow event labelling  

**Branch context:** Dashboard empty panel, next-event surface, tertiary section hierarchy were already on `feature/mel-public-visitor-navigation`.

---

## Validation

```bash
git status --short
composer validate --check-lock
ddev drush cr
npm run mel:lint
npm run mel:build
find web/modules/custom web/themes/custom -name "*.php" -print0 | xargs -0 -n1 php -l
```

---

## Manual QA checklist

**Desktop:** event page, organiser dashboard, My Tickets  
**Mobile:** event page, bottom nav + drawer, organiser dashboard  
**States:** no events, published event, sold out, no attendees, booking complete  

---

## Remaining friction (follow-up)

| Priority | Item |
|----------|------|
| P2 | Remove or hide placeholder manage-event routes from IA |
| P2 | Pro insights upsell copy when `/vendor/analytics` locked |
| P3 | Align checkout flow config (`mel_event_checkout`) in sync |
| P3 | Consolidate attendee entry URLs in help docs only |
| P4 | `myeventlane_dashboard` legacy template “Vendor Dashboard” title (admin module) |

---

## Related audits

- `docs/audits/workflow-experience-audit.md`
- `docs/audits/mel-booking-checkout-verification.md`
- `docs/audits/event-trust-conversion-audit.md`
- `docs/audits/mobile-phase-1-priority-review.md`
