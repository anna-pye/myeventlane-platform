# MEL Payment Architecture — Executive Summary

**Date:** 20 July 2026  
**Audience:** Founder · Technical Lead · Future Developer  
**Scope:** Launch readiness of payment / Stripe / Connect / ledger / wallet  
**Mode:** Audit docs + launch remediation on `feature/payment-launch-remediation` (see `docs/release/payment-launch-remediation-report.md`)

---

## 1. Architecture overview

MyEventLane charges customers on the **platform Stripe account** through Drupal Commerce Stripe gateways, then pays vendors later with Stripe **Transfers** against a payout ledger. Vendors onboard via **Stripe Connect Express**. Wallet passes are generated **after** tickets exist and do not participate in charging.

```text
Customer → Commerce checkout → Stripe PaymentIntent (platform)
                ↓
         Order paid → tickets / email / wallet CTAs
                ↓
    (later) Admin metrics may insert ledger rows
                ↓
         Admin Transfer batch → vendor Connect account
                ↓
         Payout webhook updates ledger
```

A custom destination-charge gateway (`stripe_connect`) exists in code but is **not configured**. It is not part of today’s runtime.

---

## 2. Current runtime (verified in DDEV)

| Area | Reality |
| --- | --- |
| Ticket / Boost checkout | Order type `default`, flow `mel_event_checkout` |
| Dominant ticket gateway | `stripe` (Card Element) |
| Also used for tickets | `stripe_pe_recurring` (Payment Element), `mel_stripe_cc` (manual) |
| MEL Pro initial purchase | Same `default` cart; observed on PE gateway |
| Donations | `rsvp_donation` / `platform_donation` → flow `default` → mostly `stripe` |
| Connect destination charges | Plugin present, **0** gateway entities |
| Vendor payouts | Transfers + `myeventlane_payout_ledger` |
| Ledger creation | Lazy side effect of admin KPI code — not on `ORDER_PAID` |
| Wallet | Decoupled from Stripe checkout |

Full detail: `docs/architecture/payment-runtime-map.md`, `payment-gateway-runtime.md`, `payment-runtime-matrix.md`.

---

## 3. Launch readiness

| Capability | Ready? | Notes |
| --- | --- | --- |
| Collect ticket money (platform Stripe) | **Yes** (post-remediation) | Manual admin-only; PE scoped to Pro; keep platform api_keys on `stripe` |
| Boost / Pro / donations charging | **Yes** (gateway matrix) | Boost/donations → `stripe`; Pro → `stripe_pe_recurring` |
| Vendor Connect onboarding | **Yes** (feature-complete path) | Express Account Links via `StripeService` |
| Vendor payouts | **Conditional** | New ledger inserts scoped; historical pollution + CF-006 timing remain |
| Destination-charge marketplace | **No** | Not wired; do not enable for launch |
| Wallet after purchase | **Yes** (payment-boundary) | Depends on ticket issuance/refund status, not Stripe PI |
| Webhooks for Transfers | **Ops-dependent** | Secrets must be set in real environments |

---

## 4. Known risks (genuine blockers)

Read first: `docs/architecture/payment-critical-findings.md`.

1. **Model ambiguity** — Code suggests Connect destination charges; runtime is platform collect + Transfer.  
2. **Manual gateway** — Real ticket orders completed without Stripe in this environment.  
3. **Ledger gaps** — Rows created when KPIs run, not when orders pay.  
4. **Ledger pollution** — Donations, Boost, and Pro appear as unpaid vendor liabilities.  
5. **Deploy credential drift** — Sync YAML empty keys vs active OAuth-style gateway auth.  
6. **Gateway sprawl** — Ticket carts can use Card Element, Payment Element (off_session), or manual.

Risk register: `docs/launch/payment-launch-risk-register.md`.

---

## 5. Future roadmap

