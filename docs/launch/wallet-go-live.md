# Wallet go-live runbook

Executable launch guide for Apple Wallet and Google Wallet on MyEventLane.

Prerequisites:

- [wallet-production-audit.md](../architecture/wallet-production-audit.md) — single ownership confirmed
- [apple-wallet-deployment.md](../operations/apple-wallet-deployment.md)
- [google-wallet-deployment.md](../operations/google-wallet-deployment.md)
- [wallet-assets-checklist.md](../operations/wallet-assets-checklist.md)
- [wallet-configuration.md](../operations/wallet-configuration.md)

**Rules:** do not fabricate credentials; do not bypass `WalletPresentationGate`; do not enable customer wallet CTAs without working credentials; do not create a second wallet implementation.

---

## Operational verification (when credentials are installed)

These methods on `WalletPresentationGate` MUST become TRUE once PEMs / SA JSON / config are correct and feature flags are on:

| Method | Requires |
|--------|----------|
| `isAppleWalletAvailable()` | `apple_enabled` + Team ID + Pass Type ID + `WalletSigner::isReady()` |
| `isGoogleWalletAvailable()` | `google_enabled` + numeric issuer + SA JSON RS256 probe |
| `isWalletPresentationAvailable()` | Apple **or** Google available |
| `shouldEmitWalletActions()` | `show_wallet_buttons` + presentation available |
| `shouldEmitWalletInEmail()` | `show_wallet_in_email` + presentation available |

Probe (Drush on the target environment):

```bash
ddev drush php:eval '$g=\Drupal::service("myeventlane_wallet.presentation_gate");
echo "apple=" . ($g->isAppleWalletAvailable()?"T":"F") . PHP_EOL;
echo "google=" . ($g->isGoogleWalletAvailable()?"T":"F") . PHP_EOL;
echo "any=" . ($g->isWalletPresentationAvailable()?"T":"F") . PHP_EOL;
echo "actions=" . ($g->shouldEmitWalletActions()?"T":"F") . PHP_EOL;
echo "email=" . ($g->shouldEmitWalletInEmail()?"T":"F") . PHP_EOL;'
```

### Surface checklist

Use an authenticated purchaser with an **issued** ticket. Guests must not receive wallet URLs.

| Surface | Expectation when gate ready |
|---------|----------------------------|
| Digital Pass (`/my-tickets/order/{order}`) | Apple and/or Google buttons per availability |
| Booking Confirmation page | Same wallet CTAs via continuity presenter |
| Booking Confirmation email | Wallet links when `shouldEmitWalletInEmail()` |
| Dashboard (customer hub) | **View Digital Pass** / PDF — not direct wallet mint links |
| My Bookings | Open Digital Pass — wallet on pass page |
| Reminder email (7d / 24h) | View Digital Pass CTA only (no wallet URLs) |
| Refund email (buyer) | View Digital Pass / cancelled state — no wallet save CTAs |
| Apple download | `/wallet/apple/{order_item_id}` → `.pkpass` |
| Google Save | `/wallet/google/{order_item_id}` → 302 → Google save |
| PDF | `/ticket/{ticket_code}/pdf` still works |
| QR | Digital Pass + pass barcodes use same UTVM / `TicketQrPayload` |

---

## Development

1. Pull branch; do not commit secrets.
2. Install Apple PEM trio + WWDR **or** Google SA JSON as available locally.
3. Set env vars per [wallet-assets-checklist.md](../operations/wallet-assets-checklist.md).
4. Align Drupal wallet settings (Team ID, Pass Type ID, Issuer ID, enable flags).
5. Validate:

```bash
composer validate --no-check-publish
ddev drush cr
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletPresentationGateTest.php \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletSignerTest.php \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletDownloadAccessCheckerTest.php \
  --do-not-cache-result
```

6. Confirm gate probes; walk Digital Pass + confirmation page with real issued ticket.
7. Leave buttons suppressed if credentials incomplete — expected and correct.

---

## Staging

1. Mount secrets outside web root; set PHP-FPM + CLI env.
2. Config: numeric `google_issuer_id`; Apple Team / Pass Type IDs matching certificates.
3. `drush cr`; run gate probe (all TRUE for intended providers).
4. Smoke matrix below (Staging column).
5. Record Issuer demo/publishing status for Google.
6. Only then enable any ops checklist that assumes customer-visible wallet buttons.

---

## Production

1. Freeze credential install ahead of announced go-live.
2. Deploy PEMs / SA JSON; set env; import/export reviewed config (issuer ID).
3. `drush cr`; gate probe on production (read-only eval).
4. Smoke matrix (Production column) with a non-production test order if possible, else controlled real purchase.
5. Monitor `myeventlane_wallet` watchdog for 503s after go-live (no secrets in logs).
6. Customer help: direct to Digital Pass; platform wallet apps for saves.

---

## Rollback

