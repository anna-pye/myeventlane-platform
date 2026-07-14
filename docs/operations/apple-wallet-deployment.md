# Apple Wallet deployment

Executable runbook for MyEventLane Apple Wallet (PassKit) production signing.

Official sources:

- [Wallet Passes](https://developer.apple.com/documentation/walletpasses)
- [Building a Pass](https://developer.apple.com/documentation/walletpasses/building_a_pass)
- [Creating the Source for a Pass](https://developer.apple.com/documentation/walletpasses/creating-the-source-for-a-pass)

MEL implementation owners (do not duplicate):

- `PkPassBuilder` — archive (`pass.json`, assets, `manifest.json`, zip → `.pkpass`)
- `WalletSigner` — PKCS#7 detached signature of `manifest.json`
- `WalletAppleController` — download MIME / cache / filename
- `WalletPresentationGate` — customer CTA emission

Related: [wallet-configuration.md](./wallet-configuration.md), [wallet-assets-checklist.md](./wallet-assets-checklist.md), [../architecture/wallet-production-audit.md](../architecture/wallet-production-audit.md), [../launch/wallet-go-live.md](../launch/wallet-go-live.md)

---

## Implementation audit vs Apple documentation

| Requirement | Apple expectation | MEL status | Difference / note |
|-------------|-------------------|------------|-------------------|
| Pass Type ID | Reverse-DNS Pass Type Identifier; `passTypeIdentifier` in `pass.json` | Config `apple_pass_type_id` (sync example: `pass.com.tickets.myeventlane`) | **Aligned** |
| Team ID | `teamIdentifier` matches Apple Developer Team ID of signing cert | Config `apple_team_id` | **Aligned** |
| Certificate | Pass Type ID Certificate for that Pass Type ID | Env `MEL_WALLET_APPLE_CERTIFICATE_PATH` (PEM) | **Aligned** — paths never in config/sync |
| Private key | Key matching the Pass Type ID certificate | Env `MEL_WALLET_APPLE_PRIVATE_KEY_PATH` (+ optional password) | **Aligned** |
| WWDR | Intermediate required in PKCS#7 chain | Env `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH` | **Aligned** — operator must supply current Apple WWDR PEM |
| PKCS#7 signing | Detached signature of `manifest.json` using pass cert + WWDR | `WalletSigner::sign()` — `openssl_pkcs7_sign` with `PKCS7_BINARY \| PKCS7_DETACHED`, DER extracted to file `signature` | **Aligned** |
| `pass.json` | Required keys + style-specific fields | `formatVersion`, identifiers, org, description, barcodes, `eventTicket` fields | **Aligned** with Event Ticket style |
| `manifest.json` | SHA-1 hash per source file (relative path → hex) | `sha1_file` for each bundle file | **Aligned** |
| `signature` | PKCS#7 detached DER as top-level file | Written after signing | **Aligned** |
| Bundle | Zip; extension `.pkpass` | `ZipArchive` → temp `.pkpass` | **Aligned** |
| MIME type | `application/vnd.apple.pkpass` | Set on `BinaryFileResponse` | **Aligned** |
| Cache headers | Personal artefact — not publicly cacheable | `Cache-Control: private, no-store, no-cache, must-revalidate` + `Pragma: no-cache` | **Aligned** (Apple does not mandate these headers; MEL security choice) |
| Pass filename | Suggested download name | `{sanitized_ticket_code}.pkpass` or `ticket.pkpass` | **Aligned** (UX only) |
| Required images | At least `icon.png` (and recommended `@2x` / logo) | Bundles `icon.png`, `paula.r@example.org`, `logo.png` from `assets/pass/` | **Aligned** for required icon; **no** `grace.l@example.com`, strip, or localization packs |
| Web service updates | Optional PassKit update web service | **Not implemented** | Documented gap — not required for first save |
| Sematic tags / poster event | Optional modern Event Ticket semantics | **Not implemented** | Optional enhancement; classic `eventTicket` fields only |

### Documented differences (no code change in this task)

1. **No PassKit update web service** — passes are generate-on-download; voids/refunds rely on access denial for new downloads, not push updates.
2. **No `@3x` icon / strip / localized `.lproj`** — sufficient for first production cut; add assets only via `PkPassBuilder` asset list.
3. **Legacy empty `.pkpass`** when no issued ticket — after access check; presentation gate should prevent customer CTA when issuance is incomplete.
4. **Controllers do not re-check `apple_enabled`** — presentation-only flag; direct URL still needs credentials or returns 503.

**Do not change implementation unless it diverges from Apple’s required build steps.** Current signing pipeline matches [Building a Pass](https://developer.apple.com/documentation/walletpasses/building_a_pass).

---

## Developer Portal

1. Sign in to [Apple Developer](https://developer.apple.com/account) with the MyEventLane organisation membership that owns the Team ID in config.
2. Confirm **Team ID** matches `apple_team_id` in Drupal (`/admin/config/myeventlane/wallet`).
3. Under **Certificates, Identifiers & Profiles → Identifiers**, ensure a **Pass Type ID** exists matching `apple_pass_type_id` (e.g. `pass.com.tickets.myeventlane`).
4. Membership: **Apple Developer Program** active; Pass Type ID certificates can be issued only under that Team.

---

## Certificate creation

1. On a trusted Mac, open Keychain Access → Certificate Assistant → **Request a Certificate From a Certificate Authority** → save CSR (email + Common Name; leave CA email blank; “Saved to disk”).
2. Developer Portal → **Certificates** → **+** → **Pass Type ID Certificate**.
3. Select the Pass Type ID that matches Drupal `apple_pass_type_id`.
4. Upload the CSR; download the issued certificate (`.cer`).

---

## Certificate export

1. Double-click the `.cer` to import into Keychain (login keychain).
2. Find the pass certificate (issued “Pass Type ID: …”); expand to confirm private key is present.
3. Select certificate + private key → **Export 2 items…** → Personal Information Exchange (`.p12`).
4. Set a strong export password; store in team secret manager (not git).

---

## Private key export / PEM conversion

On a secure machine (not a shared laptop where possible):

```bash
# Pass certificate only (PEM)
openssl pkcs12 -in pass.p12 -clcerts -nokeys -out pass_cert.pem

# Private key (PEM). Prefer keeping encryption for storage;
# if MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD is unset, use -nodes for unencrypted key.
openssl pkcs12 -in pass.p12 -nocerts -out pass_key.pem
# or unencrypted:
# openssl pkcs12 -in pass.p12 -nocerts -nodes -out pass_key.pem
```

Verify:

```bash
openssl x509 -in pass_cert.pem -noout -subject -dates
openssl rsa -in pass_key.pem -check -noout
# If encrypted:
# openssl rsa -in pass_key.pem -check -noout -passin env:MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD
```

---

## WWDR

1. Download the current **Apple Worldwide Developer Relations** intermediate certificate from Apple PKI / Apple CA (use the G-series intermediate Apple documents as current for PassKit signing).
2. Convert to PEM if needed:

```bash
openssl x509 -inform DER -in AppleWWDRCAG4.cer -out wwdr.pem
# (format may already be PEM — adjust -inform)
openssl x509 -in wwdr.pem -noout -subject -issuer -dates
```

3. Ensure the WWDR matches what OpenSSL needs as `-certfile` for `openssl_pkcs7_sign` (MEL passes the WWDR path as the extra certs argument).

---

## Local install (DDEV / developer machine)

1. Place files **outside** the web root, e.g. `~/mel-secrets/wallet/` (never under `web/`).
2. Export in shell or DDEV config (do not commit):

```bash
export MEL_WALLET_APPLE_CERTIFICATE_PATH="/absolute/path/pass_cert.pem"
export MEL_WALLET_APPLE_PRIVATE_KEY_PATH="/absolute/path/pass_key.pem"
export MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH="/absolute/path/wwdr.pem"
# export MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD='…'  # if encrypted
```

3. Drupal admin → Wallet settings:
   - `apple_enabled` = TRUE
   - `apple_team_id` / `apple_pass_type_id` / `apple_organisation_name` set
   - `show_wallet_buttons` = TRUE (for UI test)
4. `ddev drush cr`
5. Confirm gate (Drush PHP or unit-style debug — **do not** invent credentials in tests for production paths):

```bash
ddev drush php:eval "echo \Drupal::service('myeventlane_wallet.presentation_gate')->isAppleWalletAvailable() ? 'TRUE' : 'FALSE';"
```

Expected with valid PEMs + config: `TRUE`.

---

## Staging install

1. Mount secrets via host secret store / CI secrets into paths readable by PHP-FPM only (mode `0400` / `0600`, owner web user).
2. Set the four Apple env vars on PHP-FPM **and** CLI (Drush).
3. Import config so Team ID / Pass Type ID match the certificate’s Team and Pass Type ID.
4. Clear caches; run staging smoke from [wallet-go-live.md](../launch/wallet-go-live.md).
5. Download `.pkpass` on a physical iPhone (Simulator optional first).

---

## Production install

1. Same as staging with production secret mount.
2. Confirm `apple_enabled` TRUE and `show_wallet_*` intentional.
3. Deploy PEMs before or with the release that expects buttons; never flip UI flags while PEMs are missing (gate already suppresses CTAs).
4. Post-deploy: gate TRUE, Digital Pass Apple CTA visible for authenticated issued tickets, download installs in Wallet app.

---

## Rotation

1. Issue a **new** Pass Type ID Certificate for the **same** Pass Type ID (preferred) via new CSR.
2. Export / convert PEMs; deploy new paths (or replace files atomically).
3. Restart PHP-FPM / clear Drupal cache (`drush cr`).
4. Smoke: generate pass → add to iPhone Wallet → confirm event label + QR.
5. Revoke previous certificate in Developer Portal only after success.
6. Update secret manager; remove old PEMs from disk.

If Pass Type ID string must change: update Drupal `apple_pass_type_id` in the same release as the new certificate — mismatched `passTypeIdentifier` vs cert fails Apple validation.

---

## Recovery

| Symptom | Action |
|---------|--------|
| Gate Apple FALSE | Check env paths, file readability, OpenSSL load, Team ID, Pass Type ID, `apple_enabled` |
| Download HTTP 503 | Watchdog channel `myeventlane_wallet` (no PEM contents in logs); fix PEMs / password |
| Pass downloads but Wallet rejects | Team ID / Pass Type ID mismatch, expired cert, wrong WWDR, corrupt signature |
| Buttons still hidden with PEMs OK | `show_wallet_buttons` off, or only Google was expected off — check both providers |
| Emergency disable | Set `apple_enabled` FALSE and/or `show_wallet_buttons` FALSE via admin; clear cache — do not delete routes |

Never commit PEMs, `.p12`, or passwords. Never weaken `WalletDownloadAccessChecker` or the presentation gate to “force” CTAs.
