# Wallet assets checklist

Every material required for production Apple Wallet and Google Wallet on MyEventLane.

Owners and flows: [wallet-production-audit.md](../architecture/wallet-production-audit.md)  
Deployment: [apple-wallet-deployment.md](./apple-wallet-deployment.md), [google-wallet-deployment.md](./google-wallet-deployment.md)  
Go-live: [../launch/wallet-go-live.md](../launch/wallet-go-live.md)

Do **not** fabricate credentials. Do **not** bypass `WalletPresentationGate`.

---

## Configuration name audit (canonical only)

### Environment → `$settings['myeventlane_wallet']`

| Env | Settings key | Consumer |
|-----|--------------|----------|
| `MEL_WALLET_APPLE_CERTIFICATE_PATH` | `apple_certificate_path` | `WalletSigner` |
| `MEL_WALLET_APPLE_PRIVATE_KEY_PATH` | `apple_private_key_path` | `WalletSigner` |
| `MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD` | `apple_private_key_password` | `WalletSigner` |
| `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH` | `apple_wwdr_certificate_path` | `WalletSigner` |
| `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON` | `google_service_account_json_path` | `GoogleWalletBuilder` + gate probe |
| `MEL_WALLET_GOOGLE_ORIGINS` | `google_origins` | `GoogleWalletBuilder` |

Loaded by: `web/sites/default/settings.mel_shared_session.php`.

Optional (not env-mapped): `$settings['myeventlane_wallet_public_origin']`.

### Drupal config `myeventlane_wallet.settings`

| Key | Where set |
|-----|-----------|
| `apple_enabled` | Admin form / sync |
| `apple_team_id` | Admin form / sync |
| `apple_pass_type_id` | Admin form / sync |
| `apple_organisation_name` | Admin form / sync |
| `google_enabled` | Admin form / sync |
| `google_issuer_id` | Admin form / sync (numeric only) |
| `show_wallet_buttons` | Admin form / sync |
| `show_wallet_in_email` | Admin form / sync |

No legacy wallet credential names found in repository code paths.

---

## Apple assets

| Asset | Where to obtain | Where to install | Verification command | Expected runtime result |
|-------|-----------------|------------------|----------------------|-------------------------|
| Pass Type ID string | Apple Developer → Identifiers → Pass Type IDs | Drupal `apple_pass_type_id` | Match Portal identifier to config | Used as `passTypeIdentifier` |
| Team ID | Apple Developer membership | Drupal `apple_team_id` | Match Portal Team ID | Used as `teamIdentifier` |
| Pass Type Certificate (PEM) | Create Pass Type ID Certificate → export `.p12` → PEM | Host path + `MEL_WALLET_APPLE_CERTIFICATE_PATH` | `openssl x509 -in pass_cert.pem -noout -subject -dates` | `WalletSigner::isReady()` TRUE |
| Private key (PEM) | Same `.p12` export | Host path + `MEL_WALLET_APPLE_PRIVATE_KEY_PATH` | `openssl rsa -in pass_key.pem -check -noout` | Signing succeeds |
| Private key password | Chosen at export | Secret store + `MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD` if encrypted | Gate readiness with password set | Ready / wrong password → FALSE |
| WWDR intermediate (PEM) | Apple PKI / WWDR download | Host path + `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH` | `openssl x509 -in wwdr.pem -noout -subject -issuer` | PKCS#7 includes intermediate |
| Pass images | Repo `web/modules/custom/myeventlane_wallet/assets/pass/` | Ship with module (already in repo) | Files exist: `icon.png`, `paula.r@example.org`, `logo.png` | Bundled into `.pkpass` |
| Organisation display name | Product copy | Drupal `apple_organisation_name` | Admin form | Shown on pass / Google cardTitle fallback |

### Apple install verification (runtime)

```bash
# Paths readable by PHP (adjust paths)
test -r "$MEL_WALLET_APPLE_CERTIFICATE_PATH" && test -r "$MEL_WALLET_APPLE_PRIVATE_KEY_PATH" && test -r "$MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH" && echo OK_PATHS

ddev drush cr
ddev drush php:eval "echo \Drupal::service('myeventlane_wallet.wallet_signer')->isReady() ? 'SIGNER_READY' : 'SIGNER_NOT_READY';"
ddev drush php:eval "echo \Drupal::service('myeventlane_wallet.presentation_gate')->isAppleWalletAvailable() ? 'APPLE_TRUE' : 'APPLE_FALSE';"
```

