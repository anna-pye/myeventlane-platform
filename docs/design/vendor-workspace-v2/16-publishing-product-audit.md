# Vendor Workspace v2 — Publishing Product Audit

**Status:** Product discovery + audit (Sprint 3A/3B) — documentation only  
**Date:** 2026-07-25  
**Authority:** PDS 13 · 14 · 15 · Workspace docs `02` · `08` · `10` · `14` · runtime discovery `15`  
**Frozen:** Mission Control — do not redesign; Launch Centre sits **below** it in page hierarchy when on Publishing section.

---

## Part A — Organiser journey (current)

Goal of Publishing Experience: answer **“Am I ready to launch my event?”** with confidence — not administer a CMS publish flag.

### Journey map

```text
Draft → Incomplete (Needs attention) → Ready → Publishing (in flight)
  → Live → Sharing → Editing live → Returning later
  → Past / Closed → (Unpublish) → Draft again
  → Future scheduling: NOT SUPPORTED (node-level)
```

| Stage | What organiser experiences today | Runtime evidence |
| --- | --- | --- |
| **Draft** | Event unpublished; Hero CTA **Continue setup** → Publishing section; Mission Control lists blockers | `resolveAuthoritativePrimaryCta` when `!$readiness->ready` |
| **Incomplete** | Publishing hub: “A few things left…” + checklist ○ items + Settings form + Publish card (often disabled / blocked) | `buildPublishingHub` |
| **Ready** | Hub: “Ready to publish”; Hero primary becomes **Publish** (AJAX); Mission Control may hint “Use Publish in the header” | CTA key `publish`; shell JS publish mode |
| **Publishing** | Button `is-publishing` state during fetch; guards for dirty/stale/autosave | `EventStudioPublishController` + shell JS |
| **Live** | Status live; Hero → **Share**; hub “Your event is live”; unpublish ghost button; celebrate/handoff optional | `buildPublishSuccessHandoff`; panels `live` |
| **Returning later** | Autosave drafts; Continue setup resumes; last-saved in topbar | Autosave + topbar `lastSaved` |
| **Editing live** | Section saves; “Updates publish when you save” copy on live panel | `EventStudioPublishForm` live panel |
| **Sharing** | Share CTA → Marketing workspace; handoff social links; Boost CTA optional | Marketing route; handoff `share` |
| **Unpublishing** | Supported via shell AJAX; legacy confirm form exists but redirected | `action: unpublish` |
| **Closing / Past** | Event date past; still “Share” primary unless readiness regresses — **weak lifecycle language** on Publishing section | Lifecycle model `08` vs hub copy |
| **Future scheduling** | **Absent** — cannot schedule node publish for later | Discovery `15` §13 |

### Pain points

| ID | Pain | Severity |
| --- | --- | --- |
| P1 | Publishing section embeds **full Settings form** (visibility + publish) — feels like admin, not Launch Centre | MAJOR |
| P2 | **Competing Publish affordances**: Hero primary + card “Publish now” + Mission Control narrative (MC frozen but still present on Home) | MAJOR on Publishing page |
| P3 | Checklist is raw error strings + ✔ markup — weak visual hierarchy / fix links | MAJOR |
| P4 | Success is thin: messenger “Your event is live” / handoff panel — not a guided “what next” Launch success | MAJOR |
| P5 | Legacy redirects land on **Settings**, not Publishing — confusing recovery paths | MINOR |
| P6 | Stripe / organiser denials can feel duplicated | MINOR |
| P7 | Past/closed events still framed as publish/share without aftermath guidance | MINOR |
| P8 | No scheduled publish — organisers may expect “go live at” | Known gap (product decision) |
| P9 | Mobile: Settings + checklist + card stack = scroll fatigue; sticky CTA helps but page body remains dense | MAJOR @390 |
| P10 | Terminology: Publishing / Settings / Studio / publish flag — mixed mental models | MINOR |

### Duplicate actions

- Hero **Publish** vs card **Publish now** (`data-mel-publish-action` / `data-mel-card-publish-action`) — both hit same AJAX endpoint (good technically; bad product competition).
- Unpublish on card vs Settings danger zone (vendor theme settings page).
- Share after live: Hero Share + handoff share + Marketing section.

### Confusing terminology

