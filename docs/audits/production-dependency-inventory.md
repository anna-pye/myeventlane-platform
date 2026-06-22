# Production dependency inventory

**Repository:** `/Users/anna/myeventlane`  
**Audit date:** 2026-06-22  
**Source:** `composer show --no-dev --locked` (174 packages)  
**Lock file:** `composer.lock` (platform PHP 8.3)  
**Related:** [`dependency-risk-register.md`](dependency-risk-register.md) — deep dive on non-stable Drupal modules

---

## Summary

| Metric | Count |
|---|---|
| Production packages (locked, no dev) | 174 |
| Direct `require` entries | 56 |
| Non-stable (alpha / RC) | 3 |
| Abandoned (Composer metadata) | 0 |
| High-risk flagged | 14 |
| Local Composer patches | 3 (2× core, 1× contrib) |

### Classification totals

| Category | Packages |
|---|---|
| Drupal core | 5 |
| Commerce | 8 |
| Payment | 7 |
| Drupal contrib | 48 |
| Application libraries | 14 |
| Infrastructure | 63 |
| Developer tooling | 24 |
| Testing | 5 |

Primary taxonomy: Drupal core, Commerce, Payment, Infrastructure, Developer tooling, Testing. **Drupal contrib** and **Application libraries** are additional sections required for a complete Drupal production inventory.

---

## Flagged packages

### Non-stable (alpha / RC)

| Package | Version | Category | Notes |
|---|---|---|---|
| `drupal/commerce_recurring` | 1.0.0-rc3 | Payment | Revenue-critical RC; MEL Pro subscriptions |
| `drupal/conditional_fields` | 4.0.0-alpha6 | Drupal contrib | Alpha; global form alters |
| `drupal/webp` | 1.0.0-rc2 | Drupal contrib | RC; image derivative pipeline |

### Abandoned

None reported in `composer.lock` / `composer show --no-dev --locked` metadata.

### High-risk

| Package | Version | Category | Reason |
|---|---|---|---|
| `cweagans/composer-patches` | 1.7.3 | Developer tooling | Applies local core/contrib patches at install |
| `drupal/commerce_paypal` | 2.1.2 | Payment | PayPal gateway |
| `drupal/commerce_recurring` | 1.0.0-rc3 | Payment | Revenue-critical RC; MEL Pro subscriptions |
| `drupal/commerce_stripe` | 2.2.1 | Payment | Primary payment gateway |
| `drupal/conditional_fields` | 4.0.0-alpha6 | Drupal contrib | Alpha; global form alters |
| `drupal/core` | 11.3.12 | Drupal core | Two local patches in composer.json |
| `drupal/image_widget_crop` | 3.0.0 | Drupal contrib | Local patch applied (3496678) |
| `drupal/social_auth_google` | 4.0.3 | Drupal contrib | Google OAuth login surface |
| `drupal/webp` | 1.0.0-rc2 | Drupal contrib | RC; image derivative pipeline |
| `league/oauth2-client` | 2.9.0 | Payment | OAuth token handling |
| `league/oauth2-google` | 4.2.0 | Payment | Google OAuth client |
| `mglaman/drupal-check` | 1.5.0 | Developer tooling | Static-analysis CLI listed in production require |
| `psy/psysh` | v0.12.22 | Developer tooling | REPL listed in production require |
| `stripe/stripe-php` | v15.10.0 | Payment | Stripe API client |

### Composer patches (install-time)

