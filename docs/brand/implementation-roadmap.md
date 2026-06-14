# MEL Brand Implementation Roadmap

**Version:** 1.0  
**Purpose:** Phased plan to implement the MEL Brand System from documentation through marketing.

Each phase completes before the next begins unless explicitly parallelised by product approval. **Phase 1 is complete** with the creation of `docs/brand/`.

---

## Phase 1 — Brand documentation

**Status:** Complete (v1.0)

**Deliverables:**

- `docs/brand/` document set (strategy, tokens, guides, surfaces, governance)
- `.cursor/rules/mel-brand-system.mdc` Cursor governance
- Asset directory structure under `docs/brand/assets/`

**Exit criteria:**

- All brand docs reviewed by product and design stakeholders
- No conflict with `DESIGN_SYSTEM.md` hero and card contracts
- Reading order published in `docs/brand/README.md`

**Validation:**

```bash
find docs/brand -type f
```

---

## Phase 2 — Guide asset system

**Goal:** Produce and catalogue guide illustrations for five archetypes.

**Deliverables:**

- Explorer, Host, Curator, Helper, Celebrating guide artwork in `docs/brand/assets/guide/`
- Usage sheet per archetype (placement, max size, colour variants)
- SVG or optimised PNG suitable for theme library attachment

**Dependencies:** Phase 1 approved illustration and guide-character guidelines.

**Exit criteria:**

- All five guides available in brand assets
- Contrast-checked on Warm Cream and Soft Sky backgrounds
- No mascot naming or childish styling

---

## Phase 3 — Discovery illustration system

**Goal:** Hidden Gem and discovery motif library.

**Deliverables:**

- Discovery illustrations in `docs/brand/assets/discovery/`
- Empty-state UI illustrations in `docs/brand/assets/ui/`
- Map pin, neighbourhood, and “near you” visual vocabulary

**Dependencies:** Phase 2 guide style established.

**Exit criteria:**

- Discovery assets usable in homepage and browse empty states
- Consistent with `illustration-guidelines.md`

---

## Phase 4 — Homepage refresh

**Goal:** Align homepage hierarchy, copy, and rails to brand system.

**Deliverables:**

- Hero copy and CTA per `homepage-system.md`
- Hidden Gems and Tonight rails with correct badges
- Guide placement (max two moments)
- Mobile-first validation at 390px

**Dependencies:** Phases 1–3 for illustration; may start copy-only earlier.

**Technical touchpoints:**

- Drupal blocks/views for homepage
- `myeventlane_theme` homepage templates and SCSS
- Audit against `docs/audits/brand-rollout/homepage-audit.md`

**Exit criteria:**

- Homepage matches documented hierarchy
- No duplicate card patterns introduced
- WCAG AA spot check passed

---

## Phase 5 — Event card refresh

**Goal:** Unified card structure, badges, and discovery language across surfaces.

**Deliverables:**

- Badge rendering with one-badge rule
- Card copy patterns per `event-card-system.md`
- Shared view mode for homepage, browse, search, related events

**Dependencies:** Phase 4 homepage rails; badge criteria documented in `drupal-design-governance.md`.

**Technical touchpoints:**

- `web/themes/custom/myeventlane_theme/src/scss/components/_event-card.scss`
- Event view modes and badge fields/services

**Exit criteria:**

- Cards identical across discovery surfaces
- Approved badges only
- Mobile card audit passed

---

## Phase 6 — Email refresh

**Goal:** Align transactional and discovery emails to brand voice and visuals.

**Deliverables:**

- Order confirmation and ticket email templates — Celebrating Guide tone
- Discovery digest template — Curator Guide tone
- Photography and illustration usage per guidelines

**Dependencies:** Phases 2–3 assets optional but recommended.

**Technical touchpoints:**

- Commerce email templates
- Marketing email modules

**Audit reference:** `docs/audits/brand-rollout/email-audit.md`

**Exit criteria:**

- Avoided vocabulary removed from templates
- Alt text on hero images
- Mobile-readable email layout spot check

---

## Phase 7 — Marketing system

**Goal:** Extend brand to social, campaigns, and external touchpoints.

**Deliverables:**

- Social templates in `docs/brand/assets/social/`
- Logo usage pack in `docs/brand/assets/logo/`
- Campaign copy cheat sheet derived from `copy-guidelines.md`
- Paid social and partner co-brand rules

**Dependencies:** Phases 1–6 stable public experience.

**Exit criteria:**

- Asset library complete for marketing self-service
- Hidden Gem and Guide strategy consistent off-platform
- No exclusivity-led campaign framing

---

## Cross-phase rules

| Rule | Applies to all phases |
|------|------------------------|
| Mobile first | Yes |
| WCAG AA | Yes |
| Component reuse | Yes |
| No duplicate UI patterns | Yes |
| Australian English | Yes |
| Repository verification before Drupal changes | Yes |

---

## Reporting

After each phase, report:

- What changed (files and surfaces)
- Validation commands run and results
- Residual risks (cache, Commerce, access, config drift)

Do not skip verification per `mel-workflow-verification.mdc`.
