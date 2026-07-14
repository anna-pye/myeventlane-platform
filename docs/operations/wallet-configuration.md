# Wallet configuration (Apple + Google)

Operational runbook for production wallet passes. No sample credentials are included.

Related architecture:

- [digital-ticket-experience.md](../architecture/digital-ticket-experience.md)
- [wallet-operational-convergence.md](../wallet-operational-convergence.md)

---

## Ownership (canonical)

| Concern | Owner | Do not duplicate |
|---------|-------|------------------|
| Presentation gating | `WalletPresentationGate` | No parallel “wallet enabled” flags in theme |
| Apple download | `WalletAppleController` | No second Apple route |
| Google save redirect | `WalletGoogleController` | No second Google route |
| Apple archive | `PkPassBuilder` | No second ticket/PDF builder |
| Apple PKCS#7 signature | `WalletSigner` | No second signing pipeline |
| Google JWT | `GoogleWalletBuilder` | No second Google Wallet service |
| Ticket resolution | `WalletTicketResolver` | Order item is route key only |
| Access | `WalletDownloadAccessChecker` | Same ownership model as ticket PDF |
| Pass content / QR | `UniversalTicketViewModelBuilder` + `TicketQrPayload` | No wallet-specific QR generator |

---

## Configuration layers

### 1. Exported Drupal config (`myeventlane_wallet.settings`)

Safe for config/sync:

- `apple_enabled` / `google_enabled`
- `apple_team_id`
- `apple_pass_type_id`
- `apple_organisation_name`
- `google_issuer_id` — **numeric Issuer ID** from Google Pay & Wallet Console (not an OAuth client secret; values starting with `GOCSPX-` are rejected)
- `show_wallet_buttons` / `show_wallet_in_email`

Admin UI: `/admin/config/myeventlane/wallet`

### 2. Site settings / environment (secrets and paths)

Loaded in `web/sites/default/settings.mel_shared_session.php` into:

```php
$settings['myeventlane_wallet'] = [
  'apple_certificate_path' => '...',
  'apple_private_key_path' => '...',
  'apple_private_key_password' => '', // optional
  'apple_wwdr_certificate_path' => '...',
  'google_service_account_json_path' => '...',
  'google_origins' => ['https://www.example.com'],
];
```

Environment variables:

| Variable | Purpose |
|----------|---------|
| `MEL_WALLET_APPLE_CERTIFICATE_PATH` | Pass Type ID certificate (PEM) |
| `MEL_WALLET_APPLE_PRIVATE_KEY_PATH` | Matching private key (PEM) |
| `MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD` | Key passphrase if encrypted |
| `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH` | Apple WWDR intermediate (PEM) |
| `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON` | Absolute path to Google service account JSON |
| `MEL_WALLET_GOOGLE_ORIGINS` | Comma-separated HTTPS origins for the save JWT |

**Never commit** certificates, private keys, `.p12` / `.pem` files, or service account JSON. Repository `.gitignore` already blocks `*.pem` / `*.p12`.

---

## Apple certificate setup

1. Apple Developer → Identifiers → Pass Type IDs → create `pass.com…`.
2. Create a Pass Type ID certificate; export as `.p12` if needed and convert to PEM:

   ```bash
   openssl pkcs12 -in pass.p12 -clcerts -nokeys -out pass_cert.pem
   openssl pkcs12 -in pass.p12 -nocerts -nodes -out pass_key.pem
   ```

3. Download the current Apple Worldwide Developer Relations (WWDR) intermediate certificate and convert to PEM if required.
4. Place files outside the web root (e.g. host secret mount). Set the four Apple env vars above.
5. Set `apple_team_id`, `apple_pass_type_id`, `apple_organisation_name`, enable `apple_enabled`.
6. Clear caches. Confirm `WalletPresentationGate::isAppleWalletAvailable()` is TRUE (wallet buttons appear on digital pass when `show_wallet_buttons` is on).

### Rotation

1. Issue a new Pass Type ID certificate (same Pass Type ID when possible).
2. Deploy new PEM paths (or replace files in place).
3. Restart PHP-FPM / clear Drupal cache.
4. Download a test pass on a physical iOS device; verify it adds to Wallet.
5. Revoke the previous certificate only after validation.

### Recovery

