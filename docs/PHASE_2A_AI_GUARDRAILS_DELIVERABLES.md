# Phase 2A — AI Cost Guardrails — Deliverables

## Drush commands (safe order)

```bash
# 1. Clear cache (required after new services/routes)
drush cr

# 2. Run update hook for existing installs (adds new config keys)
drush updb -y
# Runs: myeventlane_ai_update_9001()

# 3. If you use config import
drush cim -y
```

**Note:** `drush updb` is required because `myeventlane_ai_update_9001()` adds the new guardrail config keys (`daily_user_token_limit`, etc.) to existing `myeventlane_ai.settings`. Fresh installs get them from `config/install/`. Existing installs need the update hook.

---

## Wiring confirmation

| Item | Status |
|------|--------|
| **Route** `/admin/config/myeventlane/ai-usage` | `_permission: 'administer myeventlane ai'`, `_admin_route: TRUE` |
| **Menu** | Parent: `myeventlane_core.config` (Configuration → MyEventLane) |
| **Permission** | Reuses existing `myeventlane_ai.permissions.yml` → `administer myeventlane ai` |
| **Vendor resolution** | `vendor_id` passed by caller from `$escalation->get('vendor_id')->target_id` (server-side, never from request) |
| **Circuit breaker log** | One NOTICE only when transitioning into tripped (in `AiCircuitBreaker::trip()`), not per blocked request |

---

## Manual test checklist

- [ ] **Config schema**: `drush cex` — no schema errors
- [ ] **Admin form**: Visit `/admin/config/myeventlane/ai-usage` — form loads
- [ ] **Menu**: Admin → Configuration → MyEventLane → "AI Usage & Guardrails"
- [ ] **User quota**: Lower `daily_user_token_limit` to 100, make several Vendor AI requests, confirm blocking before provider call
- [ ] **Vendor quota**: Lower `daily_vendor_token_limit`, use Vendor AI as a vendor, confirm blocking
- [ ] **Circuit breaker**: Set `cb_failure_threshold` to 2, force 2 provider failures (e.g. bad API key), confirm circuit trips and blocks further calls (no log spam)
- [ ] **Cost ceiling**: Set `daily_cost_ceiling_usd` to 0.01, make requests until ceiling reached, confirm circuit trips
- [ ] **Vendor resolution**: When escalation has no vendor, Vendor AI still works (user quota only)
- [ ] **Anonymous**: Help Centre AI returns 403 (permission `use help centre ai` required)
