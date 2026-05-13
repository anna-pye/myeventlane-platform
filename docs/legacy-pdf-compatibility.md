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

- **Allowed:** `Legacy source → compatibility adapter → shared render preparation (Dompdf pipeline, event metadata helper) → artifact`
- **Allowed:** `Issued ticket → UniversalTicketViewModelBuilder → TicketPdfGenerator (canonical methods) → same Dompdf pipeline → artifact`
- **Forbidden:** `Canonical ticket PDF → compatibility adapter → artifact` (see below).

Compatibility code may **call into** shared building blocks used by canonical paths (e.g. `TicketPdfAttachmentRenderer`, `TicketPdfEventMetadataHelper`), but must not **redefine** operational semantics or duplicate QR signing outside `TicketQrPayload` / canonical ticket flows.

---

## 3a. Compatibility adapter services (Commit 5)

Explicit adapters quarantine legacy entry points. They **normalize** non-ticket inputs and **delegate inward** to shared helpers; they do **not** own rendering rules, Dompdf, or scanner metadata.

| Service ID | Class | Role |
| --- | --- | --- |
| `myeventlane_tickets.pdf_compatibility.order_item` | `OrderItemPdfCompatibilityAdapter` | Commerce order-item PDFs; resolves event (target event → variation `field_event` → **product** `field_event` when present, matching issuance-style resolution for catalog-backed items). |
| `myeventlane_tickets.pdf_compatibility.rsvp` | `RsvpPdfCompatibilityAdapter` | RSVP virtual ticket PDFs from submission entity or array shapes. |
| `myeventlane_tickets.pdf_compatibility.event_attendee` | `EventAttendeePdfCompatibilityAdapter` | `event_attendee` fallback PDFs when order lines are missing. |

Shared inward-only helpers (not legacy adapters):

| Service ID | Class | Role |
| --- | --- | --- |
| `myeventlane_tickets.ticket_pdf_attachment_renderer` | `TicketPdfAttachmentRenderer` | Single Dompdf + `RendererInterface::renderInIsolation()` pipeline for all ticket PDF bytes. |
| `myeventlane_tickets.ticket_pdf_event_metadata_helper` | `TicketPdfEventMetadataHelper` | Legacy-frozen event title / start / **field_location-only** location line (no `field_venue_name` shortcut) for compatibility PDFs and for ticket fallback when the view model builder is absent. |

**Prohibited dependency direction:** compatibility adapter classes must not be referenced from `UniversalTicketViewModelBuilder` or from canonical branches inside `TicketPdfTemplateBuilder` / `TicketPdfGenerator::buildPdfFromCanonicalModel()`. `TicketPdfGenerator` remains the customer-facing façade: it wires adapters for **legacy public methods only**; issued-ticket paths stay on the view model + shared renderer.

---

## 4. Frozen contracts (immutable unless explicitly re-scoped)

Treat the following as **regression-sensitive contracts**, not implementation details:

| Contract | Notes |
| --- | --- |
| **QR payload structure and signing** | Payload formats and HMAC behavior are scanner- and history-sensitive. |
| **Route structure** | Ticket PDF download URLs and public entry points must remain stable. |
| **Attachment filenames and MIME** | Order confirmation and mail attachments must preserve expected names and `application/pdf` semantics. |
| **Guest access behavior** | Anonymous or guest flows must not lose access to entitled artifacts where they currently have it. Wallet inward resolution follows the same ownership semantics as ticket PDFs (see [wallet-operational-convergence.md](./wallet-operational-convergence.md)). |
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

- [x] No canonical ticket PDF path imports or calls legacy-only adapters.
- [x] Legacy paths only narrow, adapt, or delegate inward; they do not assert entitlement ownership.
- [x] Tests or snapshots cover frozen contracts where automated (routes, MIME, filenames, representative QR strings as approved).
- [x] Documentation updated if any **allowed** internal convergence changes contributor expectations.
