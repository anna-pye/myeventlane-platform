# Wallet production audit

Audit date: 2026-07-14  
Branch context: wallet production readiness (documentation only)  
Official references: [Apple Wallet Passes](https://developer.apple.com/documentation/walletpasses), [Google Wallet Generic Pass](https://developers.google.com/wallet/generic)

**Verdict:** There is **one** Apple stack, **one** Google Generic Pass JWT stack, **one** presentation gate, **one** Digital Pass surface owner, **one** QR owner, and **one** PDF owner. **No hard STOP** for duplicated artifact generation.

Related:

- [digital-ticket-experience.md](./digital-ticket-experience.md)
- [../operations/wallet-configuration.md](../operations/wallet-configuration.md)
- [../operations/apple-wallet-deployment.md](../operations/apple-wallet-deployment.md)
- [../operations/google-wallet-deployment.md](../operations/google-wallet-deployment.md)
- [../operations/wallet-assets-checklist.md](../operations/wallet-assets-checklist.md)
- [../launch/wallet-go-live.md](../launch/wallet-go-live.md)

---

## 1. Ownership (canonical)

| Concern | Single owner | Service / route / class |
|---------|--------------|-------------------------|
| Apple Wallet (.pkpass) | `myeventlane_wallet` | `myeventlane_wallet.pkpass_builder` → `PkPassBuilder` |
| Apple PKCS#7 signing | `myeventlane_wallet` | `myeventlane_wallet.wallet_signer` → `WalletSigner` |
| Apple download | `myeventlane_wallet` | Route `myeventlane_wallet.apple` → `WalletAppleController::download` → `/wallet/apple/{order_item_id}` |
| Google Wallet (Generic Pass JWT) | `myeventlane_wallet` | `myeventlane_wallet.google_wallet_builder` → `GoogleWalletBuilder` |
| Google save redirect | `myeventlane_wallet` | Route `myeventlane_wallet.google` → `WalletGoogleController::link` → `/wallet/google/{order_item_id}` |
| Wallet gate | `myeventlane_wallet` | `myeventlane_wallet.presentation_gate` → `WalletPresentationGate` |
| Ticket resolution | `myeventlane_wallet` | `myeventlane_wallet.ticket_resolver` → `WalletTicketResolver` |
| Download access | `myeventlane_wallet` | `myeventlane_wallet.download_access` → `WalletDownloadAccessChecker` |
| Digital Pass page | `myeventlane_checkout_flow` | Route `myeventlane_checkout_flow.order_detail` → `MyTicketsController::orderDetail` |
| QR payload + image | `myeventlane_tickets` | `TicketQrPayload` + `QrCodeGenerator` (via `UniversalTicketViewModelBuilder`) |
| PDF | `myeventlane_tickets` | `TicketPdfGenerator` → `/ticket/{ticket_code}/pdf` |
| Admin settings | `myeventlane_wallet` | `WalletSettingsForm` → `/admin/config/myeventlane/wallet` |

Module root (live only): `web/modules/custom/myeventlane_wallet/`.  
No live duplicate under `modules/custom/myeventlane_wallet`.

### Ownership consumers (must not mint artifacts)

| Consumer | Role |
|----------|------|
| `UniversalTicketViewModelBuilder` | Emits wallet action URLs when gate allows; QR from `TicketQrPayload` |
| `MelCustomerContinuityPresenter` | Booking confirmation wallet CTAs via gate + same routes |
| `OrderConfirmationQueueBuilder` | Confirmation email wallet URLs via gate + same routes |
| `CustomerHubDataBuilder` | Deep-links to Digital Pass / PDF only — no wallet minting |
| `MelIntelligenceManager` | Optional wallet prompt filter via gate |

---

## 2. Dependencies

### Services (`myeventlane_wallet.services.yml`)

| Service ID | Class | Key dependencies |
|------------|-------|------------------|
| `myeventlane_wallet.download_access` | `WalletDownloadAccessChecker` | `@config.factory` |
| `myeventlane_wallet.ticket_resolver` | `WalletTicketResolver` | `@entity_type.manager` |
| `myeventlane_wallet.wallet_signer` | `WalletSigner` | `@config.factory`, `@logger.channel.myeventlane_wallet` |
| `myeventlane_wallet.pkpass_builder` | `PkPassBuilder` | file system, UTVM, wallet signer, config, module extension list, logger |
| `myeventlane_wallet.google_wallet_builder` | `GoogleWalletBuilder` | config, UTVM, logger |
| `myeventlane_wallet.presentation_gate` | `WalletPresentationGate` | config, wallet signer |

Module dependencies (`myeventlane_wallet.info.yml`): `myeventlane_core`, `myeventlane_tickets`, `commerce_order`.

Optional DI into tickets / surface / messaging uses `@?myeventlane_wallet.presentation_gate` so those modules degrade safely when wallet is off.

### Secrets / paths (never config/sync)

Loaded in `web/sites/default/settings.mel_shared_session.php` → `$settings['myeventlane_wallet']`.

---

## 3. Configuration

### Drupal config object: `myeventlane_wallet.settings`

| Key | Purpose |
|-----|---------|
| `apple_enabled` | Feature toggle |
| `apple_team_id` | Team Identifier in `pass.json` |
| `apple_pass_type_id` | Pass Type Identifier in `pass.json` |
| `apple_organisation_name` | Org / logo text; also reused as Google `cardTitle` issuer label |
| `google_enabled` | Feature toggle |
| `google_issuer_id` | Numeric Google Issuer ID only |
| `show_wallet_buttons` | UI CTA emission (`shouldEmitWalletActions`) |
| `show_wallet_in_email` | Email CTA emission (`shouldEmitWalletInEmail`) |

Schema: `web/modules/custom/myeventlane_wallet/config/schema/myeventlane_wallet.schema.yml`  
Sync export: `config/sync/myeventlane_wallet.settings.yml`

### Environment → `$settings['myeventlane_wallet']`

| Env var | Settings key |
|---------|--------------|
| `MEL_WALLET_APPLE_CERTIFICATE_PATH` | `apple_certificate_path` |
| `MEL_WALLET_APPLE_PRIVATE_KEY_PATH` | `apple_private_key_path` |
| `MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD` | `apple_private_key_password` |
| `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH` | `apple_wwdr_certificate_path` |
| `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON` | `google_service_account_json_path` |
| `MEL_WALLET_GOOGLE_ORIGINS` | `google_origins` (array) |

Optional origin fallback (not env-mapped in shared settings today): `$settings['myeventlane_wallet_public_origin']`, then Drupal `$base_url`.

No legacy alternate env names for wallet credentials were found in the repository.

---

## 4. Runtime flow

```
Order paid → TicketIssuer → myeventlane_ticket
        ↓
Surface asks WalletPresentationGate
  (shouldEmitWalletActions / shouldEmitWalletInEmail)
        ↓ (if presentable + show flags)
Customer opens /wallet/apple|{google}/{order_item_id}
        ↓
Controller loads commerce_order_item
        ↓
WalletTicketResolver::resolvePrimaryTicketForOrderItem()
        ↓
WalletDownloadAccessChecker::assertAuthorized()
        ↓
Apple: PkPassBuilder::generate() → WalletSigner::sign()
       → BinaryFileResponse application/vnd.apple.pkpass
Google: GoogleWalletBuilder::generateSaveLink()
       → 302 https://pay.google.com/gp/v/save/{jwt}
```

When credentials are missing:

| Layer | Behaviour |
|-------|-----------|
| Gate | Capability FALSE → no buttons / no email wallet URLs |
| Direct Apple hit | HTTP 503 — “Apple Wallet pass is temporarily unavailable.” |
| Direct Google hit | HTTP 503 — “Google Wallet is temporarily unavailable.” |

Controllers do **not** re-check `apple_enabled` / `google_enabled`; those flags only gate presentation. Generation still requires real credentials.

Guests: confirmation page continuity and confirmation email **never** emit wallet URLs.

---

## 5. No duplicated logic (STOP conditions)

### Hard STOP checks — PASS

| Check | Result |
|-------|--------|
| Second Apple builder / signer / route | **None** |
| Second Google JWT builder / route | **None** |
| Parallel WalletPresentationGate | **None** |
| Wallet-specific QR generator | **None** (uses UTVM → `TicketQrPayload`) |
| Wallet-specific PDF generator | **None** |
| Second Digital Pass page | **None** |
| Google Event Ticket JWT (`eventTicketClasses` / `eventTicketObjects`) | **Not used** — Generic Pass only |

### Soft residual risks (not second implementations)

| Item | Notes |
|------|-------|
| Triplicated CTA URL assemblers | UTVM, `MelCustomerContinuityPresenter`, `OrderConfirmationQueueBuilder` — same gate + same routes; do not diverge labels/paths |
| Google readiness probe in gate | Intentional duplicate of `GoogleWalletBuilder::isReady()` to avoid DI cycle; keep rules aligned |
| Legacy `wallet-buttons.html.twig` | Orphan fragment; live CTAs are order-detail + confirmation Twig |
| Unregistered `TicketUpdatedSubscriber` | Stub; not in services.yml |
| Legacy empty / placeholder artifacts | No-ticket path returns empty `.pkpass` or `…/save/placeholder` after access check |

**Audit decision:** Proceed with production deployment documentation. Do **not** add another wallet module, route, builder, or gate.

---

## 6. Platform model confirmation

| Platform | MEL model | Evidence |
|----------|-----------|----------|
| Apple | Event ticket style (`eventTicket` fields) + QR barcode | `PkPassBuilder::buildPassJson()` |
| Google | **Generic Pass** (`genericClasses` + `genericObjects`) | `GoogleWalletBuilder::buildSignedJwt()` |

This split is intentional: Apple uses PassKit Event Ticket style; Google uses official Generic Pass JWT (not Google Event Tickets).

---

## 7. Configuration audit summary

Canonical names only — verified across env loading, settings keys, Drupal schema, form, gate, signers, and builders:

**Apple env:** `MEL_WALLET_APPLE_CERTIFICATE_PATH`, `MEL_WALLET_APPLE_PRIVATE_KEY_PATH`, `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH`, `MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD`

**Google env:** `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON`, `MEL_WALLET_GOOGLE_ORIGINS`

**Drupal config:** `apple_team_id`, `apple_pass_type_id`, `apple_organisation_name`, `google_issuer_id`, `show_wallet_buttons`, `show_wallet_in_email`, plus `apple_enabled` / `google_enabled`

No duplicate credential env aliases found.

Current sync blockers for live CTAs:

1. Production signing PEMs / WWDR not installed (gate Apple FALSE until `WalletSigner::isReady()`).
2. `google_issuer_id` empty in `config/sync` (gate Google FALSE until numeric issuer + SA JSON).
3. Buttons remain correctly suppressed until both capability probes pass — do not bypass `WalletPresentationGate`.

---

## 8. Test coverage (repository)

| Test | Path |
|------|------|
| Presentation gate | `web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletPresentationGateTest.php` |
| Signer readiness | `web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletSignerTest.php` |
| Download access | `web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletDownloadAccessCheckerTest.php` |
| Confirmation CTAs | `web/modules/custom/myeventlane_surface/tests/src/Unit/MelCustomerContinuityPresenterWalletTest.php` |
| Issuance + wallet builders | `web/modules/custom/myeventlane_tickets/tests/src/Kernel/IssuancePipelineConvergenceTest.php` |
| QR payload | `web/modules/custom/myeventlane_tickets/tests/src/Unit/TicketQrPayloadSecretTest.php` |
| Digital Pass VM | `web/modules/custom/myeventlane_checkout_flow/tests/src/Kernel/MyTicketsOrderViewModelBuilderTest.php` |
