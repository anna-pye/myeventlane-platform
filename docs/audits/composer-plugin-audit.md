# Composer Plugin Audit

**Repository:** `/Users/anna/myeventlane`  
**Audit date:** 2026-06-22  
**Scope:** All Composer packages with `"type": "composer-plugin"` in `composer.lock`, plus `config.allow-plugins` entries  
**Action taken:** Audit only — no package or config changes.

---

## Summary

| Plugin | Version | Section | Production necessity |
|--------|---------|---------|----------------------|
| `composer/installers` | 2.3.0 | `require` | **Yes (build/deploy)** |
| `cweagans/composer-patches` | 1.7.3 | `require` | **Yes (build/deploy)** |
| `drupal/core-composer-scaffold` | 11.3.12 | `require` | **Yes (build/deploy)** |
| `drupal/core-project-message` | 11.3.12 | `require` | **No (DX only)** |
| `drupal/core-recipe-unpack` | 11.3.12 | `require` | **Conditional (build/deploy)** |
| `dealerdirect/phpcodesniffer-composer-installer` | 1.2.1 | `require-dev` | **No (dev only)** |
| `php-http/discovery` | 1.20.0 | `require-dev` (transitive) | **No (dev only)** |
| `phpstan/extension-installer` | 1.4.3 | `require-dev` | **No (dev only)** |
| `tbachert/spi` | 1.0.5 | `require-dev` (transitive) | **No (dev only)** |
| `php-tuf/composer-integration` | — | `allow-plugins` only | **Not installed** |

**Installed plugin count:** 9 (5 production `require`, 4 dev-only `require-dev` chain).

**Production deploy model:** `.github/workflows/reusable-build.yml` runs `composer install --no-dev`, so dev-only plugins are not installed on production artifacts. Production plugins run at **build/deploy time** only; none execute at PHP runtime.

**Stale allow-list entry:** `php-tuf/composer-integration` is permitted in `composer.json` `config.allow-plugins` but is not present in `composer.lock` or the vendor tree. Safe to remove from `allow-plugins` in a future cleanup; no runtime impact today.

---

## Validation (2026-06-22)

```bash
composer validate --check-lock
# ./composer.json is valid

composer show --type=composer-plugin
# The "--type" option does not exist.  (Composer 2.9.2)
```

`composer show --type=composer-plugin` is **not supported** in Composer 2.9.2. Use one of these equivalents:

```bash
# Installed plugins (filtered from composer show -i)
composer show -i | rg 'installers|patches|scaffold|project-message|recipe-unpack|phpcodesniffer|discovery|extension-installer|tbachert'

# Authoritative list from lock file
python3 -c "
import json
with open('composer.lock') as f:
    lock = json.load(f)
plugins = [p for p in lock.get('packages', []) + lock.get('packages-dev', [])
           if p.get('type') == 'composer-plugin']
for p in sorted(plugins, key=lambda x: x['name']):
    print(f\"{p['name']} {p['version']} - {p.get('description','')}\")
"
```

Lock-file output:

```
composer/installers v2.3.0 - A multi-framework Composer library installer
cweagans/composer-patches 1.7.3 - Provides a way to patch Composer packages.
dealerdirect/phpcodesniffer-composer-installer v1.2.1 - PHP_CodeSniffer Standards Composer Installer Plugin
drupal/core-composer-scaffold 11.3.12 - A flexible Composer project scaffold builder.
drupal/core-project-message 11.3.12 - Adds a message after Composer installation.
drupal/core-recipe-unpack 11.3.12 - A Composer project unpacker for Drupal recipes.
php-http/discovery 1.20.0 - Finds and installs PSR-7, PSR-17, PSR-18 and HTTPlug implementations
phpstan/extension-installer 1.4.3 - Composer plugin for automatic installation of PHPStan extensions
tbachert/spi v1.0.5 - Service provider loading facility
```

---

## Plugin reference

### `composer/installers`

| Field | Detail |
|-------|--------|
| **Purpose** | Routes packages to custom install paths based on package `type` (e.g. `drupal-module` → `web/modules/contrib/{$name}`). |
| **Required by** | Direct: `drupal/recommended-project` (`composer.json` `require`). |
| **Production necessity** | **Yes (build/deploy).** Without this plugin, Drupal core, contrib modules, themes, libraries, and Drush commands would not land in the relocated `web/` document root defined in `extra.installer-paths`. |
| **MEL config** | `extra.installer-paths` maps `drupal-core`, `drupal-module`, `drupal-theme`, `drupal-library`, `drupal-drush`, custom module/theme paths, and `drupal-recipe` → `recipes/{$name}`. |
| **Runtime** | Not loaded at PHP request time. |

---

### `cweagans/composer-patches` ⚠️

