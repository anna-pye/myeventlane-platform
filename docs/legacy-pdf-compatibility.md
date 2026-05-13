# Legacy PDF compatibility (governance)

This document is a **policy firewall** between **operational architecture** and **compatibility preservation**. It exists so contributors (human or AI) do not “simplify,” “unify,” or “modernize” legacy PDF entry points in ways that collapse operational truth, customer-visible output, or scanner continuity.

**Scope:** Phase 2B — Commit 5 and onward. Code changes must stay inside the rules here unless product and engineering explicitly expand scope.

**Related:** [issuance-pipeline.md](./issuance-pipeline.md) (canonical issuance and attachment orchestration).

---

## 1. Operational authority

**Canonical ticket PDFs** (issued `myeventlane_ticket` rows) are the **only** PDF path that represents **operational entitlement truth**.

- **Authority chain:** `myeventlane_ticket` → `UniversalTicketViewModelBuilder` → `TicketPdfGenerator` (ticket methods) → Twig / Dompdf.
- **TicketIssuer** and **ORDER_PAID** issuance define when ticket rows exist; PDFs for those rows must assume ticket entities as source of truth for QR and entitlement metadata.
- **Canonical flows must never delegate outward** through legacy compatibility adapters (see [Forbidden patterns](#5-forbidden-patterns)).

---

## 2. Compatibility purpose

**Legacy PDF paths** exist only for **historical continuity** and **non-ticket** artifacts:

- **Commerce order-item** PDFs (legacy admission representation without an issued ticket, or historical orders).
- **RSVP** virtual ticket PDFs.
- **Event attendee** PDF fallbacks.

These paths are **not** allowed to become authoritative for operational entitlement, scanner state, or post-issuance orchestration. They may **bridge** old inputs into shared rendering where explicitly allowed.

---

## 3. Delegation rules

**Inward-only delegation**

- **Allowed:** `Legacy source → compatibility adapter → canonical normalization / TicketPdfGenerator → artifact`
- **Forbidden:** `Canonical ticket PDF → compatibility adapter → artifact` (see below).

Compatibility code may **call into** shared building blocks used by canonical paths (e.g. shared render helpers, `TicketPdfGenerator` internal pipeline where contract-safe), but must not **redefine** operational semantics or duplicate QR signing outside `TicketQrPayload` / canonical ticket flows.

---

## 4. Frozen contracts (immutable unless explicitly re-scoped)

Treat the following as **regression-sensitive contracts**, not implementation details:

| Contract | Notes |
| --- | --- |
| **QR payload structure and signing** | Payload formats and HMAC behavior are scanner- and history-sensitive. |
| **Route structure** | Ticket PDF download URLs and public entry points must remain stable. |
| **Attachment filenames and MIME** | Order confirmation and mail attachments must preserve expected names and `application/pdf` semantics. |
| **Guest access behavior** | Anonymous or guest flows must not lose access to entitled artifacts where they currently have it. |
| **Customer-visible PDF layout** | No **material** visual or layout regressions in production PDFs without explicit design sign-off. |

**Allowed to evolve** behind the same contracts: internal field extraction, normalization delegation, render array shaping, and duplication reduction **that does not change** the frozen outputs above.

---

## 5. Forbidden patterns

The following are **explicitly disallowed**. They must not appear in new code and should be removed if found during review.

| Anti-pattern | Why |
| --- | --- |
| **Canonical ticket PDF → compatibility adapter → render** | Re-introduces ambiguity; compatibility must not sit in authority over ticket entities. |
| **Compatibility adapter → scanner metadata generation** | Scanner operational logic belongs to ticket/scanner services, not legacy PDF bridges. |
| **Legacy order-item PDF → operational entitlement inference** | Order items are **purchase context**; entitlement truth is `myeventlane_ticket` after issuance. |
| **RSVP or attendee PDF path mutating or issuing `myeventlane_ticket`** | Issuance belongs to **TicketIssuer** on **ORDER_PAID** only. |
| **Changing frozen contracts “for cleanup”** | Violates customer, scanner, and historical-order safety. |

---

## Review checklist (Commit 5)

- [ ] No canonical ticket PDF path imports or calls legacy-only adapters.
- [ ] Legacy paths only narrow, adapt, or delegate inward; they do not assert entitlement ownership.
- [ ] Tests or snapshots cover frozen contracts where automated (routes, MIME, filenames, representative QR strings as approved).
- [ ] Documentation updated if any **allowed** internal convergence changes contributor expectations.