- Buttons missing: check gate — team ID, pass type ID, path readability, OpenSSL, `apple_enabled`.
- Download 503: signing failed — inspect `myeventlane_wallet` watchdog (messages never include key material).
- Pass opens then fails signature: wrong WWDR, mismatched key/cert, or Pass Type ID / Team ID mismatch vs certificate.

---

## Google service account setup (Generic Pass)

MEL issues **Generic Pass** JWTs per
[Working with JWTs (Generic)](https://developers.google.com/wallet/generic/use-cases/jwt)
(`genericClasses` + `genericObjects`), not Event Ticket JWT payloads.

1. Google Pay & Wallet Console → create/enable issuer; copy **numeric Issuer ID**.
2. Google Cloud → create a service account with Wallet API access; download JSON once.
3. Store JSON outside the web root; set `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON`.
4. Set `google_issuer_id` to the numeric issuer (admin form).
5. Set `MEL_WALLET_GOOGLE_ORIGINS` to public HTTPS origins that host the save button (e.g. customer site).
6. Enable `google_enabled`; clear caches; confirm Google button only when gate is ready.
7. Until publishing access is granted, saved passes may show **[TEST ONLY]** in the Wallet app (Google’s demo behaviour).

### Rotation

1. Create a new service account key (or account); deploy new JSON path.
2. Disable/delete the old key after validation.
3. Issuer ID usually stays stable; do not swap in OAuth client secrets.

### Recovery

- Gate false with valid-looking config: issuer may be an OAuth secret (`GOCSPX-…`) — replace with numeric Issuer ID.
- Redirect 503: JWT signing failure — check JSON path, `client_email`, private key PEM newlines.
- Google rejects save: origins mismatch, issuer not approved, or class/object review status — fix in Wallet Console.

---

## Capability detection

`WalletPresentationGate` diagnoses capability (no hard-coded `FALSE`):

**Apple TRUE only when**

- `apple_enabled`
- team ID configured
- pass type ID configured
- `WalletSigner::isReady()` (certificate, private key, WWDR load + OpenSSL)

**Google TRUE only when**

- `google_enabled`
- issuer configured and not an obvious OAuth secret
- service account JSON loadable
- RS256 JWT probe signature succeeds (credential can sign, not merely parse)

Digital pass Twig already renders whichever of Apple / Google the universal ticket VM emits. If neither is ready, neither button shows.

If Apple PEMs or Google SA JSON are unavailable in an environment: **do not fake buttons**. Leave credentials unset; the gate keeps CTAs off.

---

## Branding compliance

| Platform | Guidance | MEL status |
|----------|----------|------------|
| Apple | Use official [Add to Apple Wallet](https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/) badge artwork; do not recreate | Text CTA today via `.mel-button`; replace with official badge asset when brand ops supplies files |
| Google | Use official [Add to Google Wallet](https://developers.google.com/wallet/generic/resources/brand-guidelines) button assets | Text CTA today; hydrate with Google-provided PNG/SVG only (no custom “G” badge) |

Do not invent MEL-branded wallet badges that mimic platform marks.

---

## Security checklist

- Ownership enforced by `WalletDownloadAccessChecker` (purchaser / guest email continuity; admin permissions).
- Void, refunded, and fulfilment-cancelled tickets denied for wallet downloads.
- PDF expiry window applies (`pdf_expiry_days`).
- Responses use `Cache-Control: private, no-store`.
- Guest anonymous uid `0` cannot match purchaser uid `0` without authenticated email continuity.
- Logs must not include certificate PEM, private keys, or JWT signing keys.
- QR replay protection remains HMAC signing in `TicketQrPayload` (wallet embeds the same payload).

---

## Troubleshooting quick table

| Symptom | Likely cause |
|---------|----------------|
| No wallet buttons | Gate not ready / `show_wallet_buttons` off |
| Only Apple / only Google | Expected — per-provider readiness |
| Apple 503 | Paths missing, bad PEM, OpenSSL PKCS#7 failure |
| Google 503 | Missing SA JSON, bad issuer, JWT sign failure |
| Google always off with secret-looking issuer | `GOCSPX-…` rejected — use numeric Issuer ID |
| Empty Apple file | Legacy path: no issued ticket for order item |

---

## Extension points

1. Pass field layout — extend `PkPassBuilder::buildPassJson()` only.
2. Google object/class shape — extend `GoogleWalletBuilder::buildSignedJwt()` only.
3. Presentation — extend `WalletPresentationGate` diagnosis only.
4. Do not add parallel wallet modules, routes, or QR/PDF generators.
