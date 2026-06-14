# Final Rollout Strategy — The Hidden Gem + The Guide (Bright Edition)

**Audit date:** 2026-06-14
**Branch audited:** `feature/event-studio-consolidation`
**Status:** Audit-only synthesis. **No implementation performed.** Every recommendation traces to a phase document with repository evidence.

> Companion documents (all in `docs/audits/brand-rollout/`):
> `theme-architecture.md` · `component-inventory.md` · `homepage-audit.md` · `discovery-audit.md` · `event-page-audit.md` · `help-centre-audit.md` · `email-audit.md` · `onboarding-audit.md` · `brand-assets-audit.md` · `surface-boundary-audit.md` · `mobile-audit.md`

---

## Executive summary

The Bright Edition / "Hidden Gem + The Guide" territory can be rolled out with **high reuse and low risk** because MEL already contains the structural ingredients:

1. **One token source of truth** — `myeventlane_theme/src/scss/base/_tokens.scss` (`:root`) drives the whole public UI. Already a bright coral/cream/periwinkle palette. *(theme-architecture.md)*
2. **A discovery-first homepage** — region/block/View section stack with featured, recommended, tonight, free, nearby, online rails. *(homepage-audit.md)*
3. **A recommendation engine** — `EventRecommendationService` attaches human-readable "why" reasons to event cards today. *(event-page-audit.md)*
4. **A grounded AI assistant that already suggests events** — `myeventlane_help_assistant` + `EventSuggestionEngine`. The basis for "Ask the Guide." *(help-centre-audit.md)*
5. **A branded email shell + queue/cron digest pipeline + Postmark** — basis for Weekly Hidden Gems / Guide Recommendations / Community Digest. *(email-audit.md)*
6. **A post-login hub with a built-in recommendation slot** — the prime Guide entry point. *(onboarding-audit.md)*
7. **A clean public/vendor boundary enforced at theme negotiation** — the Guide's territory is unambiguous. *(surface-boundary-audit.md)*
8. **A mascot + documented (mostly empty) asset pipeline** — art drops into existing slots. *(brand-assets-audit.md)*

**The rollout is predominantly: re-value tokens + re-voice copy + populate art slots + place 2–3 existing components + re-brand the existing AI assistant.** It is *not* a re-architecture.

---

## Existing Assets To KEEP (do not touch)

| Asset | Evidence |
|---|---|
| `myeventlane_vendor_theme` token system, regions, console widgets (operational workspace) | theme-architecture §4, surface-boundary §2🟠 |
| `myeventlane_admin` / Gin back-office | theme-architecture §5 |
| Domain-based theme negotiation (`VendorThemeNegotiator`, `AdminThemeNegotiator`) | theme-architecture §2 |
| Commerce/cart/checkout/ticket/RSVP business logic, access, payment state | CLAUDE.md, event-page §4 |
| AI guardrails, circuit breaker, prompt-hash governance | help-centre §5 |
| Postmark webhook/bounce handling, mailsystem routing, unsubscribe compliance | email-audit §1,§7 |
| Auth/OAuth/token flow, Stripe Connect onboarding, post-login *redirect decision logic* | onboarding §6 |
| Search API index config; View query/access layer | discovery §4 |
| 215 vendor routes + 102 admin routes (operational/back-office) | surface-boundary §5 |

## Existing Assets To EVOLVE (re-value / re-voice / re-skin — keep structure)

| Asset | Action | Evidence |
|---|---|---|
| `:root` `_tokens.scss` + SCSS color abstract | Re-value to Bright Edition palette | theme-architecture §3, §8 |
| Homepage section headings/subtitles + hero copy | Re-voice to discovery-wonder | homepage §5 |
| Event card, hero, site header, category pills/chips | Token re-skin + Guide badge potential | component-inventory §B |
| Related-events rail + recommendation reason labels | Re-voice to Guide tone | event-page §3 |
| `myeventlane_help_assistant` surface | Re-brand → "Ask the Guide" + Guide prompts | help-centre §6 |
| `mel-email-base.html.twig` + transactional subjects | Re-skin logo/accent + Guide voice | email-audit §2,§7 |
| Post-login hub copy + recommendation | Guide welcome + first gem | onboarding §1,§5 |
| Empty-state copy | Guide nudges | onboarding §4 |
| Mascot, logos, heroes, value icons | Bright Edition art | brand-assets §6 |
| `mel_maintenance` error-page token block | Re-skin (brand-visible) | theme-architecture §6 |

