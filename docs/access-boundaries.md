# Universal Ticket Access Boundaries

Universal entitlement behavior extends existing MEL ticket ownership and event/vendor access. It does not add a second ownership model.

Customer boundary:

- Customers own tickets through `myeventlane_ticket.purchaser_uid`.
- Customer surfaces must query only tickets they own.
- Customers cannot access `mel_redemption_log` records by default.

Vendor and staff boundary:

- Vendor visibility must be scoped to the event/vendor relationship already used by MEL event operations.
- Staff operational access must be scoped to events they can operate.
- Route access and entity access must enforce this server-side; Twig hiding or frontend filtering is not sufficient.

Admin boundary:

- Admins with ticket administration permissions can inspect operational audit records.
- Admin-only data must not leak into customer or vendor wallet surfaces.

Why MEL avoided `mel_entitlement`:

- `myeventlane_ticket` is already the canonical ownership and QR object.
- Checkout, wallet rendering, ticket PDFs, and scanner flows already resolve around tickets.
- A separate entitlement entity would duplicate ownership, create reconciliation risk, and invite a second QR/scanner path.

Phase 2A therefore adds capability fields and services around the existing ticket entity while keeping checkout, wallet, scanner, and Commerce integrations intact.
