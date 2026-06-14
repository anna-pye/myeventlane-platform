# MEL Brand System

**Brand name:** MyEventLane (MEL)  
**Version:** 1.0  
**Status:** Source of truth for brand strategy, voice, visual language, and Drupal design governance

---

## Strategy at a glance

MEL is built around two complementary ideas:

1. **Hidden Gem** — surfacing experiences people did not know existed nearby. Discovery is the product promise, not a marketing tagline.
2. **Guide** — a human, encouraging presence that helps people explore, join, and feel welcome. Guides are not mascots; they are tone, placement, and illustration direction.

Together, Hidden Gem + Guide shape how MEL looks, reads, and behaves across the public site, vendor tools, email, and marketing.

---

## Reading order

Read documents in this order when onboarding to the brand system or starting implementation work:

| Order | Document | Purpose |
|-------|----------|---------|
| 1 | [mel-brand-system-v1.md](mel-brand-system-v1.md) | Primary reference — vision, pillars, frameworks, principles |
| 2 | [mel-brand-strategy.md](mel-brand-strategy.md) | Condensed strategy — promise, positioning, emotional outcomes |
| 3 | [design-tokens.md](design-tokens.md) | Colour, type, spacing, radius, shadow, motion tokens |
| 4 | [guide-character-system.md](guide-character-system.md) | Guide archetypes, phrases, usage rules |
| 5 | [homepage-system.md](homepage-system.md) | Homepage hierarchy, hero, discovery patterns |
| 6 | [event-card-system.md](event-card-system.md) | Card structure, badges, discovery language |
| 7 | [copy-guidelines.md](copy-guidelines.md) | Preferred and avoided language, tone examples |
| 8 | [photography-guidelines.md](photography-guidelines.md) | Photo direction and exclusions |
| 9 | [illustration-guidelines.md](illustration-guidelines.md) | Illustration direction and exclusions |
| 10 | [drupal-design-governance.md](drupal-design-governance.md) | How brand maps to Drupal surfaces |
| 11 | [implementation-roadmap.md](implementation-roadmap.md) | Phased rollout plan |

---

## Source of truth

**`docs/brand/` is the authoritative brand documentation for MyEventLane v2.**

- Brand strategy, voice, visual language, and governance live here.
- Runtime implementation contracts (SCSS tokens, Twig components, locked hero variants) remain in repository implementation files such as `DESIGN_SYSTEM.md` and `web/themes/custom/myeventlane_theme/`.
- Brand audits and rollout analysis live in `docs/audits/brand-rollout/` and inform implementation but do not override this brand system.

When brand documentation and implementation diverge, treat `docs/brand/` as the strategic target and open a scoped implementation task to align code — do not silently reinterpret brand rules in theme or module code.

---

## Asset directories

Brand assets are organised under `docs/brand/assets/`:

| Directory | Contents |
|-----------|----------|
| `guide/` | Guide character illustrations and usage variants |
| `discovery/` | Hidden Gem and discovery illustration assets |
| `ui/` | UI pattern references and annotated screenshots |
| `photography/` | Approved photography direction and reference images |
| `social/` | Social and marketing asset templates |
| `logo/` | Logo files and usage specifications |

Assets are added in later implementation phases. See [implementation-roadmap.md](implementation-roadmap.md).

---

## Cursor governance

Agents and contributors working on brand-adjacent UI should follow `.cursor/rules/mel-brand-system.mdc`, which references this directory.