## Existing Assets To REMOVE / RESOLVE (debt to clear before/with re-skin)

| Item | Action | Evidence |
|---|---|---|
| Duplicate empty-state vocab (`.mel-empty` vs `.mel-empty-state` vs `--listing`) | Consolidate to one | component-inventory §E |
| Duplicate button/card ownership (public vs vendor vs `mel-*`) | Confirm canonical owner before re-skin | component-inventory §C,§E |
| Legacy `scss/` dir (`scss/auth-pages.scss`, `scss/components/_event-hero.scss`) | Confirm dead → retire | theme-architecture §3 |
| Duplicate `VendorThemeNegotiator` (vendor module vs core) | Confirm dead → retire | theme-architecture §2 |
| Possibly-broken `/events/nearby`, `/events/online` links | Validate / build displays | discovery §1, homepage §2 |
| `myeventlane_radix` (Bootstrap 5 migration) | **DECIDE**: adopt or shelve — do not invest Bright Edition work until decided | theme-architecture §1,§8 |

---

## Phased rollout plan

### Phase 1 — Quick Wins (copy + 2 block placements; days)
*Goal: feel the new territory with near-zero risk.*
- Re-voice homepage section headings/subtitles + hero to discovery-wonder. *(homepage §5)*
- Re-voice "Recommended for you" and related-events headers to the Guide. *(event-page §3)*
- Place the **Vibe Mixer** block in a homepage region (config + existing component). *(component-inventory §B, homepage §4)*
- Add a curated **"Hidden Gems"** `upcoming_events` display + place as a homepage rail. *(discovery §3)*
- Re-voice empty states. *(onboarding §4)*
- **Risk:** minimal (copy/config). No token, Commerce, or access changes.

### Phase 2 — Brand Layer (token re-skin + art; 1–2 weeks)
*Goal: the Bright Edition visual identity.*
- Re-value `_tokens.scss` `:root` + SCSS color abstract; rebuild Vite. *(theme-architecture §3,§8)*
- Re-skin `mel-email-base` (logo/accent) + `mel_maintenance` error tokens. *(email §2, theme-architecture §6)*
- Swap logos (4 SVG + email PNG); populate documented empty art slots (events/search/category heroes, empty-state). *(brand-assets §3,§6)*
- Evolve mascot → **The Guide** character (single pose today → pose/expression set). *(brand-assets §2,§6)*
- **Pre-work:** resolve duplicate vocab/ownership debt to avoid drift. *(component-inventory §E)*
- **Risk:** low-moderate; visual regression testing recommended; mobile token re-skin is near-zero layout risk. *(mobile §4)*

### Phase 3 — Guide System (re-brand existing AI + recommendation surfacing; 2–4 weeks)
*Goal: the Guide persona becomes interactive.*
- Re-brand `myeventlane_help_assistant` → **"Ask the Guide"**; add Guide-persona `PromptDefinition`s; optionally widen grounding from help articles to discovery data. *(help-centre §5,§6)*
- Surface `EventRecommendationService` "why" reasons in Guide voice on cards + related rails. *(event-page §2,§3)*
- Wire the **post-login hub** recommendation slot to a Guide welcome + first gem. *(onboarding §1)*
- Optional: attendee interest/vibe capture step (reuse Vibe Mixer) to seed recommendations. *(onboarding §3,§5)*
- **Risk:** moderate; respect AI guardrails/cost; **team decision**: Guide scope (support-only vs support+discovery) and AI provider/model (config-level; current default `gpt-4o-mini` via OpenAI-compatible provider). *(help-centre §7)*