| Target | Purpose |
|---|---|
| `drupal/core` | FormatterBase third_party_settings null TypeError (Layout Builder) |
| `drupal/core` | `mb_strtolower(null)` deprecation in config entity query Condition |
| `drupal/image_widget_crop` | ImmutableConfigException when saving crop widget settings (#3496678) |

Patches are declared in `composer.json` → `extra.patches` and applied by `cweagans/composer-patches`.

---

## Direct production requirements

Packages declared in `composer.json` → `require` (56):

- `composer/installers` v2.3.0
- `cweagans/composer-patches` 1.7.3 — high-risk: Applies local core/contrib patches at install
- `dompdf/dompdf` v3.1.5
- `drupal/address` 2.0.4
- `drupal/admin_toolbar` 3.6.3
- `drupal/advancedqueue` 1.6.0
- `drupal/bootstrap5` 4.0.8
- `drupal/bootstrap5_admin` 1.1.38
- `drupal/classy` 2.0.3
- `drupal/commerce` 3.3.6
- `drupal/commerce_paypal` 2.1.2 — high-risk: PayPal gateway
- `drupal/commerce_recurring` 1.0.0-rc3 — non-stable; high-risk: Revenue-critical RC; MEL Pro subscriptions
- `drupal/commerce_shipping` 3.0.2
- `drupal/commerce_stripe` 2.2.1 — high-risk: Primary payment gateway
- `drupal/conditional_fields` 4.0.0-alpha6 — non-stable; high-risk: Alpha; global form alters
- `drupal/core-composer-scaffold` 11.3.12
- `drupal/core-project-message` 11.3.12
- `drupal/core-recipe-unpack` 11.3.12
- `drupal/core-recommended` 11.3.12
- `drupal/ctools` 4.1.0
- `drupal/field_group` 4.0.0
- `drupal/flag` 5.0.3
- `drupal/focal_point` 2.1.2
- `drupal/fullcalendar_view` 5.2.5
- `drupal/geofield` 10.3.4
- `drupal/gin` 5.0.15
- `drupal/gin_login` 2.1.4
- `drupal/gin_toolbar` 3.0.3
- `drupal/honeypot` 2.2.2
- `drupal/image_widget_crop` 3.0.0 — high-risk: Local patch applied (3496678)
- `drupal/imageapi_optimize` 4.2.0
- `drupal/inline_entity_form` 3.0.0
- `drupal/klaro` 3.1.0
- `drupal/mailsystem` 4.5.0
- `drupal/menu_link_attributes` 1.7.0
- `drupal/metatag` 2.2.0
- `drupal/migrate_plus` 6.0.10
- `drupal/migrate_tools` 6.1.4
- `drupal/mimemail` 2.0.2
- `drupal/paragraphs` 1.20.0
- `drupal/pathauto` 1.15.0
- `drupal/radix` 6.0.3
- `drupal/search_api` 1.41.0
- `drupal/search_api_db` 1.40.0
- `drupal/social_auth_google` 4.0.3 — high-risk: Google OAuth login surface
- `drupal/stable` 2.1.0
- `drupal/token` 1.17.0
- `drupal/twbstools` 2.1.0
- `drupal/twig_tweak` 3.4.2
- `drupal/webp` 1.0.0-rc2 — non-stable; high-risk: RC; image derivative pipeline
- `drush/drush` 13.7.2
- `endroid/qr-code` 6.0.9
- `mglaman/drupal-check` 1.5.0 — high-risk: Static-analysis CLI listed in production require
- `phpoffice/phpspreadsheet` 5.7.0
- `psy/psysh` v0.12.22 — high-risk: REPL listed in production require
- `rlanvin/php-rrule` v2.6.0

**Note:** `mglaman/drupal-check`, `psy/psysh`, and testing libraries pulled transitively (`phpstan/*`) are present in the production lock tree but are operationally developer-oriented. Consider moving CLI-only tools to `require-dev` in a future hygiene pass.

---

## Full inventory by category

### Drupal core (5)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `drupal/core` | 11.3.12 | transitive | **high-risk: Two local patches in composer.json** |
| `drupal/core-composer-scaffold` | 11.3.12 | yes | — |
| `drupal/core-project-message` | 11.3.12 | yes | — |
| `drupal/core-recipe-unpack` | 11.3.12 | yes | — |
| `drupal/core-recommended` | 11.3.12 | yes | — |

### Commerce (8)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `commerceguys/addressing` | v2.2.5 | transitive | — |
| `commerceguys/intl` | v2.0.7 | transitive | — |
| `drupal/commerce` | 3.3.6 | yes | — |
| `drupal/commerce_number_pattern` | 3.3.5 | transitive | — |
| `drupal/commerce_order` | 3.3.5 | transitive | — |
| `drupal/commerce_price` | 3.3.5 | transitive | — |
| `drupal/commerce_shipping` | 3.0.2 | yes | — |
| `drupal/commerce_store` | 3.3.5 | transitive | — |

### Payment (7)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `drupal/commerce_payment` | 3.3.5 | transitive | — |
| `drupal/commerce_paypal` | 2.1.2 | yes | **high-risk: PayPal gateway** |
| `drupal/commerce_recurring` | 1.0.0-rc3 | yes | **non-stable**; **high-risk: Revenue-critical RC; MEL Pro subscriptions** |
| `drupal/commerce_stripe` | 2.2.1 | yes | **high-risk: Primary payment gateway** |
| `league/oauth2-client` | 2.9.0 | transitive | **high-risk: OAuth token handling** |
| `league/oauth2-google` | 4.2.0 | transitive | **high-risk: Google OAuth client** |
| `stripe/stripe-php` | v15.10.0 | transitive | **high-risk: Stripe API client** |

### Drupal contrib (48)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `drupal/address` | 2.0.4 | yes | — |
| `drupal/admin_toolbar` | 3.6.3 | yes | — |
| `drupal/advancedqueue` | 1.6.0 | yes | — |
| `drupal/bootstrap5` | 4.0.8 | yes | — |
| `drupal/bootstrap5_admin` | 1.1.38 | yes | — |
| `drupal/classy` | 2.0.3 | yes | — |
| `drupal/conditional_fields` | 4.0.0-alpha6 | yes | **non-stable**; **high-risk: Alpha; global form alters** |
| `drupal/crop` | 2.6.0 | transitive | — |
| `drupal/ctools` | 4.1.0 | yes | — |
| `drupal/entity` | 1.6.0 | transitive | — |
| `drupal/entity_reference_revisions` | 1.14.0 | transitive | — |
| `drupal/field_group` | 4.0.0 | yes | — |
| `drupal/flag` | 5.0.3 | yes | — |
| `drupal/focal_point` | 2.1.2 | yes | — |
| `drupal/fullcalendar_view` | 5.2.5 | yes | — |
| `drupal/geofield` | 10.3.4 | yes | — |
| `drupal/gin` | 5.0.15 | yes | — |
| `drupal/gin_login` | 2.1.4 | yes | — |
| `drupal/gin_toolbar` | 3.0.3 | yes | — |
| `drupal/honeypot` | 2.2.2 | yes | — |
| `drupal/image_widget_crop` | 3.0.0 | yes | **high-risk: Local patch applied (3496678)** |
| `drupal/imageapi_optimize` | 4.2.0 | yes | — |
| `drupal/inline_entity_form` | 3.0.0 | yes | — |
| `drupal/klaro` | 3.1.0 | yes | — |
| `drupal/klaro_js` | 3.1.0 | transitive | — |
| `drupal/mailsystem` | 4.5.0 | yes | — |
| `drupal/menu_link_attributes` | 1.7.0 | yes | — |
| `drupal/metatag` | 2.2.0 | yes | — |
| `drupal/migrate_plus` | 6.0.10 | yes | — |
| `drupal/migrate_tools` | 6.1.4 | yes | — |
| `drupal/mimemail` | 2.0.2 | yes | — |
| `drupal/paragraphs` | 1.20.0 | yes | — |
| `drupal/pathauto` | 1.15.0 | yes | — |
| `drupal/physical` | 1.5.0 | transitive | — |
| `drupal/profile` | 1.14.0 | transitive | — |
| `drupal/radix` | 6.0.3 | yes | — |
| `drupal/rat` | 1.0.1 | transitive | — |
| `drupal/search_api` | 1.41.0 | yes | — |
| `drupal/search_api_db` | 1.40.0 | yes | — |
| `drupal/social_api` | 4.0.2 | transitive | — |
| `drupal/social_auth` | 4.1.2 | transitive | — |
| `drupal/social_auth_google` | 4.0.3 | yes | **high-risk: Google OAuth login surface** |
| `drupal/stable` | 2.1.0 | yes | — |
| `drupal/state_machine` | 1.14.0 | transitive | — |
| `drupal/token` | 1.17.0 | yes | — |
| `drupal/twbstools` | 2.1.0 | yes | — |
| `drupal/twig_tweak` | 3.4.2 | yes | — |
| `drupal/webp` | 1.0.0-rc2 | yes | **non-stable**; **high-risk: RC; image derivative pipeline** |

### Application libraries (14)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `bacon/bacon-qr-code` | v3.1.1 | transitive | — |
| `dasprid/enum` | 1.0.7 | transitive | — |
| `dompdf/dompdf` | v3.1.5 | yes | — |
| `dompdf/php-font-lib` | 1.0.2 | transitive | — |
| `dompdf/php-svg-lib` | 1.0.2 | transitive | — |
| `endroid/qr-code` | 6.0.9 | yes | — |
| `itamair/geophp` | 1.12 | transitive | — |
| `league/container` | 4.2.5 | transitive | — |
| `maennchen/zipstream-php` | 3.2.2 | transitive | — |
| `markbaker/complex` | 3.0.2 | transitive | — |
| `markbaker/matrix` | 3.0.1 | transitive | — |
| `phpoffice/phpspreadsheet` | 5.7.0 | yes | — |
| `phpowermove/docblock` | v4.0 | transitive | — |
| `rlanvin/php-rrule` | v2.6.0 | yes | — |

### Infrastructure (63)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `asm89/stack-cors` | v2.3.0 | transitive | — |
| `dflydev/dot-access-data` | v3.0.3 | transitive | — |
| `doctrine/collections` | 2.6.0 | transitive | — |
| `doctrine/deprecations` | 1.1.6 | transitive | — |
| `doctrine/lexer` | 3.0.1 | transitive | — |
| `egulias/email-validator` | 4.0.4 | transitive | — |
| `guzzlehttp/guzzle` | 7.10.6 | transitive | — |
| `guzzlehttp/promises` | 2.3.1 | transitive | — |
| `guzzlehttp/psr7` | 2.10.4 | transitive | — |
| `jean85/pretty-package-versions` | 2.1.1 | transitive | — |
| `masterminds/html5` | 2.10.0 | transitive | — |
| `mck89/peast` | v1.17.6 | transitive | — |
| `nette/neon` | v3.4.7 | transitive | — |
| `nikic/php-parser` | v5.7.0 | transitive | — |
| `pear/archive_tar` | 1.6.0 | transitive | — |
| `pear/console_getopt` | v1.4.3 | transitive | — |
| `pear/pear-core-minimal` | v1.10.18 | transitive | — |
| `pear/pear_exception` | v1.0.2 | transitive | — |
| `psr/container` | 2.0.2 | transitive | — |
| `psr/event-dispatcher` | 1.0.0 | transitive | — |
| `psr/http-client` | 1.0.3 | transitive | — |
| `psr/http-factory` | 1.1.0 | transitive | — |
| `psr/http-message` | 2.0 | transitive | — |
| `psr/log` | 3.0.2 | transitive | — |
| `psr/simple-cache` | 3.0.0 | transitive | — |
| `ralouphie/getallheaders` | 3.0.3 | transitive | — |
| `revolt/event-loop` | v1.0.9 | transitive | — |
| `sabberworm/php-css-parser` | v9.3.0 | transitive | — |
| `symfony/console` | v7.4.13 | transitive | — |
| `symfony/dependency-injection` | v7.4.13 | transitive | — |
| `symfony/deprecation-contracts` | v3.7.0 | transitive | — |
| `symfony/error-handler` | v7.4.8 | transitive | — |
| `symfony/event-dispatcher` | v7.4.9 | transitive | — |
| `symfony/event-dispatcher-contracts` | v3.7.0 | transitive | — |
| `symfony/filesystem` | v7.4.11 | transitive | — |
| `symfony/finder` | v7.4.8 | transitive | — |
| `symfony/http-foundation` | v7.4.13 | transitive | — |
| `symfony/http-kernel` | v7.4.13 | transitive | — |
| `symfony/mailer` | v7.4.12 | transitive | — |
| `symfony/mime` | v7.4.13 | transitive | — |
| `symfony/polyfill-ctype` | v1.37.0 | transitive | — |
| `symfony/polyfill-iconv` | v1.37.0 | transitive | — |
| `symfony/polyfill-intl-grapheme` | v1.37.0 | transitive | — |
| `symfony/polyfill-intl-idn` | v1.38.1 | transitive | — |
| `symfony/polyfill-intl-normalizer` | v1.37.0 | transitive | — |
| `symfony/polyfill-mbstring` | v1.37.0 | transitive | — |
| `symfony/polyfill-php81` | v1.38.1 | transitive | — |
| `symfony/polyfill-php83` | v1.38.2 | transitive | — |
| `symfony/polyfill-php84` | v1.37.0 | transitive | — |
| `symfony/polyfill-php85` | v1.37.0 | transitive | — |
| `symfony/process` | v7.4.13 | transitive | — |
| `symfony/psr-http-message-bridge` | v7.4.8 | transitive | — |
| `symfony/routing` | v7.4.13 | transitive | — |
| `symfony/serializer` | v7.4.10 | transitive | — |
| `symfony/service-contracts` | v3.7.0 | transitive | — |
| `symfony/string` | v7.4.13 | transitive | — |
| `symfony/translation-contracts` | v3.7.0 | transitive | — |
| `symfony/validator` | v7.4.10 | transitive | — |
| `symfony/var-dumper` | v7.4.8 | transitive | — |
| `symfony/var-exporter` | v7.4.9 | transitive | — |
| `symfony/yaml` | v7.4.13 | transitive | — |
| `thecodingmachine/safe` | v3.4.0 | transitive | — |
| `twig/twig` | v3.27.1 | transitive | — |

### Developer tooling (24)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `chi-teck/drupal-code-generator` | 4.2.0 | transitive | — |
| `composer/installers` | v2.3.0 | yes | — |
| `composer/pcre` | 3.3.2 | transitive | — |
| `composer/semver` | 3.4.4 | transitive | — |
| `composer/xdebug-handler` | 3.0.5 | transitive | — |
| `consolidation/annotated-command` | 4.10.5 | transitive | — |
| `consolidation/config` | 3.2.1 | transitive | — |
| `consolidation/filter-via-dot-access-data` | 2.0.3 | transitive | — |
| `consolidation/log` | 3.1.2 | transitive | — |
| `consolidation/output-formatters` | 4.7.1 | transitive | — |
| `consolidation/robo` | 5.1.1 | transitive | — |
| `consolidation/site-alias` | 4.1.3 | transitive | — |
| `consolidation/site-process` | 5.4.2 | transitive | — |
| `cweagans/composer-patches` | 1.7.3 | yes | **high-risk: Applies local core/contrib patches at install** |
| `drush/drush` | 13.7.2 | yes | — |
| `grasmash/expander` | 3.0.1 | transitive | — |
| `grasmash/yaml-cli` | 3.2.1 | transitive | — |
| `laravel/prompts` | v0.3.17 | transitive | — |
| `mglaman/drupal-check` | 1.5.0 | yes | **high-risk: Static-analysis CLI listed in production require** |
| `phootwork/collection` | v3.2.3 | transitive | — |
| `phootwork/lang` | v3.2.3 | transitive | — |
| `php-tuf/composer-stager` | v2.0.2 | transitive | — |
| `psy/psysh` | v0.12.22 | yes | **high-risk: REPL listed in production require** |
| `webflo/drupal-finder` | 1.3.1 | transitive | — |

### Testing (5)

| Package | Version | Direct | Flags |
|---|---|---|---|
| `jangregor/phpstan-prophecy` | 1.0.2 | transitive | — |
| `mglaman/phpstan-drupal` | 1.3.9 | transitive | — |
| `phpstan/phpstan` | 1.12.33 | transitive | — |
| `phpstan/phpstan-deprecation-rules` | 1.2.1 | transitive | — |
| `sebastian/diff` | 6.0.2 | transitive | — |

---

## Validation

```bash
composer validate --check-lock
composer show --no-dev --locked
composer show --no-dev --locked --direct
```

**2026-06-22 run:** `composer show --no-dev --locked` returned 174 packages. Initial attempt without readable `vendor/composer/installed.json` failed; `--locked` reads from `composer.lock` and is the authoritative source when vendor is incomplete.

## Residual risk

- Three Drupal packages remain on non-stable tags; stable upgrades unavailable today — see [`dependency-risk-register.md`](dependency-risk-register.md).
- Payment and OAuth packages require staging smoke tests on any upgrade.
- Core/contrib patches must be re-validated on every Drupal core minor bump.
- `phpstan/*` and `symfony/var-dumper` appear in the production lock via transitive dependencies of `mglaman/drupal-check` / Drush; they are classified under **Testing** / **Infrastructure** but are not intentional runtime dependencies.

**Next review:** 2026-09-22 (quarterly), or before any Commerce / core upgrade.
