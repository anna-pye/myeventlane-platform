# MEL Design System

## Scope
This lock applies only to the public MEL theme surface in `web/themes/custom/myeventlane_theme`.

This pass does not clean vendor, email, or Radix templates. It also does not attempt a repo-wide raw `px` migration.

## Canonical Token Source
The canonical runtime token bridge is `web/themes/custom/myeventlane_theme/src/scss/base/_tokens.scss`.

It must be imported first from `web/themes/custom/myeventlane_theme/src/scss/main.scss`.

The existing token architecture remains in place and is aligned to the bridge:
- Runtime CSS vars: `web/themes/custom/myeventlane_theme/src/scss/_tokens.scss`
- Sass colors: `web/themes/custom/myeventlane_theme/src/scss/tokens/_colors.scss`
- Sass spacing: `web/themes/custom/myeventlane_theme/src/scss/tokens/_spacing.scss`
- Sass radii: `web/themes/custom/myeventlane_theme/src/scss/tokens/_radii.scss`
- Sass shadows: `web/themes/custom/myeventlane_theme/src/scss/tokens/_shadows.scss`
- DS compatibility layer: `web/themes/custom/myeventlane_theme/src/scss/base/_mel-ds-tokens.scss`

Do not introduce a second token system.

## Hero Contract
Only one locked public hero variant is allowed:
- `.mel-event-hero--featured-style`

Canonical hero files:
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-hero.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-card.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-book.scss`

Required hero structure:
- `.mel-event-hero`
- `.mel-event-hero--featured-style`
- `.mel-event-hero__media`
- `.mel-event-hero__overlay`
- `.mel-event-hero__content`
- `.mel-event-hero__title`

Booking-specific glass treatment may live under `.mel-event-hero__glass`, but it must remain inside the featured-style hero path rather than creating a second hero variant.

Do not add new `mel-event-hero--*` variants on the public MEL surface.

## Card Contract
The canonical card base is `.mel-card` in `web/themes/custom/myeventlane_theme/src/scss/components/_cards.scss`.

Public event and booking styles may extend or specialize the card system, but must not redefine base card chrome in parallel files.

The shared card contract includes:
- Surface color from tokens
- Radius from tokens
- Shadow from tokens
- Shared header/body/footer spacing

Allowed card-related files:
- `web/themes/custom/myeventlane_theme/src/scss/components/_cards.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-card.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-book.scss`

If a page needs different content treatment, extend the canonical card classes instead of redefining `.mel-card`.

## Grid And Container Contract
The canonical grid primitive is `web/themes/custom/myeventlane_theme/src/scss/layout/_grid.scss`.

The canonical container definition is `web/themes/custom/myeventlane_theme/src/scss/layout/_container.scss`.

Shared layout rules must not be duplicated across page-specific files. Page-level overrides are only allowed where the public event/booking layout requires them.

Authoritative shared layout classes:
- `.mel-container`
- `.mel-grid`
- `.mel-grid--events`
- `.mel-grid--featured`
- `.mel-event-layout`

## Forms And Motion Contract
Canonical files:
- `web/themes/custom/myeventlane_theme/src/scss/base/_forms.scss`
- `web/themes/custom/myeventlane_theme/src/scss/utilities/_motion.scss`

Form controls must use the shared tokenized radius, border, and focus ring behavior.

Motion tokens must come from the MEL token bridge. Reduced-motion support must be preserved.

## CTA Rules
Canonical CTA/button entry point:
- `web/themes/custom/myeventlane_theme/src/scss/components/_buttons.scss`

Primary CTA, secondary CTA, ghost CTA, and public event CTA variants must all resolve through the shared button system.

Do not create page-local button systems for the locked public theme surface.

## Spacing Rules
Use token spacing from the existing Sass token modules and aligned CSS vars.

For this phase:
- Allowed: token spacing and existing aligned shared scales
- Not allowed in the locked files: ad-hoc `10px`, `18px`, `30px`, `50px`
- Not in scope: repo-wide conversion of all legacy raw `px` values outside the locked surface

## Twig Restrictions
For the public MEL theme templates in this pass:
- No structural inline `style` attributes
- No style definitions in Twig
- Keep semantic class structure aligned with the active event and booking templates

Confirmed active public templates for the locked surface:
- `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`
- `web/themes/custom/myeventlane_theme/templates/commerce/myeventlane-event-book.html.twig`

## Enforcement Workflow
Repo-managed enforcement lives in:
- `web/themes/custom/myeventlane_theme/.stylelintrc.json`
- `scripts/check-mel-hero-variants.mjs`
- `/.husky/pre-commit`
- Root scripts in `package.json`
- Theme scripts in `web/themes/custom/myeventlane_theme/package.json`

Current enforced checks:
1. `npm run mel:hero-check`
2. `npm run mel:lint`
3. `npm run mel:build`
4. `ddev drush cr`

The duplicate-hero guard fails if any public locked hero variant other than `.mel-event-hero--featured-style` appears in `src/scss/components/_event-hero.scss`.

Stylelint is intentionally scoped to the locked MEL surface first, not the entire legacy SCSS baseline.

## Change Discipline
When extending this system:
- Reuse the canonical files above
- Do not create parallel components
- Do not duplicate shared CSS
- Do not widen lint scope without cleaning the newly included surface first
- Do not edit the plan file that drove this lock pass