| Horizon | Work |
| --- | --- |
| Pre-launch (ops/config — out of this audit’s write scope) | Ratify Option A; disable manual gateway in prod; set secrets/webhooks; freeze payout batches until ledger rules clear |
| Immediately post-launch | Ledger write on `ORDER_PAID` with allowlist; gateway conditions by product/order type; fee SoT cleanup |
| Later | Revisit true destination charges only via ADR-003 Option B with Transfer double-pay controls |
| Ongoing | Keep Commerce Stripe current; treat unused PI helpers / Connect plugin as dormant until checklist deletion |

ADRs: `docs/adr/ADR-002-payment-runtime.md`, `docs/adr/ADR-003-stripe-connect-strategy.md`.

---

## 6. Technical debt (short)

| Bucket | Examples |
| --- | --- |
| Safe after launch | Dead `createPaymentIntentForTicketSale/Boost`; legacy donation pane |
| Product decision | A vs B; Connect hard gate; what belongs on ledger |
| Architecture decision | Ledger writer; gateway condition matrix; webhook strategy |
| Dormant | `stripe_connect` plugin; unwired validation subscriber |

Detail: `docs/architecture/payment-technical-debt.md`.

---

## 7. Recommended post-launch work (priority)

1. **Ledger v2** — Insert on paid vendor-revenue orders only; KPI becomes backfill.  
2. **Gateway policy** — One ticket gateway; PE reserved for Pro/recurring; manual local-only.  
3. **Fee alignment** — Single commission/application-fee definition for finance reports.  
4. **Connect gate** — If required, fail closed before payment (not log-only).  
5. **Option B evaluation** — Only after A is stable and product demands instant splits.

---

## 8. Confidence score

| Topic | Confidence |
| --- | --- |
| Checkout uses platform Commerce Stripe (not destination-charge plugin) | **High** |
| Transfers + ledger are the payout path | **High** |
| Ledger lazy + contaminated in this DB | **High** |
| Production cron/frequency of KPI inserts | **Low** (not proven) |
| Production webhook secrets / deploy overlays | **Environment-specific** — verify per env |
| Wallet ↔ Stripe decoupling | **High** |

**Overall architecture understanding confidence: 8/10**  
**Overall payout operational safety confidence: 3/10** until CF-006/007 addressed.

---

## 9. Go / No-Go recommendation

### Ticket & donation collection (platform Stripe)

**Conditional GO** — if Product accepts **Option A**, production disables `mel_stripe_cc`, and deploy injects working Stripe credentials.

### Vendor payouts (Transfers)

**NO-GO** — until ledger eligibility and creation guarantees are fixed or manually controlled with a written finance SOP that excludes non-vendor-revenue orders.

### Destination-charge Connect checkout

**NO-GO** — not configured; do not enable for launch.

### Wallet

**GO** from a payment-boundary perspective (not a Stripe charge dependency). Separate wallet credential/go-live checklist still applies.

---

## 10. Document index for a new developer

| Doc | Purpose |
| --- | --- |
| `docs/architecture/payment-critical-findings.md` | Blockers first |
| `docs/architecture/payment-runtime-map.md` | Full Phase 1 map |
| `docs/architecture/payment-component-lifecycle.md` | Every component status |
| `docs/architecture/payment-runtime-matrix.md` | Flow × gateway × webhook × ledger |
| `docs/architecture/payment-gateway-runtime.md` | Why Commerce picks gateways |
| `docs/architecture/payment-sequence-diagrams.md` | Mermaid sequences |
| `docs/architecture/payment-ledger-review.md` | Lazy ledger analysis |
| `docs/architecture/wallet-payment-boundary.md` | Wallet isolation |
| `docs/architecture/payment-technical-debt.md` | Debt buckets |
| `docs/adr/ADR-002-*.md` / `ADR-003-*.md` | Current vs Connect strategy |
| `docs/launch/payment-launch-risk-register.md` | Risks & owners |

No assumptions: every operational claim in this set cites code path and/or DDEV runtime evidence dated 20 July 2026.
