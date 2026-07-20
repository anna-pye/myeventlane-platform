# MEL Payment Launch Risk Register

**Status:** READ-ONLY Phase 2  
**Date:** 20 July 2026  
**Audience:** Founder, Technical Lead, Launch Manager  
**Evidence base:** [`payment-critical-findings.md`](../architecture/payment-critical-findings.md), Phase 2 DDEV verification

**Launch blocker column:** `Yes` only for genuine blockers to safe public money movement / vendor payouts. Ticket collection may still operate under Option A with ops constraints.

---

## Register

| Risk | Likelihood | Impact | Mitigation | Evidence | Owner | Launch Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Destination-charge Connect gateway unused; funds stay on platform | Certain (current wiring) | High — marketplace split not at PI time | Accept Option A (platform collect + Transfer) explicitly; do not market destination charges | CF-001; entities_with_plugin_stripe_connect=0 | Product + Tech Lead | **Yes** until model A/B decided and communicated |
| Ledger rows missing until admin KPI runs | High | Critical — vendors omitted from payouts | Ops backfill SOP before batches; post-launch `ORDER_PAID` insert | CF-006; `PlatformMetricsService::buildKpis` | Tech Lead + Ops | **Yes** for **vendor payout** go-live |
| Ledger includes donations, Boost, Pro as unpaid vendor net | Certain in DDEV data (historical) | Critical — wrong Transfers / economics | **Partial mitigate:** new inserts allowlisted; historical rows remain — do not unrestricted batch | CF-007; remediation report | Tech Lead + Finance | **Yes** until historical cleanup |
| Manual gateway enabled; tickets completable without Stripe | High (already used) | Critical — unpaid “completed” tickets | **Mitigated:** admin role condition + filter subscriber; entity preserved | CF-008; remediation report | Tech Lead | **Mitigated** for customer checkout |
| Ticket checkout can use Pro PE off_session gateway | High (11 ticket payments) | High — wrong PM usage / UX / renewals confusion | **Mitigated:** PE conditions + filter; Pro/recurring forced to PE | CF-008; remediation report | Tech Lead | **Mitigated** |
| Card Element uses Connect access_token vs platform publishable key | Certain when token set | Critical — `No such PaymentMethod` at checkout | **Mitigated (env):** clear access_token; api_keys auth for Option A | CF-009 | DevOps + Tech Lead | **Mitigated** when deploy keeps token empty |
| Connect validation subscriber unwired | Certain | Medium–High — vendors sell without Connect readiness | Product: hard gate vs soft; wire fail-closed if required | CF-003; COMPLETION listeners | Product + Tech Lead | Conditional — **Yes** if Connect required before sale |
| Config sync vs active gateway auth/keys drift | High | High — broken payments after deploy from sync alone | Deploy runbook: inject secrets/OAuth; never commit secrets | CF-005 | DevOps | **Yes** if prod deploy uses empty sync keys without overlay |
| Payout webhook secret empty (local; may be empty elsewhere) | Medium | High — ledger not reconciled from Transfers | Set secrets in staging/prod; verify signature path | Phase 1 Stage 8; DDEV empty | DevOps | **Yes** for automated payout reconcile |
| Pro subscription webhook audit-only mistaken for SoT | Medium | Medium — false ops confidence | Document Commerce Recurring as authority | Pro webhook controller behaviour | Tech Lead | No |
| Application fee calc exists but unused at charge time | Certain | Medium — reporting vs reality diverge | Align fee SoT with Option A Transfers | `StripeConnectPaymentService` vs metrics `commission_rate` | Finance + Tech | Conditional |
| Unused direct PI helpers confuse future fixes | Medium | Medium — wrong code path edited | Mark dormant in docs; no delete yet | CF-004 | Tech Lead | No |
| Double destination charge + Transfer | Low today | Critical if Option B wired carelessly | Keep `stripe_connect` entity absent until ADR; never enable both splits | CF-001 + Transfer stack | Tech Lead | No (today); **Yes** if Option B without retiring Transfers |
| Wallet download after refund still possible | Low–Medium | Medium — invalid admission artifact | Ticket status must flip; access checker blocks void/refunded | `WalletDownloadAccessChecker` | Tech Lead | No (payment); ticket status QA required |
| Off-session vendor auto-bill not on cron | Medium | Medium — invoices unpaid | Explicit Drush/admin process | No cron proven | Ops | No |
| Commerce payment webhooks unused / secrets empty | Medium | Medium — async edge cases | Confirm sync checkout sufficient; add webhooks if needed later | Gateway webhook secrets empty | Tech Lead | No for sync-happy path |
| Refund complexity under future Connect destination charges | Low now | High later | Stay on Option A for launch | ADR-003 | Tech Lead | No (current model) |

---

## Genuine launch blockers (highlight)

1. **Marketplace model decision (A vs B)** — CF-001.  
2. **Production manual gateway** — CF-008.  
3. **Ledger correctness (timing + order-type scope)** — CF-006, CF-007 — blocks **safe vendor payouts**.  
4. **Deployable Stripe credentials** — CF-005.  
5. **Payout webhook secrets** — for Transfer reconcile.

### Explicit non-blockers for ticket *collection* (if Option A accepted)

- Unused `stripe_connect` plugin (as long as Transfers are the payout plan).  
- Wallet Stripe decoupling (wallet is fine architecturally).  
- Pro audit webhook being non-mutating.

---

## Suggested ownership defaults

| Owner | Scope |
| --- | --- |
| Product | A vs B model; Connect hard-gate policy |
| Tech Lead | Gateway conditions; ledger design; ADR close-out |
| Finance | Commission/net definitions; payout batch policy |
| DevOps | Secrets, webhooks, env overlays |
| Ops | Pre-batch ledger review until automation exists |
