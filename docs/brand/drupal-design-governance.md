# MEL Drupal Design Governance

**Version:** 1.0  
**Purpose:** Map the MEL Brand System to Drupal surfaces, components, and implementation rules.

This document governs **how brand principles apply in the MEL Drupal 11 codebase** without duplicating runtime SCSS contracts in `DESIGN_SYSTEM.md`.

---

## Governance principles

1. **Mobile first** — Design and accept at 390px baseline; enhance upward.
2. **WCAG AA** — All public surfaces meet contrast, focus, and touch target requirements.
3. **Component reuse** — Extend canonical theme components before creating new patterns.
4. **No duplicate UI patterns** — One event card, one button system, one hero contract on the public theme.
5. **Repository-first** — Do not invent fields, routes, or plugins; verify in codebase before implementation.
6. **Brand docs lead strategy** — `docs/brand/` defines intent; theme/module code implements it.

---

## Surface map

### Homepage

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Hero | Discovery promise, one primary CTA | Blocks and front module templates; locked hero variant per `DESIGN_SYSTEM.md` |
| Discovery rails | Hidden Gems, Tonight, categories | Views or custom block plugins; consistent card render mode |
| Guide placement | Max two guide moments | Rendered via block content or illustration library — Phase 2+ |
| Location | Nearby content prioritised | Geo or suburb filter integration — verify active implementation |

**Audit reference:** `docs/audits/brand-rollout/homepage-audit.md`

### Event cards

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Structure | Title, date, location, one badge, price | Event view modes; `myeventlane_theme` `_event-card.scss` |
| Badges | Approved list only | Field or computed badge service — criteria documented before enablement |
| Discovery language | Copy guidelines | String overrides in templates or translation |
| Mobile | Full-width single column | `.mel-grid--events` layout |

**Audit reference:** `docs/audits/brand-rollout/component-inventory.md`

### Browse pages

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Filters | Clear, non-intimidating | Views exposed filters; Helper Guide only if filters need explanation |
| Sort | Sensible defaults (date, nearby) | Document default sort in view config |
| Cards | Same as homepage | Shared view mode — no browse-specific card chrome |
| Empty states | Optimistic copy | Custom empty text in views or templates |

### Event detail pages

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Hero | Featured-style hero contract | `myeventlane_theme` event full/book templates |
| Belonging | Host Guide tone in supportive copy | Body field and organiser messaging |
| Badge | Max one on hero or header | Same badge rules as cards |
| CTA | Get tickets / RSVP primary | Commerce and RSVP modules — high-risk; no checkout bypass |
| Photography | Participation-focused gallery | Event media fields; vendor upload guidance |

**Audit reference:** `docs/audits/brand-rollout/event-page-audit.md`

### Blog

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Tone | Curious, local, optimistic | `myeventlane_front` blog templates |
| Imagery | Photography and illustration guidelines | Featured image field |
| Discovery | Link to relevant events where natural | Editorial workflow — manual linking |
| Cards | Distinct from event cards but shared tokens | Reuse spacing, radius, typography tokens |

### Help Centre

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Voice | Clear Helper Guide tone | Help content types; audience boundaries per workflow rules |
| Access | Public vs vendor vs staff separation | Route and search access — never leak staff playbooks |
| UI | Same tokens and components | Radix or custom help theme — verify active theme |

**Audit reference:** `docs/audits/brand-rollout/help-centre-audit.md`

### Event Studio (vendor)

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Onboarding | Helper Guide | Vendor dashboard modules and wizard |
| Public impact | Copy guidelines apply to public fields | Event title, description, imagery shown on public site |
| Tone | Professional, supportive | Distinct from consumer marketing but not cold enterprise |
| Components | Vendor theme aligns to token bridge where shared | `myeventlane_vendor_theme` — avoid divergent button/card systems long term |

**Audit reference:** `docs/audits/brand-rollout/onboarding-audit.md`, `docs/event-studio-section-contracts.md`

### Emails

| Aspect | Brand requirement | Drupal / theme notes |
|--------|-------------------|----------------------|
| Transactional | Celebrating Guide on success | Commerce email templates |
| Discovery digests | Curator Guide tone | Marketing modules — opt-in only |
| Imagery | Photography guidelines | Inline images; alt text required |
| Exclusivity | Avoided vocabulary enforced | Template review checklist |

**Audit reference:** `docs/audits/brand-rollout/email-audit.md`

---

## Component reuse hierarchy

When implementing brand changes, use this order:

1. **Existing theme component** — e.g. `.mel-card`, `.mel-btn`, `.mel-event-hero--featured-style`
2. **Token adjustment** — colours, spacing in `_tokens.scss` after `design-tokens.md` update
3. **Template copy change** — Twig string or translation only
4. **New variant of existing component** — requires design review
5. **New component** — requires brand and architecture review; document in component inventory

**Never** create a parallel event card or hero variant on the public theme without updating `DESIGN_SYSTEM.md`.

---

## Badge implementation governance

Before enabling a badge in Drupal:

1. Document eligibility criteria in this file or a linked ops doc.
2. Ensure one-badge-per-card rule in render logic.
3. Map badge to translation strings for Australian English.
4. Verify contrast for badge chip colours.

| Badge | Suggested ownership |
|-------|---------------------|
| Hidden Gem | Editorial flag or discovery score service |
| Community Favourite | Aggregated attendance/favourite metric |
| Trending Tonight | Time-boxed view or cron job |
| Editor’s Pick | Manual taxonomy or boolean field |
| Just Added | Created date threshold |
| Nearby | Location query context |

Exact field names and services must be verified in the repository before implementation — do not invent.

---

## Accessibility checklist (per change)

- [ ] Colour contrast AA for text and badges
- [ ] Focus visible on interactive cards and CTAs
- [ ] Touch targets ≥ 44×44px on mobile
- [ ] `prefers-reduced-motion` respected
- [ ] Meaningful alt text on images
- [ ] Heading hierarchy logical on page

---

## Cache and performance

Brand-related block and view changes must set appropriate cache contexts (e.g. user location), tags, and max-age. Do not disable caching to fix stale badges without review.

---

## Related documents

| Document | Role |
|----------|------|
| [mel-brand-system-v1.md](mel-brand-system-v1.md) | Principles |
| [design-tokens.md](design-tokens.md) | Tokens |
| `DESIGN_SYSTEM.md` | Locked theme contracts |
| [implementation-roadmap.md](implementation-roadmap.md) | Phased delivery |
| `docs/audits/brand-rollout/final-rollout-strategy.md` | Rollout analysis |