| Action | How | Effect |
|--------|-----|--------|
| Soft rollback (hide CTAs) | Set `show_wallet_buttons` / `show_wallet_in_email` FALSE | CTAs gone; routes remain |
| Provider rollback | Set `apple_enabled` and/or `google_enabled` FALSE | Gate FALSE for provider |
| Hard credential rollback | Unset MEL_WALLET_* env / remove mounts | Signer / JWT not ready → gate FALSE; downloads 503 |
| Code rollback | Redeploy previous app release | Only if a regression in wallet modules — credentials independent |

Do **not** delete wallet routes as the disable mechanism — use gate flags.

---

## Certificate / key rotation

- Apple: follow [apple-wallet-deployment.md](../operations/apple-wallet-deployment.md) § Rotation.
- Google: follow [google-wallet-deployment.md](../operations/google-wallet-deployment.md) § Rotation.
- Rotate staging first; then production; keep previous material until smoke passes.

---

## Emergency disable

1. Admin → `/admin/config/myeventlane/wallet` → disable providers and/or show flags.
2. `drush cr`.
3. Confirm Digital Pass / confirmation / email no longer show wallet CTAs.
4. Optionally revoke compromised Apple cert or Google SA key in vendor consoles.
5. File incident note; restore via checklist — never “temporary” CTA bypasses.

---

## Smoke testing matrix

| Check | Dev | Staging | Production |
|-------|-----|---------|------------|
| Gate probes | ✓ | ✓ | ✓ |
| Digital Pass CTAs | ✓ | ✓ | ✓ |
| Booking confirmation page | ✓ | ✓ | ✓ |
| Confirmation email wallet links | ✓ | ✓ | ✓ |
| Apple `.pkpass` download MIME | ✓ | ✓ | ✓ |
| Google 302 save URL | ✓ | ✓ | ✓ |
| PDF download | ✓ | ✓ | ✓ |
| QR visible on Digital Pass | ✓ | ✓ | ✓ |
| Unauthorized user denied wallet route | ✓ | ✓ | ✓ |
| Void/refunded ticket denied | ✓ | ✓ | ✓ |

---

## Device testing

### iPhone (Safari / Wallet)

1. Open Digital Pass; tap Add to Apple Wallet.
2. Pass preview → Add.
3. Confirm event title, ticket code/name, QR present.
4. Lock screen / Wallet app retention after kill/reopen.
5. Optional: AirDrop / Mail attachment of `.pkpass` from desktop for MIME check.

### Android (Chrome / Google Wallet)

1. Open Digital Pass; tap Add to Google Wallet.
2. Complete Google Save sheet.
3. Confirm card title / header / QR.
4. Note **[TEST ONLY]** if issuer unpublished — track publishing separately.

### Desktop

1. Chrome / Safari: confirmation + Digital Pass render; Apple download yields `.pkpass` file.
2. Google button may redirect to web save flow — verify HTTPS and login.
3. Do not expect Apple Wallet install **into** iPhone Wallet from desktop without device handoff.

### Mail

1. Authenticated confirmation email contains Apple / Google links only when email gate TRUE.
2. Links require login / ownership continuity to succeed.
3. Guests: no wallet URLs (attachments only).

### QR validation / check-in

1. Scan QR from Digital Pass page and from saved Apple / Google pass.
2. Expect same payload source (`TicketQrPayload` via UTVM).
3. Run existing check-in scanner against both; fail loudly on signature mismatch — do not invent a wallet-specific QR path.

---

## Post go-live monitoring

- Spike of HTTP 503 on `/wallet/apple/*` or `/wallet/google/*` → credential/path/OpenSSL/JWT issue.
- Sudden CTA disappearance → env mount lost on new hosts (gate correctly off).
- Google Save failures with gate TRUE → origins / issuer publishing / SA authorization.

---

## Validation suite (safe, no credentials)

```bash
composer validate --no-check-publish
ddev drush cr
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletPresentationGateTest.php \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletSignerTest.php \
  web/modules/custom/myeventlane_wallet/tests/src/Unit/WalletDownloadAccessCheckerTest.php \
  web/modules/custom/myeventlane_surface/tests/src/Unit/MelCustomerContinuityPresenterWalletTest.php \
  --do-not-cache-result
ddev exec bash scripts/mel-phpunit \
  web/modules/custom/myeventlane_tickets/tests/src/Unit/TicketQrPayloadSecretTest.php \
  web/modules/custom/myeventlane_tickets/tests/src/Kernel/UniversalTicketViewModelBuilderTest.php \
  web/modules/custom/myeventlane_checkout_flow/tests/src/Kernel/MyTicketsOrderViewModelBuilderTest.php \
  web/modules/custom/myeventlane_tickets/tests/src/Kernel/IssuancePipelineConvergenceTest.php \
  --do-not-cache-result
```

Unit/kernel tests use temporary fake cert material only inside test fixtures — never production secrets.