Expected when PEMs + Team ID + Pass Type ID + `apple_enabled`: `SIGNER_READY` / `APPLE_TRUE`.

Authenticated download of `/wallet/apple/{order_item_id}` for an owned issued ticket: HTTP 200, `Content-Type: application/vnd.apple.pkpass`, installs on iPhone.

---

## Google assets

| Asset | Where to obtain | Where to install | Verification command | Expected runtime result |
|-------|-----------------|------------------|----------------------|-------------------------|
| Wallet Business account | [Pay & Wallet Console](https://pay.google.com/business/console) | Org ownership | Console login | Issuer manageable |
| Issuer ID (numeric) | Wallet Console → Issuer | Drupal `google_issuer_id` | Reject if starts with `GOCSPX-` | Gate accepts issuer |
| Cloud Project | Google Cloud Console | Linked to issuer | Project ID recorded in ops notes | API host |
| Google Wallet API | Cloud → Enable API | Enabled on project | API list shows Wallet API | API callable |
| Service Account | Cloud → Service Accounts | IAM + Wallet Console Users | SA email listed on issuer | Authorized to issue |
| Service Account JSON | SA Keys → JSON (once) | Host path + `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON` | `jq -r .type,.client_email sa.json` → `service_account` + email | Gate Google probe TRUE |
| Origins | Public customer HTTPS hosts | `MEL_WALLET_GOOGLE_ORIGINS` | Matches live site URL origin | Save JWT accepted |

### Google install verification (runtime)

```bash
test -r "$MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON" && echo OK_SA
jq -r '.type, .client_email' "$MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON"

ddev drush cr
ddev drush php:eval "echo \Drupal::service('myeventlane_wallet.google_wallet_builder')->isReady() ? 'GOOGLE_BUILDER_READY' : 'GOOGLE_BUILDER_NOT_READY';"
ddev drush php:eval "echo \Drupal::service('myeventlane_wallet.presentation_gate')->isGoogleWalletAvailable() ? 'GOOGLE_TRUE' : 'GOOGLE_FALSE';"
```

Expected when numeric issuer + readable SA + `google_enabled`: `GOOGLE_BUILDER_READY` / `GOOGLE_TRUE`.

Authenticated hit of `/wallet/google/{order_item_id}`: HTTP 302 to `https://pay.google.com/gp/v/save/…`.

---

## Presentation flags (not secrets)

| Flag | When TRUE matters |
|------|-------------------|
| `show_wallet_buttons` | Digital Pass + booking confirmation wallet CTAs (`shouldEmitWalletActions`) |
| `show_wallet_in_email` | Confirmation email wallet links (`shouldEmitWalletInEmail`) |

Both still require `isWalletPresentationAvailable()` (Apple and/or Google ready).

---

## Assets already in repository (non-secret)

| Item | Path | Notes |
|------|------|-------|
| Apple icon/logo PNGs | `web/modules/custom/myeventlane_wallet/assets/pass/` | Required for `.pkpass` |
| Web badge notes | `web/modules/custom/myeventlane_wallet/assets/web/README.md` | Official Add-to-Wallet artwork still optional upgrade |
| Install defaults | `config/install/myeventlane_wallet.settings.yml` | Providers default disabled in install |
| Sync settings | `config/sync/myeventlane_wallet.settings.yml` | Team ID / Pass Type ID present; `google_issuer_id` empty until ops sets it |

---

## Still required for production go-live

Mark each when complete:

- [ ] Apple Pass Type Certificate PEM installed on staging
- [ ] Apple private key PEM installed on staging
- [ ] WWDR PEM installed on staging
- [ ] Apple env vars on PHP-FPM + CLI (staging)
- [ ] `isAppleWalletAvailable()` TRUE on staging
- [ ] Physical iPhone Add-to-Wallet smoke
- [ ] Google Issuer ID numeric in config
- [ ] Google SA JSON installed on staging
- [ ] Google Wallet API enabled + SA authorized on issuer
- [ ] Origins set for staging HTTPS hosts
- [ ] `isGoogleWalletAvailable()` TRUE on staging
- [ ] Android Save-to-Wallet smoke
- [ ] Repeat all of the above for **production** secret mounts
- [ ] Publishing / demo status accepted for customer-facing Google passes
