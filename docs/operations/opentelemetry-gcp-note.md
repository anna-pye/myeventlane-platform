# OpenTelemetry and Google Cloud Observability

**Date:** 2026-02-14  
**Scope:** Observability, dependencies, GCP compatibility

---

## Summary

MyEventLane does **not** use runtime OpenTelemetry or Google Cloud Observability. No code, composer, or infrastructure changes are required for GCP OTel ingestion API updates (March 2026).

---

## Verification (2026-02-14)

### Runtime OpenTelemetry Configuration

| Check | Result |
|-------|--------|
| `OTEL_*` / `OTLP_*` env vars | None configured |
| OTLP endpoint config | None in PHP, YAML, .env |
| Telemetry export to GCP | Not used |

**Conclusion:** No runtime OpenTelemetry configuration exists.

### Package Dependencies

| Package | Source | Scope |
|---------|--------|-------|
| `open-telemetry/api` | `drupal/core-dev` | dev |
| `open-telemetry/context` | transitive | dev |
| `open-telemetry/exporter-otlp` | `drupal/core-dev` | dev |
| `open-telemetry/gen-otlp-protobuf` | transitive | dev |
| `open-telemetry/sdk` | `drupal/core-dev` | dev |
| `open-telemetry/sem-conv` | transitive | dev |

These packages are pulled in by `drupal/core-dev` (PHPStan / static analysis tooling) and are **dev-only**. They are not deployed to production and do not send telemetry.

---

## Remote Server Audit (mel@myeventlane.com.au)

**Audit date:** 2026-02-14  
**Host:** mel@myeventlane.com.au (cPanel, production via `public_html/staging` → `staging/web`)

### Runtime OpenTelemetry Configuration

| Check | Result |
|-------|--------|
| `OTEL_*` / `OTLP_*` env vars | None |
| `settings.php` OTEL/OTLP config | None |
| `.env` OTEL/OTLP config | None / no matches |
| Apache/PHP-FPM OTEL env | None |

### Production Vendor (staging/vendor)

| Check | Result |
|-------|--------|
| `vendor/open-telemetry/*` | **Absent** — not deployed |
| `drupal/core-dev` | Absent — `composer install --no-dev` used |
| open-telemetry in `composer.lock` | Present (35 refs) — dev-only, excluded from deploy |

**Conclusion:** Production server has no OpenTelemetry packages installed and no runtime telemetry configuration. Same findings as local/codebase audit.

---

## Google Cloud Observability Notice (March 2026)

Google Cloud's new OTel ingestion API (`telemetry.googleapis.com`) auto-enables for projects using Cloud Logging, Trace, or Monitoring. MEL does not use these services. GCP usage is limited to Google Wallet API and Google Maps API.

**No action required.**

---

## What Not to Do

- Do **not** remove dev dependencies — Drupal core-dev controls OpenTelemetry packages
- Do **not** modify composer unnecessarily
- Do **not** add Google Cloud Observability configuration unless explicitly required
