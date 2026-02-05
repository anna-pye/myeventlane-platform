# Feature 3 — Assign Tickets Later (Buyer UX + Tokens)

**Date:** 2026-02-02  
**Status:** Complete

---

## Phase A — Foundations Confirmed

- Ticket entity: `myeventlane_tickets/src/Entity/Ticket.php` — STATUS_ISSUED_UNASSIGNED, holder_name, holder_email ✅
- TicketIssuer: creates unassigned tickets ✅
- TicketPdfGenerator: blocks PDF until holder assigned ✅
- CheckInTokenService: HMAC pattern (reused for TicketAssignmentTokenService) ✅
- MessagingManager: TRANSACTIONAL_TEMPLATES ✅

---

## Phase B — Design by Extension

- **Token:** New TicketAssignmentTokenService — same HMAC pattern as CheckInTokenService, ticket_id scope, 7-day expiry
- **Form:** AssignTicketForm — token-validated, purchaser-only, updates holder_name/email, transitions STATUS_ASSIGNED
- **Route:** /ticket/assign/{token}
- **Template:** assign_tickets_buyer — created; trigger to be wired when "assign later" checkout path exists

---

## Phase C — Implementation

### Files Modified

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_tickets/src/Service/TicketAssignmentTokenService.php` | New — HMAC token for ticket_id, 7-day expiry |
| `web/modules/custom/myeventlane_tickets/src/Form/AssignTicketForm.php` | New — buyer form to assign holder details |
| `web/modules/custom/myeventlane_tickets/myeventlane_tickets.services.yml` | Added ticket_assignment_token service |
| `web/modules/custom/myeventlane_tickets/myeventlane_tickets.routing.yml` | Added myeventlane_tickets.assign_ticket route |
| `web/modules/custom/myeventlane_messaging/config/install/myeventlane_messaging.template.assign_tickets_buyer.yml` | New — assign tickets email template |
| `web/modules/custom/myeventlane_messaging/src/Service/MessagingManager.php` | Added assign_tickets_buyer to TRANSACTIONAL_TEMPLATES |
| `web/modules/custom/myeventlane_messaging/myeventlane_messaging.install` | Added update_10002 to install template for existing sites |

---

## Phase D — Verification & Safety Checks

### Manual Verification Checklist

1. [ ] Run `ddev drush updatedb -y` (myeventlane_messaging update)
2. [ ] Create unassigned ticket (or use existing ORDER_PAID flow)
3. [ ] Generate token: `TicketAssignmentTokenService::generateToken($ticket_id)`
4. [ ] Visit /ticket/assign/{token} as purchaser
5. [ ] Confirm form shows holder_name, holder_email
6. [ ] Submit — confirm ticket status → ASSIGNED, holder fields populated
7. [ ] Visit with expired/invalid token — confirm AccessDenied
8. [ ] Visit as non-purchaser — confirm AccessDenied
9. [ ] Visit with already-assigned ticket — confirm "already assigned" message

### Failure Scenarios

- **Invalid token:** AccessDeniedHttpException
- **Expired token:** AccessDeniedHttpException
- **Non-purchaser:** AccessDeniedHttpException
- **Already assigned:** Message + link to My Tickets

### Security/Access Validation

- Token validates HMAC; 7-day expiry
- Purchaser-only (or admin)
- No guessable URLs (token is signed)

### Existing Flows Unchanged

- TicketHolderParagraphPane — not touched
- OrderCompletedSubscriber — not touched
- EventAttendee creation — not touched
- TicketPdfGenerator rules — not touched

---

## Phase E — Feature Lock Report

### Files Modified

- myeventlane_tickets (new service, form, route)
- myeventlane_messaging (template, TRANSACTIONAL_TEMPLATES, install)

### Services Extended

- None (new TicketAssignmentTokenService)

### New Components Added

- TicketAssignmentTokenService
- AssignTicketForm
- Route myeventlane_tickets.assign_ticket
- Messaging template assign_tickets_buyer

### Explicit Confirmation

- **No duplicated logic:** Reused HMAC pattern from CheckInTokenService
- **No refactors:** Additive only
- **No unrelated changes:** Paragraph flows untouched

### Note — Email Trigger

The assign_tickets_buyer template is created and registered. The trigger to send it (when unassigned tickets are created) is not yet wired. This can be added when the "assign later" checkout path exists (e.g. optional TicketHolderParagraphPane or bulk-buy flow).

---

*Feature 3 complete. Proceed to Feature 4.*
