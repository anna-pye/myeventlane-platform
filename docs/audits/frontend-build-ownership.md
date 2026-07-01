# Frontend build ownership

**Date:** 2026-06-22  
**Scope:** Vite / npm build pipeline for MEL themes.

## Summary

Frontend assets are built **per theme**, not from the repository root. The root `vite.config.js` was legacy (single-entry build to `/dist/`) and has been **removed**. Production and CI paths use theme-local Vite configs only.

---

## Active Vite configs

| Theme | Path | Role |
|-------|------|------|
| **Public (primary)** | `web/themes/custom/myeventlane_theme/vite.config.js` | Public site SCSS/JS; manifest-based hashed assets; DDEV HMR on port 5173 |
| **Vendor console** | `web/themes/custom/myeventlane_vendor_theme/vite.config.js` | Vendor dashboard SCSS/JS; `@mel-theme` alias to public theme SCSS partials |
| **Radix (experimental)** | `web/themes/custom/myeventlane_radix/vite.config.js` | Alternate base theme; not wired into `mel:build` or CI today |

**Audit snapshot (not production):** `_myeventlane_audit/frontend/vite.config.js` — historical audit input only.

---

## Active `package.json` locations

| Location | Scripts | Used by |
|----------|---------|---------|
| **Repository root** | `mel:build`, `mel:lint`, `mel:hero-check`, `prepare` (husky) | Developers, pre-commit hook, docs |
| `web/themes/custom/myeventlane_theme/package.json` | `build`, `dev`, `lint:css`, `check:hero` | `mel:build`, `mel:lint`, CI |
| `web/themes/custom/myeventlane_vendor_theme/package.json` | `build`, `build:vendor-wizard`, `dev` | `mel:build`, CI |
| `web/themes/custom/myeventlane_radix/package.json` | `build`, `dev` | Manual / rollout docs only |

Root `package.json` **does not** run Vite for production. `mel:build` chains:

```text
cd web/themes/custom/myeventlane_theme && npm ci && npm run build
cd web/themes/custom/myeventlane_vendor_theme && npm ci && npm run build
```

Root `dev` / `start` (`vite`, `vite preview`) previously consumed the deleted root `vite.config.js`. They now print guidance only — run `dev` / `start` from the theme directory (or `npm run mel:build` for production).

---

## CI and hooks

| Consumer | Build path |
|----------|------------|
| `.github/workflows/reusable-build.yml` | `npm ci` + `npm run build` in `myeventlane_theme` and `myeventlane_vendor_theme` working directories |
| `.husky/pre-commit` | `npm run mel:build` (root script → theme builds) |
| `scripts/rebuild-scss.sh` | `ddev npm run build --prefix web/themes/custom/myeventlane_theme` |

Root `npm ci` in CI installs husky, stylelint, and root-level tooling only — not theme asset compilation.

---

## Build output

| Theme | Output dir | Git |
|-------|------------|-----|
| Public | `web/themes/custom/myeventlane_theme/dist/` | Ignored (`/web/themes/custom/**/dist/`) |
| Vendor | `web/themes/custom/myeventlane_vendor_theme/dist/` | Ignored |
| Legacy root | `/dist/` | Ignored (`/dist/` in `.gitignore`); no longer produced |

Public theme libraries resolve hashed filenames via `dist/.vite/manifest.json` (`hook_library_info_alter`).

---

## Developer commands

```bash
# Production (both active themes)
npm run mel:build

# Lint (hero guard + scoped stylelint)
npm run mel:lint

# Per-theme dev server (from theme dir, typically via DDEV)
cd web/themes/custom/myeventlane_theme && ddev npm run dev
cd web/themes/custom/myeventlane_vendor_theme && ddev npm run dev
```

---

## Removed artefact

| File | Reason |
|------|--------|
| `vite.config.js` (repo root) | Superseded by theme configs; pointed at a single `main.js` entry and wrote to `/dist/`; not referenced by CI, husky, `mel:build`, or documented workflows |

---

## Validation

```bash
cd web/themes/custom/myeventlane_theme && npm ci && npm run build
cd web/themes/custom/myeventlane_vendor_theme && npm ci && npm run build
npm run mel:build   # optional aggregate check from root
git status
```
