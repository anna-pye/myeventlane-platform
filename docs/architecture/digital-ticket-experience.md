# Digital ticket experience (ACE Phase 4)

Customer-facing **digital event pass** — the primary attendee experience for issued tickets. Presentation-only; does not create a second ticket, QR, PDF, wallet, or check-in system.

Related:

- [account-dashboard-bookings-architecture.md](./account-dashboard-bookings-architecture.md)
- [event-readiness.md](./event-readiness.md)
- [../operations/wallet-configuration.md](../operations/wallet-configuration.md)
- [../wallet-operational-convergence.md](../wallet-operational-convergence.md)
- [../issuance-pipeline.md](../issuance-pipeline.md)

---

## Official platform comparison (canonical sources)

Treated as authoritative (not blog posts):

| Platform | Spec | MEL mapping |
|----------|------|-------------|
| Apple Wallet Passes | [Wallet Passes](https://developer.apple.com/documentation/walletpasses), [Building a Pass](https://developer.apple.com/documentation/walletpasses/building_a_pass) | `pass.json` + SHA-1 `manifest.json` + PKCS#7 detached `signature` → zip as `.pkpass` |
| Apple pass style | Event ticket (`Pass.EventTicket`) | `PkPassBuilder` emits `eventTicket` fields + QR barcode from Universal VM |
| Google Wallet | [Generic Pass](https://developers.google.com/wallet/generic), [JWT](https://developers.google.com/wallet/generic/use-cases/jwt) | Signed JWT with `payload.genericClasses` + `payload.genericObjects`; save URL `https://pay.google.com/gp/v/save/{jwt}` |

### Architectural differences

| Topic | Apple | Google |
|-------|-------|--------|
| Artifact | Signed zip package (`.pkpass`) | Signed JWT URL (no local package) |
| Content model | Style-specific keys (`eventTicket`, `boardingPass`, …) | Class + Object; MEL uses **Generic Pass** |
| Signing | Pass Type ID cert + WWDR intermediate (PKCS#7) | Service account RS256 JWT |
| Distribution | HTTP download of pass file | HTTPS redirect to Google save endpoint |
| Updates | Optional PassKit web service (not MEL yet) | REST API object updates (not MEL yet) |

### Deprecated / avoided patterns

- Serving unsigned scaffold JSON as `.pkpass` (removed for issued tickets).
- Google JWT using `eventTicketClasses` / `eventTicketObjects` for this product cut — **replaced** with official Generic Pass JWT shape per product brief + Generic Pass docs.
- Hard-coded `WalletPresentationGate` `FALSE` (replaced with capability diagnosis).
- Embedding secrets in `config/sync`.

### Missing / future platform capabilities

- Apple PassKit update web service (push updates / void).
- Google Wallet REST pre-create of classes before JWT (optional; JWT JIT class+object is officially supported).
- Official branded “Add to … Wallet” image assets in Twig (text CTAs today — see branding notes).

---

## Ownership map

| Purpose | Owner | Route | Status | Duplicate risk |
|---------|-------|-------|--------|----------------|
| Issued ticket | `Ticket` | Admin | Implemented | Low |
| Canonical VM | `UniversalTicketViewModelBuilder` | Consumers | Implemented | **Do not fork** |
| Digital pass page | `MyTicketsController` · `MyTicketsOrderViewModelBuilder` | `/my-tickets/order/{commerce_order}` | Implemented | Low |
| QR | `TicketQrPayload` + `QrCodeGenerator` | — | Implemented | **Do not fork** |
| PDF | `TicketPdfGenerator` | `/ticket/{ticket_code}/pdf` | Implemented | Low |
| Apple Wallet | `WalletAppleController` · `PkPassBuilder` · `WalletSigner` | `/wallet/apple/{order_item_id}` | **Implemented** | Do not invent parallel stack |
| Google Wallet | `WalletGoogleController` · `GoogleWalletBuilder` | `/wallet/google/{order_item_id}` | **Implemented** (Generic Pass JWT) | Do not invent parallel stack |
| Gate | `WalletPresentationGate` | — | Implemented (capability diagnosis) | No hard FALSE |
| Access | `WalletDownloadAccessChecker` + `WalletTicketResolver` | Controllers | Implemented | Ownership + void/cancelled |

No duplicate ownership found for wallet artifact generation.

---

## Digital Pass hierarchy

```
Event → Large QR (hero) → Ticket → Wallet buttons → PDF
       → Collapsed Event Readiness → Collapsed Purchase details
```

Wallet buttons appear only when the gate emits actions (Apple only / Google only / both / neither). Accordions must not hide QR or ticket identity from assistive tech.

---

## Attendee surface matrix (Digital Pass actions)

### Product rule — wallet save opportunities

When wallet capability is available, expose **Add to Apple Wallet** / **Add to Google Wallet** in all three post-purchase moments where authenticated attendees already have ownership:

1. **Booking confirmation page** (immediate — reduce “find it later” friction)
2. **Booking confirmation email** (`shouldEmitWalletInEmail`)
3. **Digital Pass page** (`shouldEmitWalletActions` via UTVM)

Same canonical wallet routes and ownership checks everywhere. Hide completely when unavailable — never disabled buttons.

Guests remain without authenticated pass/wallet URLs (attachments only) until an account owns the booking.

Canonical URLs (do not duplicate):

| Action | Route / path |
|--------|----------------|
| View Digital Pass | `myeventlane_checkout_flow.order_detail` → `/my-tickets/order/{commerce_order}` |
| Apple Wallet | `myeventlane_wallet.apple` → `/wallet/apple/{order_item_id}` |
| Google Wallet | `myeventlane_wallet.google` → `/wallet/google/{order_item_id}` |
| Download PDF | `myeventlane_tickets.download_pdf_by_code` → `/ticket/{ticket_code}/pdf` |
| Manage Booking | `myeventlane_checkout_flow.my_tickets` → `/my-tickets` |

| Surface | View Digital Pass | Apple Wallet | Google Wallet | Download PDF | Notes |
|---------|-------------------|--------------|---------------|--------------|-------|
| Digital Pass page | Page itself | Gate (`shouldEmitWalletActions`) | Gate | Yes (UTVM) | Ownership: `MyTicketsOrderAccess` + wallet checker |
| Booking confirmation page | Auth: yes | Gate (`shouldEmitWalletActions`) — **required** | Gate — **required** | Optional / prefer pass | **Target UX.** Wire via continuity presenter + same routes as pass page; guests: no wallet URLs |
| Booking confirmation email | Auth: yes | Gate (`shouldEmitWalletInEmail`) | Gate | Auth: yes | Guests: attachments only; no pass/wallet URLs |
| Dashboard (hub) | Yes (primary CTA) | No | No | Yes (secondary when URL exists) | Hub → pass page for wallet |
| My Bookings | Yes (“Open Digital Pass”) | No | No | No | Wallet/PDF on pass page |
| Reminder 7-day | Yes (CTA → order detail) | No | No | No | Label: View Digital Pass |
| Reminder 24-hour | Yes (CTA → order detail) | No | No | No | Label: View Digital Pass |
| Refund confirmation (buyer) | Yes (CTA → order detail) | No | No | No | Open pass for cancelled state; wallet omitted (not save-opportunity) |

Wallet actions are omitted entirely when the gate reports unavailable — never shown disabled.

---

## Extension points

1. `WalletPresentationGate` — capability only.
2. `PkPassBuilder` / `WalletSigner` — Apple layout/assets; keep QR from Universal VM.
3. `GoogleWalletBuilder` — Generic class/object fields only.
4. `order.readiness.detail_items` — accordion projection only.

---

## Validation checklist

```bash
git diff --check
composer validate --no-check-publish
ddev drush cr
npm run mel:lint
npm run mel:build
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletPresentationGateTest.php \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletSignerTest.php \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletDownloadAccessCheckerTest.php \
  --do-not-cache-result
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_tickets/tests/src/Kernel/IssuancePipelineConvergenceTest.php \
  --do-not-cache-result
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_tickets/tests/src/Unit/TicketQrPayloadSecretTest.php \
  web/modules/custom/myeventlane_tickets/tests/src/Kernel/UniversalTicketViewModelBuilderTest.php \
  web/modules/custom/myeventlane_checkout_flow/tests/src/Kernel/MyTicketsOrderViewModelBuilderTest.php \
  --do-not-cache-result
```
