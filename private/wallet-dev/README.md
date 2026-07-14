# Local wallet development credentials

Place Apple / Google signing material here for DDEV only.

**Do not commit any files in this directory except this README.**

## Required filenames

| File | Source |
|------|--------|
| `pass_cert.pem` | Apple Pass Type ID certificate (PEM) |
| `pass_key.pem` | Matching private key (PEM) |
| `wwdr.pem` | Apple WWDR intermediate (PEM) |
| `service-account.json` | Google Wallet API service account key |

Optional passphrase for the Apple private key is supplied via env
`MEL_WALLET_APPLE_PRIVATE_KEY_PASSWORD` (never store the passphrase in this directory).

## DDEV env wiring

Paths are exposed through ignored `.ddev/config.local.yaml` as:

- `MEL_WALLET_APPLE_CERTIFICATE_PATH=/var/www/html/private/wallet-dev/pass_cert.pem`
- `MEL_WALLET_APPLE_PRIVATE_KEY_PATH=/var/www/html/private/wallet-dev/pass_key.pem`
- `MEL_WALLET_APPLE_WWDR_CERTIFICATE_PATH=/var/www/html/private/wallet-dev/wwdr.pem`
- `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON=/var/www/html/private/wallet-dev/service-account.json`
- `MEL_WALLET_GOOGLE_ORIGINS=https://myeventlane.ddev.site` (optional; public HTTPS origins for save JWT)

Drupal team ID / Pass Type ID / numeric Google Issuer ID stay in
`myeventlane_wallet.settings` (admin UI). Never put PEM/JSON contents in config.

See `docs/operations/wallet-configuration.md`.
