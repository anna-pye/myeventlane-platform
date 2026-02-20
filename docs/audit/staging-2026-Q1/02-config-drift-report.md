# Phase 2 — Configuration Drift Report

**Audit Date:** 2026-02-20  
**Branch:** audit/staging-2026-Q1  
**Config Sync:** sites/default/files/sync (DDEV)

---

## Drift Summary

| Config Item | State | Severity |
|-------------|-------|----------|
| block.block.myeventlane_theme_vendorprofiles | Only in DB | Medium |
| commerce_order.commerce_order_type.default | Different | **High** |
| commerce_order.commerce_order_type.platform_donation | Different | **High** |
| commerce_order.commerce_order_type.rsvp_donation | Different | **High** |
| user.role.anonymous | Different | **High** |
| user.role.authenticated | Different | **High** |

**Total:** 6 items

---

## Detailed Analysis

### 1. block.block.myeventlane_theme_vendorprofiles

- **State:** Only in DB (not in config sync)
- **Impact:** Block placement created/edited in staging not version-controlled. Risk of loss on config import.
- **Classification:** Medium

---

### 2. commerce_order.commerce_order_type.default

**Sync (file):**
```yaml
showOrderEditLinks: null
sendReceipt: false
receiptSubject: ''
```

**Active (DB):**
```yaml
showOrderEditLinks: true
sendReceipt: true
receiptSubject: 'Your receipt for [commerce_order:order_items]'
```

- **Impact:** Staging has order-editing and receipt behaviour configured. Sync reflects earlier/default state.
- **Risk:** Import would revert receipt sending and edit links to defaults.
- **Classification:** **HIGH** — Commerce checkout behaviour differs from config sync.

---

### 3. commerce_order.commerce_order_type.platform_donation / rsvp_donation

- **State:** Different from sync
- **Impact:** Order type third-party settings (cart/checkout) likely differ.
- **Classification:** **HIGH** — Affects donation flows.

---

### 4. user.role.anonymous / user.role.authenticated

- **State:** Different
- **Impact:** Permission changes made in staging (e.g. `access check-in`, `access commerce_order overview`, `use klaro`, `view unpublished paragraphs`) not exported.
- **Risk:** Config import could revert permission model; environment-specific grants may be lost.
- **Classification:** **HIGH** — Access control drift.

---

## Environment Leakage Check

**Dev modules enabled:** None detected beyond standard DDEV defaults (verbose logging in settings.ddev.php).

**API keys / secrets in config sync:**  
Config sync at `web/sites/default/files/sync` is under `web/sites/*/files/` (gitignored). However, committed copies exist in:

- `_INVALID_config_backup_2026-01-02/sync/` — contains Stripe keys, Google Maps API key
- `_myeventlane_audit/config-sync/` — same

**Classification:** **CRITICAL** — Payment and API credentials in version-controlled backup/audit folders. See Phase 4.

---

## Recommendations

1. **Export active config** for the 6 drifted items and commit to sync (or document as intentional overrides).
2. **Resolve block placement:** Add `block.block.myeventlane_theme_vendorprofiles` to sync or document as theme-specific.
3. **Secrets:** Remove `_INVALID_config_backup_2026-01-02` and `_myeventlane_audit` from repo or strip secrets before commit. Use config overrides / env vars per `docs/SECRETS_PROTECTION_GUIDE.md`.
