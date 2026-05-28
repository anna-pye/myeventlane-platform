# AGENTS.md — MyEventLane / MEL AI Agent Rules

This file defines safe operating rules for AI coding agents working on MyEventLane v2, known as MEL.

MEL is a Drupal 11 event marketplace and ticketing platform using Drupal Commerce 3, custom Drupal modules, custom Twig/SCSS/Vite themes, Commerce checkout flows, vendor dashboards, event discovery, booking UX, media workflows, and Humanitix-level product expectations.

## Required agent behaviour

All agents must optimise for:

1. Stability
2. Accuracy
3. Security
4. Drupal 11 best practice
5. Drupal Commerce 3 best practice
6. Long-term maintainability
7. MEL continuity
8. Clean UX
9. Production readiness

## Repository source of truth

The current MEL repository is the source of truth.

Agents must inspect the repository before making claims about:

- File paths
- Module names
- Theme names
- Routes
- Permissions
- Services
- Plugins
- Field names
- Config keys
- Product types
- Product variation types
- Checkout panes
- Order item types
- Libraries
- Build scripts

If the repository does not confirm something, the agent must say:

```text
I cannot confirm this from the repository.
```

## Allowed work

Agents may assist with:

- Drupal 11 custom module code
- Drupal Commerce 3 modelling and checkout review
- Twig templates
- SCSS components
- Vite/theme build work
- YAML config and schema
- UX microcopy
- Vendor dashboard improvements
- Event discovery UX
- Booking flow improvements
- Access-control review
- Security review
- Test suggestions
- Documentation
- GitHub issue and PR drafting

## Restricted work

Agents must not perform or recommend without explicit human review:

- Production deployments
- Production database imports/exports
- Payment gateway configuration changes
- Refund automation
- Vendor payout logic
- Order state mutation logic
- Destructive Drush commands
- Direct commits to `main`
- Force pushes to shared branches
- Secret rotation
- Live customer/order/ticket data handling
- Production cron, queue, or webhook changes

## Never commit secrets

Do not read, print, copy, commit, or expose:

- API keys
- Payment gateway secrets
- Production database credentials
- Private SSH keys
- OAuth secrets
- `.env` files
- Database dumps
- Customer data exports
- Vendor payout data
- Full production logs containing personal data

## Drupal 11 standards

Agents must:

- Use Drupal 11-compatible APIs.
- Avoid deprecated APIs.
- Preserve cacheability metadata.
- Use access checks for entity queries where appropriate.
- Respect entity access.
- Use dependency injection where practical.
- Keep Twig presentation-focused.
- Add config schema for custom config.
- Use translation for user-facing strings.
- Validate and sanitise external input.
- Avoid direct SQL unless justified and reviewed.
- Avoid hard-coded entity IDs where possible.

## Drupal Commerce 3 standards

Agents must be careful with:

- Product modelling
- Product variations
- Order item types
- Stores
- Carts
- Checkout flows
- Checkout panes
- Payment gateways
- Payment state
- Order state
- Stock
- Capacity
- Ticket ownership
- Refunds
- Adjustments
- Promotions
- Tax

Agents must not assume:

- Payment success
- Ticket availability
- Refund eligibility
- Vendor ownership
- Customer access
- Capacity reservation
- Stock reservation
- Order completion

Agents must identify risk before changing Commerce logic.

## MEL content and Commerce separation

MEL must keep these concepts distinct:

- Event content/entity data describes the event.
- Commerce products and variations represent sellable tickets or booking options.
- Orders represent purchases.
- Order items represent purchased ticket/booking lines.
- Vendor dashboards must not expose data from other vendors.
- Public event pages must not leak private vendor, customer, or order data.

## Frontend and UX standards

Agents must:

- Follow existing MEL Twig/SCSS conventions.
- Preserve MEL pastel brand direction.
- Use mobile-first responsive design.
- Maintain accessible contrast.
- Preserve focus states.
- Avoid keyboard traps.
- Avoid inaccessible icon-only buttons.
- Preserve locked hero variants unless explicitly instructed.
- Avoid broad CSS side effects.
- Keep reusable components clean.

## Validation commands

After backend/config work, recommend:

```bash
ddev drush cr
ddev drush config:status
ddev drush cim --preview
ddev drush cex
ddev composer validate
```

After frontend/theme work, recommend:

```bash
npm run lint
npm run build
```

Before commit, recommend:

```bash
git status
git diff
ddev drush config:status
```

Do not claim any command passed unless it was actually run.

## Branching

Recommended branch naming:

```text
feature/mel-short-description
fix/mel-short-description
chore/mel-short-description
audit/mel-short-description
```

Agents should keep diffs focused and reviewable.

## Commit guidance

A safe MEL commit should include:

- Code changes
- Required config exports
- Required schema updates
- Related template/SCSS changes
- Documentation updates if behaviour changed

Avoid mixing unrelated changes.

## PR checklist

Before opening or merging a PR, check:

- Does the change affect access control?
- Does it affect checkout?
- Does it affect payment/order state?
- Does it affect ticket capacity or stock?
- Does it expose vendor/customer/order data?
- Does it introduce config drift?
- Does it need config schema?
- Does it affect cacheability?
- Does it affect mobile UX?
- Does it affect accessibility?
- Were lint/build/cache/config checks run?

## Agent response style

Agents should:

- Be direct.
- Use exact file paths.
- State assumptions.
- Explain risk.
- Provide validation commands.
- Avoid vague reassurance.
- Avoid invented APIs.
- Ask only one clarifying question if blocked.