### Phase 4 — Discovery System (promote editorial/recommendation; 2–4 weeks)
*Goal: shift from browse-first to Guide-curated discovery.*
- Promote editorial/recommendation rails above generic browse rails on homepage. *(discovery §3)*
- Connect Vibe Mixer chips to `upcoming_events` exposed/contextual filters. *(discovery §3)*
- Add event-page "more gems like this" carousel slide / "more from this host" rail (reuse patterns). *(event-page §3, mobile §3)*
- Validate/build `/events/nearby`, `/events/online`. *(discovery §1)*
- **Risk:** moderate; keep Search API/View access untouched; mobile = in-flow rails, **no new fixed bars**. *(mobile §3,§4)*

### Phase 5 — Marketing Rollout (Guide email programs; 2–3 weeks)
*Goal: the Guide reaches inboxes.*
- **Weekly Hidden Gems** — clone Category Digest pipeline (queue+cron+base template) with a Hidden Gems source. *(email §4,§6)*
- **Guide Recommendations** — feed `EventRecommendationService`/`EventSuggestionEngine` output into the `mel-email-base` marketing slot. *(email §6)*
- **Community Digest** — re-brand existing category digest + cadence. *(email §6)*
- Opt-in via notification preferences. *(email §5)*
- **Risk:** low (reuses Postmark + pipeline); respect unsubscribe/compliance. *(email §7)*

### Phase 6 — Launch Readiness (hardening; 1–2 weeks)
*Goal: ship safely.*
- Cross-surface QA against `surface-boundary-audit.md` — confirm **no Guide persona leaked into vendor/admin** (215+102 routes). *(surface-boundary §5)*
- Mobile regression: sticky-collision check on event/cart/checkout; touch targets; mobile hero art weight. *(mobile §4)*
- Accessibility: contrast on new tokens, focus states, reduced-motion preserved (CLAUDE.md). *(mobile §1)*
- Email render testing (Postmark) across clients; transactional emails unbroken. *(email §3)*
- Config export (`drush cex`) for new blocks/Views/displays; cache rebuild; Vite build. *(CLAUDE.md commands)*
- Confirm debt items resolved or consciously deferred (Phase 2C breakpoints non-blocking). *(mobile §4)*

---

## Risk register (cross-cutting)

| Risk | Severity | Source | Mitigation |
|---|---|---|---|
| Guide persona leaks into vendor/admin | High (brand integrity) | surface-boundary | Scope by domain theme negotiation; QA gate Phase 6 |
| Mobile sticky-element collision | Medium | mobile §3,§4 | In-flow Guide content only; no new fixed bar |
| Token re-skin visual regressions | Medium | theme-architecture | Visual diff testing; tokens are centralized (contained blast radius) |
| Duplicate component ownership drift | Medium | component-inventory | Resolve canonical owners in Phase 2 pre-work |
| AI cost/safety on "Ask the Guide" | Medium | help-centre | Existing guardrails + circuit breaker; cadence caps |
| `myeventlane_radix` ambiguity | Medium (wasted effort) | theme-architecture | **Decide before Phase 2**: target stable9 theme or Radix |
| Broken nearby/online discovery links | Low | discovery | Validate in Phase 4 |
| Empty documented art slots | Low | brand-assets | Populate in Phase 2 |

---

## Open decisions requiring the team (not resolvable from repo)

1. **`myeventlane_radix`**: adopt as future public theme, or shelve? Determines Bright Edition target. *(theme-architecture §8)*
2. **Guide scope**: support-only vs support + discovery answers. `EventSuggestionEngine` shows the latter is partly built. *(help-centre §6)*
3. **AI provider/model**: keep OpenAI-compatible default, or change? Config-level, not architectural. *(help-centre §7)*
4. **Bright Edition palette values**: the audit confirms *where* tokens live and that re-valuing them re-skins the app; the actual hex values are a design decision. *(theme-architecture §3)*
5. **Mascot direction**: evolve the single existing mascot into the Guide, or new character system? *(brand-assets §2)*

---

## Bottom line

MEL is **structurally ready** for the Hidden Gem + The Guide territory. The discovery homepage, recommendation engine, grounded AI assistant, digest email pipeline, post-login recommendation slot, branded email shell, and a clean public/vendor boundary already exist. The rollout is **re-value + re-voice + populate + re-brand**, sequenced from zero-risk copy wins to a token re-skin to surfacing the Guide — with the vendor console and admin deliberately untouched. Maximum reuse, minimum risk, exactly as scoped.