| Term | Risk |
| --- | --- |
| Publishing vs Settings | Two sections; publish UI lives in both (hub embeds settings form) |
| Ready vs Published | Product “Ready” ≠ Drupal published |
| Live vs On sale | Lifecycle `08` richer than hub headlines |
| Cannot publish yet | Accurate but anxious — prefer “Finish setup to launch” |

### Unnecessary decisions

- Visibility controls on the same screen as first-time launch (should be secondary / progressive).
- Boost offered immediately in success path without calm “share first” primacy (Boost OK as secondary).

### Missing feedback

- No progressive “X of Y ready” confidence meter on Publishing (Mission Control has checklist; hub list is flatter).
- Failed publish messages exist (422/409/500) but recovery CTAs uneven.
- After unpublish: little guidance on “you’re draft again — here’s what that means for guests”.

### Mobile friction

- Dense vertical stack: headline → checklist → visibility fields → publish card.
- Touch targets generally use `.mel-btn` (PASS if preserved).
- Sticky Hero CTA helps; body still asks for multiple decisions.

### Accessibility concerns

- Publish panels use `hidden` + `aria-hidden` (good).
- Checklist as `item_list` of markup strings — weak semantics vs proper list of links with status.
- Celebrate/handoff must announce via `aria-live` (shell feedback region — verify in implementation sprint; design requires it in `19`).
- Competing focusables (Hero + card Publish) — keyboard users get two primaries.

---

## Part B — Product audit ratings

Scale: **PASS** · **MINOR** · **MAJOR**

| Area | Rating | Notes |
| --- | --- | --- |
| Information hierarchy | **MAJOR** | Settings form dominates Launch intent |
| Visual hierarchy | **MAJOR** | Checklist + form + card compete equally |
| Navigation | **PASS** | Section exists; shell frozen; legacy Settings redirect is MINOR |
| CTA ownership | **MINOR** | Authoritative resolver exists; UI still duplicates Publish controls |
| Feedback | **MINOR** | AJAX states exist; copy/structure underplay confidence |
| Loading | **PASS** | `is-publishing` button state |
| Errors | **PASS** | Fail-loud codes + messages; reasons from eligibility |
| Success | **MAJOR** | Handoff exists but not Launch-success experience |
| Confidence | **MAJOR** | Checklist present but buried in admin chrome |
| Trust | **PASS** | Server-side eligibility; no Twig gate bypass |
| Discoverability | **MINOR** | Continue setup → Publishing works; Settings redirect muddies |
| Calm / one narrative | **MAJOR** | Fails Launch Centre philosophy |
| Mission Control coexistence | **PASS** | MC frozen; Publishing must not steal Home narrative |
| Accessibility | **MINOR** | Foundation OK; dual primary + weak checklist semantics |
| Mobile 390 | **MAJOR** | Density / decision overload |

### Overall product verdict

**Not ready as Launch Centre.**  
Technical publish path is solid. Product composition fails the five-second confidence test on the Publishing section itself.

| Question | Today |
| --- | --- |
| Am I ready to launch? | Partially — checklist exists but framed as admin |
| What should I do next? | Split between Hero, MC (Home), and card |
| What happens when I publish? | Under-explained until after |
| What next after live? | Thin share/boost |

---

## Part C — Design principles for Launch Centre (audit → design)

1. **One narrative:** Ready to Launch → checklist → one control → aftercare.
2. **One primary action:** Mirror `resolveAuthoritativePrimaryCta` — never two Publish buttons.
3. **Settings ≠ Launch:** Visibility / danger zone move to Settings or progressive disclosure.
4. **Reuse readiness truth:** Facade / `EventReadinessService` only — no new calculator.
5. **Respect Mission Control freeze:** Do not redesign MC; Launch Centre is the Publishing section composition.
6. **Calm confidence:** Australian English; no FOMO; honest blockers with fix links.
7. **Success is a journey beat:** Guided share, not fireworks spam.

---

## Outstanding product questions (for PO)

1. Should visibility (public / private / passcode) remain on Launch Centre as a collapsed “Who can find this?” band, or Settings-only?
2. Is scheduled publish in scope for v2 Launch Centre, or explicitly deferred?
3. After publish, is **Share** always primary, or should paid events with zero tickets sold elevate **View public page** briefly?
4. Unpublish confirmation: inline modal on Launch Centre vs Settings-only destructive action?

---

**Recommendation seed:** Design docs `17`–`21` assume visibility secondary, scheduled publish deferred, Share primary after live, unpublish confirmed secondary.
