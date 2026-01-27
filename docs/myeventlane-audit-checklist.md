# MyEventLane v2 — Repository Audit Checklist

**Date:**  
**Branch:**  
**Commit:**  
**Docroot:** `web` (assumed)  
**Config sync:** `web/sites/default/config/sync` (current; consider relocating outside docroot post-audit)

---

## 1) Platform & Dependencies
- [ ] `composer check-platform-reqs` passes (see `composer-platform.txt`)
- [ ] PHP version supported by current Drupal core (verify with `composer show drupal/core-recommended` and `drush status`)
- [ ] Using `drupal/core-recommended` (pinned dependencies) and `drupal/core-composer-scaffold`
- [ ] No abandoned packages or insecure libs (scan `composer-show.json`)
- [ ] Patches documented (grep in `patches-grep.txt`)

## 2) Drupal Core & Modules
- [ ] Core version matches the target (Drupal 11; confirm exact version)
- [ ] Contrib modules are up-to-date, supported, and compatible with current core
- [ ] Minimal site config (no dev settings in prod). Verify `system.site.yml`.
- [ ] `core.extension.yml` audited (enabled modules/themes expected; no strays)

## 3) Custom Modules / Themes
- [ ] All custom modules have `.info.yml`, `.permissions.yml` (if needed), routing, services, config schema
- [ ] No deprecated APIs (`@deprecated` use); `@internal` not imported
- [ ] TypedData, Plugins, Event Subscribers follow Drupal 11 practices
- [ ] Config schema for any custom config (no untyped config)
- [ ] Theme uses Twig/SCSS with Vite; no unsafe `|raw` or string concatenation in Twig
- [ ] Accessibility: landmarks, color contrast (WCAG AA), focus states

## 4) Commerce Architecture
- [ ] Order types, product types, variations defined explicitly
- [ ] Checkout flow panes audited (login, address, payment, review)
- [ ] Payment gateways: keys in settings, not config; test/live separation
- [ ] Taxes, currency, rounding, refunds, and receipts behavior verified
- [ ] Stock management & availability rules
- [ ] Email templates and events (placed orders, tickets) wired

## 5) Caching & Performance
- [ ] Dynamic Page Cache and BigPipe enabled
- [ ] Render cache contexts/tags set for custom controllers/blocks
- [ ] Views caching configured (time or tag-based)
- [ ] Image styles and responsive images configured
- [ ] No cache-busting anti-patterns

## 6) Security
- [ ] Config export **outside** docroot (planned)
- [ ] Keys/secrets in `settings*.php` or environment, not in YAML
- [ ] Permissions audited; no wide `access content` overrides
- [ ] Twig debug disabled on prod; `services*.yml` reviewed
- [ ] File permissions and public/private file systems configured

## 7) DevOps
- [ ] DDEV `.ddev/config.yaml` sane; PHP/FPM versions match Composer reqs
- [ ] Drush commands run cleanly in CI (if present)
- [ ] Git hygiene: ignore `web/sites/*/files/**`, `vendor/**` etc.
- [ ] Tag before large core updates; branch naming consistent

## 8) Humanitix Parity (Events)
- [ ] Ticket types, fees, promos, seating/limits
- [ ] Attendee data capture forms; export/reporting
- [ ] Embedded checkout; mobile UX; accessibility
- [ ] Refunds/partial refunds; organizer tools

## 9) Findings & Actions
- **Critical:**  
- **High:**  
- **Medium:**  
- **Low:**  

## 10) Decisions & Risks
- Decision log and rationale.