| Field | Detail |
|-------|--------|
| **Purpose** | Applies unified-diff patches to Composer packages during `install` / `update`. |
| **Required by** | Direct: `drupal/recommended-project` (`composer.json` `require`). |
| **Production necessity** | **Yes (build/deploy).** Patches modify installed source under `web/core` and `web/modules/contrib`; those modified files are what production runs. Removing the plugin without upstreaming or replacing patches would regress production behaviour. |
| **MEL patches** | Defined in `composer.json` `extra.patches`: |

**Active patches:**

| Target | Patch file | Why MEL needs it |
|--------|------------|------------------|
| `drupal/core` | `patches/drupal-core-formatter-third-party-settings.patch` | Prevents `TypeError` when Layout Builder field formatters receive `null` `third_party_settings`. |
| `drupal/core` | `patches/drupal-core-config-entity-query-null-condition.patch` | Prevents PHP 8.1+ `mb_strtolower(null)` deprecation in config entity query conditions. |
| `drupal/image_widget_crop` | `patches/image-widget-crop-immutable-config.patch` | Fixes `ImmutableConfigException` when saving crop widget admin settings (drupal.org #3496678). |

| **Risk** | Patches can conflict on core/contrib upgrades. Re-validate after every `drupal/core` or `drupal/image_widget_crop` bump; prefer upstream fixes and patch removal when available. |
| **Runtime** | Not loaded at PHP request time; only the patched files matter. |

---

### `drupal/core-composer-scaffold` ⚠️

| Field | Detail |
|-------|--------|
| **Purpose** | Copies and merges scaffold files from Drupal core (and other packages) into the project web root — `.htaccess`, `index.php`, `robots.txt`, `update.php`, `autoload.php`, etc. |
| **Required by** | Direct: `drupal/recommended-project` (`require`). Also required by `drupal/core` 11.3.12 (`self.version`). |
| **Production necessity** | **Yes (build/deploy).** Scaffold output is part of the deployed web root. Production serving depends on scaffolded entry points (e.g. `web/index.php`, `web/.htaccess`). |
| **MEL config** | `extra.drupal-scaffold.locations.web-root` = `web/`. |
| **Runtime** | Not loaded at PHP request time. |
| **Note** | Replaces legacy `drupal-composer/drupal-scaffold` (conflict declared in package metadata). |

---

### `drupal/core-project-message` ⚠️

| Field | Detail |
|-------|--------|
| **Purpose** | Prints a formatted post-install message after `composer install` / `update` (Drupal onboarding links, docs). |
| **Required by** | Direct: `drupal/recommended-project` (`require`). |
| **Production necessity** | **No (DX only).** No files or runtime behaviour change; message appears only in Composer CLI output. |
| **MEL config** | `extra.drupal-core-project-message.include-keys` = `["homepage", "support"]`. |
| **Recommendation** | Keep as part of standard Drupal recommended-project template. Safe to retain in production `require`; zero deploy artifact impact. Could be moved to `require-dev` only if the team explicitly wants a leaner production `composer.json` — not recommended without broader template alignment. |

---

### `drupal/core-recipe-unpack`

| Field | Detail |
|-------|--------|
| **Purpose** | Unpacks Drupal **recipes** (bundled config/module install automation) from Composer packages into `recipes/`. |
| **Required by** | Direct: `drupal/recommended-project` (`require`). |
| **Production necessity** | **Conditional (build/deploy).** Required only when installing or updating packages of type `drupal-recipe`. MEL currently has no unpacked recipes (`recipes/` contains only `README.txt`), but `extra.installer-paths` reserves `recipes/{$name}` and the plugin is part of Drupal 11's standard project template. |
| **Runtime** | Not loaded at PHP request time. |

---

### `dealerdirect/phpcodesniffer-composer-installer` ⚠️

| Field | Detail |
|-------|--------|
| **Purpose** | Registers PHPCS coding standards (Drupal, Slevomat, etc.) with `squizlabs/php_codesniffer` automatically on `composer install`. |
| **Required by** | Direct (dev): `drupal/recommended-project` (`require-dev`). Also required by `drupal/coder` 8.3.31 and `slevomat/coding-standard` 8.22.1. |
| **Production necessity** | **No (dev only).** Excluded by `composer install --no-dev` in CI production builds (`.github/workflows/reusable-build.yml`). |
| **Runtime** | Not loaded at PHP request time. |
| **Note** | `drupal/core` 11.3.12 declares a conflict with `dealerdirect/phpcodesniffer-composer-installer` 1.1.0 only; installed 1.2.1 is compatible. |

---

### `php-http/discovery`

| Field | Detail |
|-------|--------|
| **Purpose** | Composer plugin that can install PSR-7/17/18 and HTTPlug implementations when packages declare virtual `provide` requirements. Marked `plugin-optional: true` upstream. |
| **Required by** | Transitive (dev): `open-telemetry/exporter-otlp` 1.4.0, `open-telemetry/sdk` 1.14.0 ← `drupal/core-dev` 11.3.1. |
| **Production necessity** | **No (dev only).** Entire chain is under `require-dev`; not installed with `--no-dev`. |
| **Runtime** | Not loaded at PHP request time. |

---

### `phpstan/extension-installer`

| Field | Detail |
|-------|--------|
| **Purpose** | Auto-registers PHPStan extensions (e.g. `mglaman/phpstan-drupal`) in `vendor/phpstan/extension-installer` on install. |
| **Required by** | Direct (dev): `drupal/recommended-project` (`require-dev`). Also required by `drupal/core-dev` 11.3.1. |
| **Production necessity** | **No (dev only).** Static analysis tooling only. |
| **Runtime** | Not loaded at PHP request time. |

---

### `tbachert/spi`

| Field | Detail |
|-------|--------|
| **Purpose** | Service Provider Interface (SPI) loader — registers PHP `ServiceLoader` providers for packages that use the SPI pattern. |
| **Required by** | Transitive (dev): `open-telemetry/sdk` 1.14.0 ← `drupal/core-dev` → OpenTelemetry test/dev tooling. |
| **Production necessity** | **No (dev only).** Marked `plugin-optional: true` upstream; dev dependency chain only. |
| **Runtime** | Not loaded at PHP request time. |

---

### `php-tuf/composer-integration` (allow-list only)

| Field | Detail |
|-------|--------|
| **Purpose** | Would integrate PHP-TUF (The Update Framework) signature verification into Composer operations. |
| **Required by** | **Not installed.** Listed in `composer.json` `config.allow-plugins` only. |
| **Production necessity** | **N/A — not present.** `php-tuf/composer-stager` 2.0.2 is installed as a **library** (Drupal automatic updates staging), not this plugin. |
| **Recommendation** | Remove stale `allow-plugins` entry in a future hygiene pass, or install deliberately if TUF-verified Composer updates become a requirement. |

---

## `config.allow-plugins` matrix

All installed plugins are explicitly allowed (Composer 2.2+ security default):

| Plugin | Allowed | Installed |
|--------|---------|-----------|
| `composer/installers` | yes | yes |
| `cweagans/composer-patches` | yes | yes |
| `dealerdirect/phpcodesniffer-composer-installer` | yes | yes (dev) |
| `drupal/core-composer-scaffold` | yes | yes |
| `drupal/core-project-message` | yes | yes |
| `drupal/core-recipe-unpack` | yes | yes |
| `php-http/discovery` | yes | yes (dev) |
| `php-tuf/composer-integration` | yes | **no** |
| `phpstan/extension-installer` | yes | yes (dev) |
| `tbachert/spi` | yes | yes (dev) |

---

## Production vs development install

| Context | Command | Plugins installed |
|---------|---------|-------------------|
| Local / CI (full) | `composer install` | All 9 plugins |
| Production artifact | `composer install --no-dev` (`.github/workflows/reusable-build.yml`) | 5 production plugins only |
| Runtime (web requests) | — | **0** (plugins are not autoloaded during HTTP handling) |

**Build-time effects that persist in production artifacts:**

1. **Install paths** — `composer/installers` layout under `web/`, `drush/`, `recipes/`.
2. **Scaffold files** — `web/index.php`, `web/.htaccess`, `web/autoload.php`, etc.
3. **Patched source** — modified `web/core` and `web/modules/contrib/image_widget_crop` code.

---

## Recommendations

1. **Keep all five production `require` plugins** — they are standard for Drupal 11 recommended-project and MEL depends on installer paths, scaffold, and active patches.
2. **Track patch debt** — document upstream issue status for each patch; remove when fixed in released core/contrib versions.
3. **Do not deploy dev plugins to production** — current `--no-dev` CI workflow is correct.
4. **Remove stale `php-tuf/composer-integration` allow entry** when convenient (optional hygiene).
5. **Re-run this audit** after major Composer or Drupal core upgrades, or when adding/removing patches or recipes.

---

## Related files

- `composer.json` — direct requires, `extra.patches`, `extra.drupal-scaffold`, `extra.installer-paths`, `config.allow-plugins`
- `composer.lock` — locked plugin versions and `packages` / `packages-dev` split
- `patches/` — project patch files applied by `cweagans/composer-patches`
- `.github/workflows/reusable-build.yml` — production `composer install --no-dev`
- `docs/audits/dependency-risk-register.md` — non-stable production dependency audit (separate scope)

**Next review:** 2026-09-22 (quarterly), or immediately when patches, scaffold overrides, or recipe packages change.
