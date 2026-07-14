# Local PHPUnit (canonical)

Canonical guide for running Drupal PHPUnit suites on a MEL DDEV workspace.

For the CI governance Kernel **slice** (`composer governance:test`), see [mel-governance-devtooling-and-ci.md](mel-governance-devtooling-and-ci.md). That path embeds `SIMPLETEST_DB` in `web/phpunit-governance.xml`. This document covers general Unit / Kernel / Functional / Browser runs via `web/core/phpunit.xml.dist`.

## Why the helper exists

`web/core/phpunit.xml.dist` leaves `SIMPLETEST_DB` and `SIMPLETEST_BASE_URL` empty. Unit tests do not need a database; Kernel and Functional tests abort before bootstrap without `SIMPLETEST_DB`. Drupal also expects a writable `web/sites/simpletest/browser_output` for HTML output from browser-oriented suites.

MEL does **not** put `SIMPLETEST_DB` in tracked `.ddev/config.yaml`. Use the helper instead.

## Entry points

| Entry | What it does |
| --- | --- |
| `scripts/mel-phpunit` | Sets missing env defaults, creates `browser_output`, runs `vendor/bin/phpunit` |
| `composer test:drupal` | Thin wrapper: `bash scripts/mel-phpunit` (all args after `--` are passed through) |

Prefer running inside DDEV so PHP, extensions, and DB match the project.

```bash
ddev exec bash scripts/mel-phpunit <phpunit-args>
# or
ddev composer test:drupal -- <phpunit-args>
```

## Environment variables

| Variable | Required for | Default when unset (helper) | Notes |
| --- | --- | --- | --- |
| `SIMPLETEST_DB` | Kernel, Functional, Browser | `sqlite://localhost/:memory:` | Matches CI governance style. **Never overwritten** if already set. |
| `SIMPLETEST_BASE_URL` | Functional, Browser | `DDEV_PRIMARY_URL` when present, else `https://myeventlane.ddev.site` | **Never overwritten** if already set. |
| `BROWSERTEST_OUTPUT_DIRECTORY` | HTML debug output | Absolute path to `web/sites/simpletest/browser_output` | Core’s XML default is CWD-relative (`sites/simpletest/browser_output`). The helper creates the web path and sets the absolute env when unset. **Never overwritten** if already set. |

### Recommended overrides

**Functional / Browser against DDEV MariaDB** (closer to a real stack):

```bash
export SIMPLETEST_DB='mysql://db:db@db/db'
export SIMPLETEST_BASE_URL='https://myeventlane.ddev.site'
ddev exec bash scripts/mel-phpunit -- web/modules/custom/myeventlane_legal/tests/src/Functional
```

**Preserve a custom DB URL** (helper will not replace it):

```bash
SIMPLETEST_DB='sqlite://localhost/tmp/mel-phpunit.sqlite' \
  ddev exec bash scripts/mel-phpunit -- web/modules/custom/.../tests/src/Kernel
```

## Examples

Paths below are from the repository root. Replace with any suite or file under `web/modules/custom/`.

### Unit

No database required, but the helper is still fine (sets env and default `-c`).

```bash
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalAddonGuidanceBuilderTest.php
```

### Kernel

```bash
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalCheckoutKernelTest.php
```

Composer equivalent:

```bash
ddev composer test:drupal -- \
  web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalCheckoutKernelTest.php
```

### Functional

Uses Mink against `SIMPLETEST_BASE_URL`. Prefer MariaDB for broader coverage:

```bash
ddev exec bash -lc '
  export SIMPLETEST_DB=mysql://db:db@db/db
  export SIMPLETEST_BASE_URL=https://myeventlane.ddev.site
  bash scripts/mel-phpunit web/modules/custom/myeventlane_legal/tests/src/Functional/RsvpLegalConsentTest.php
'
```

### Browser (FunctionalJavascript)

Same env needs as Functional, plus a working ChromeDriver/Selenium stack in DDEV if configured for this project. HTML output lands under `web/sites/simpletest/browser_output`.

```bash
ddev exec bash -lc '
  export SIMPLETEST_DB=mysql://db:db@db/db
  export SIMPLETEST_BASE_URL=https://myeventlane.ddev.site
  bash scripts/mel-phpunit web/modules/custom/<module>/tests/src/FunctionalJavascript
'
```

### Explicit PHPUnit config

The helper adds `-c web/core/phpunit.xml.dist` only when you did not pass `-c` / `--configuration`. Slice configs still work:

```bash
ddev exec bash scripts/mel-phpunit -c web/phpunit-governance.xml
# same CI slice without the helper:
ddev composer governance:test
```

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `There is no database connection… SIMPLETEST_DB` | Use `scripts/mel-phpunit` or `composer test:drupal`, or export `SIMPLETEST_DB` yourself. Do not run bare `vendor/bin/phpunit -c web/core/phpunit.xml.dist` for Kernel without the env. |
| `sites/simpletest/browser_output is not writable` | Re-run the helper (it `mkdir -p`s the directory). Fix ownership/permissions if the web user differs. |
| Unit OK, Kernel fails | You bypassed the helper and hit empty `SIMPLETEST_DB` in core `phpunit.xml.dist`. |
| Functional cannot reach site | Set `SIMPLETEST_BASE_URL` to your DDEV primary URL (`ddev describe`). |
| `vendor/bin/phpunit` missing | `ddev composer install` (`drupal/core-dev` supplies PHPUnit). |
| Want MariaDB instead of sqlite | Export `SIMPLETEST_DB=mysql://db:db@db/db` before the helper; existing values are preserved. |

## What this does not change

- Production / staging runtime or deploy scripts
- Tracked `.ddev/config.yaml`
- Ticket QR / Commerce checkout behaviour
- Active Drupal config / `config/sync`

## Related

- [mel-governance-devtooling-and-ci.md](mel-governance-devtooling-and-ci.md) — CI `governance:test` slice
- [mel-governance-testing-system.md](mel-governance-testing-system.md) — surface governance test design
- `web/core/tests/README.md` — upstream Drupal PHPUnit notes
