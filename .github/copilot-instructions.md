# GitHub Copilot Instructions for MyEventLane / MEL

You are assisting on the MyEventLane v2 repository, known as MEL.

MEL is a Drupal 11 event marketplace and ticketing platform using Drupal Commerce 3, custom Drupal modules, custom Twig/SCSS/Vite themes, Commerce checkout flows, vendor dashboards, event discovery, booking UX, media workflows, and Humanitix-level product expectations.

## Primary goal

Help build MEL safely and maintainably.

Prioritise:

1. Stability
2. Accuracy
3. Security
4. Drupal 11 best practice
5. Drupal Commerce 3 best practice
6. Long-term maintainability
7. MEL continuity
8. Clean UX
9. Production readiness

## Repository truth

Use the repository as the source of truth.

Do not invent:

- Drupal APIs
- Drupal Commerce services
- Plugin IDs
- Routes
- Permissions
- Field names
- Config keys
- Libraries
- Theme regions
- Hooks
- Event subscribers
- Checkout pane IDs
- Product variation types
- Order item types

If something is not visible in the repository, say so.

## Drupal rules

- Use Drupal 11-compatible APIs.
- Prefer dependency injection over static service access where practical.
- Preserve cacheability metadata.
- Respect entity access.
- Use translation wrappers for user-facing strings.
- Keep Twig presentation-only.
- Keep business logic in services, plugins, controllers, forms, or appropriate Drupal extension points.
- Add config schema when introducing or changing module config.
- Do not use deprecated APIs.
- Do not bypass form API validation or access checks.

## Commerce rules

Assume Drupal Commerce 3.

Before suggesting Commerce changes, identify the affected concept:

- Product type
- Product variation type
- Order item type
- Store
- Cart
- Checkout flow
- Checkout pane
- Payment gateway
- Order state
- Adjustment
- Promotion
- Tax
- Stock
- Capacity
- Refund
- Ticket ownership

Never assume ticket availability, payment success, refund eligibility, or order ownership without repository evidence.

Flag high-risk changes involving:

- Checkout
- Payment state
- Ticket capacity
- Vendor payouts
- Order access
- Customer data
- Refunds
- Webhooks
- Stock/capacity reservations

## Security rules

Do not expose:

- Production credentials
- API keys
- Payment secrets
- Customer data
- Vendor-private data
- Ticket/order details
- Database dumps
- `.env` secrets

Do not propose changes that:

- Commit secrets
- Disable CSRF protection
- Bypass Drupal access
- Trust raw request data
- Give vendors access to other vendors' data
- Let anonymous users view private booking/order data
- Modify payment state without review

## Theme and frontend rules

MEL uses custom Twig, SCSS, and Vite theme work.

- Follow existing MEL component conventions.
- Preserve locked hero variants unless explicitly asked.
- Use mobile-first CSS.
- Maintain accessible contrast, focus states, and keyboard usability.
- Do not introduce broad global CSS unless necessary.
- Prefer reusable components.
- Validate theme changes with the project build/lint scripts.

## Config workflow

When config changes are made or generated, recommend checking drift:

```bash
ddev drush config:status
ddev drush cim --preview
ddev drush cex
ddev drush cr
```

Before a PR, recommend:

```bash
ddev drush cr
ddev drush config:status
ddev composer validate
npm run lint
npm run build
git diff
```

## Pull request behaviour

When helping with a PR:

- Summarise what changed.
- List risk areas.
- Mention config changes.
- Mention cache rebuild needs.
- Mention build/lint status if known.
- Do not claim tests passed unless they were actually run.
- Do not approve high-risk Commerce or access-control changes without review.

## Style

Be concise, specific, and repository-grounded.

Use exact paths where possible.

If unsure, write:

> I cannot confirm this from the repository.
