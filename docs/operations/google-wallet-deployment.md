# Google Wallet deployment

Executable runbook for MyEventLane Google Wallet **Generic Pass** JWT production.

Official sources:

- [Generic Pass overview](https://developers.google.com/wallet/generic)
- [Working with JWTs (Generic)](https://developers.google.com/wallet/generic/use-cases/jwt)
- [Generic Class](https://developers.google.com/wallet/generic/rest/v1/genericclass) / [Generic Object](https://developers.google.com/wallet/generic/rest/v1/genericobject)

MEL implementation owners (do not duplicate):

- `GoogleWalletBuilder` — Generic Pass JWT + save URL
- `WalletGoogleController` — ownership → HTTPS 302 to Google
- `WalletPresentationGate` — customer CTA emission

**MEL does not use Google Event Tickets** (`eventTicketClasses` / `eventTicketObjects`). Passes are Generic Pass only.

Related: [wallet-configuration.md](./wallet-configuration.md), [wallet-assets-checklist.md](./wallet-assets-checklist.md), [../architecture/wallet-production-audit.md](../architecture/wallet-production-audit.md), [../launch/wallet-go-live.md](../launch/wallet-go-live.md)

---

## Implementation audit vs Google documentation

| Requirement | Google expectation | MEL status | Difference / note |
|-------------|-------------------|------------|-------------------|
| Pass type | Generic Pass for flexible use cases | `payload.genericClasses` + `payload.genericObjects` | **Aligned** — **not** Event Ticket API |
| Save URL | `https://pay.google.com/gp/v/save/{signed_jwt}` | `GoogleWalletBuilder::SAVE_URL_PREFIX` | **Aligned** |
| Issuer ID | Numeric issuer from Wallet Business Console | Config `google_issuer_id`; rejects empty, spaces, `GOCSPX-` prefix | **Aligned** |
| Service account | SA key authorized for Wallet API / JWT signing | Env `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON` | **Aligned** |
| JWT signing | RS256 with service account private key | `openssl_sign` + base64url | **Aligned** |
| Claims `iss` | Service account `client_email` | From JSON | **Aligned** |
| Claims `aud` | `google` | Hard-coded | **Aligned** |
| Claims `typ` | `savetowallet` | Hard-coded | **Aligned** |
| Claims `iat` | Unix time | `time()` | **Aligned** |
| Claims `origins` | Origins hosting the save button | Env `MEL_WALLET_GOOGLE_ORIGINS`, else public origin / `$base_url` | **Aligned** with fallbacks |
| Class ID | `{issuerId}.{suffix}` | `{issuerId}.mel.ticket.event.{eventId}` or `.mel.ticket.generic` | **Aligned** pattern |
| Object ID | `{issuerId}.{suffix}` | `{issuerId}.{ticketUuid\|code}` sanitized | **Aligned** pattern |
| Object fields | `classId`, `state`, `cardTitle`, `header`, barcode, etc. | Present; QR from Universal VM | **Aligned** — subset of optional modules |
| REST pre-create | Optional REST create before JWT | **Not required** — Google documents JWT can create class+object on save | **Aligned** with JWT-only issuance |
| Event Tickets product | Separate pass vertical | **Not used** | Intentional product choice |

### Documented differences (no code change in this task)

1. **No Google Event Ticket classes/objects** — ticketing UX uses Generic Pass fields (`header` / barcode / text modules).
2. **Minimal class definition** — JWT class payload is `{ id }` only; optional class display properties can be extended later in `GoogleWalletBuilder` only.
3. **No REST object lifecycle updates** (void/update) — access checker blocks new saves for voided tickets; Wallet objects already saved are not updated by MEL yet.
4. **`cardTitle` reuses `apple_organisation_name`** — branding convenience; not a second issuer config key.
5. **Legacy `…/save/placeholder`** when no issued ticket — after access check; not a production customer path.
6. **Demo / publishing** — until Google grants production publishing, Wallet may show **[TEST ONLY]** — ops must complete Wallet Console publishing.

---

## Wallet Business account

1. Open [Google Pay & Wallet Console](https://pay.google.com/business/console) (Wallet Business Console).
2. Create or join the MyEventLane issuer business profile.
3. Complete business verification / publishing requirements Google requires for public passes.
4. Note demo vs production publishing status — demo issuers save with test banners.

---

## Issuer

1. In Wallet Console, open the Issuer for MyEventLane.
2. Copy the **numeric Issuer ID** (digits only).  
   **Never** store OAuth client secrets (`GOCSPX-…`) in `google_issuer_id` — gate and builder reject them.
3. Set Drupal config `google_issuer_id` via `/admin/config/myeventlane/wallet` and export to `config/sync` when ready.

---

## Cloud Project

1. Create or select a Google Cloud project dedicated to Wallet API (or shared MEL platform project with clear IAM).
2. Link / authorize the Cloud project with the Wallet issuer per Google’s “Additional features → Users / Service accounts” Console steps for the issuer.
3. Enable billing if required by Google for the project (follow Console prompts).

---

## Wallet API

1. Google Cloud Console → APIs & Services → enable **Google Wallet API**.
2. Confirm the service account used for JWT signing is allowed to issue for the Issuer ID (Wallet Console user/service-account grants).

---

## Service Account

1. Cloud Console → IAM & Admin → Service Accounts → Create (e.S. `mel-wallet-issuer@PROJECT.iam.gserviceaccount.com`).
2. Grant the minimum roles Google requires for Wallet JWT issuance (typically Wallet API user / issuer-authorized SA — follow Console Issuer “Users” invitation for the SA email).
3. Do **not** paste private keys into Drupal config forms.

---

## IAM

1. Wallet Console → Issuer → add service account email as a user with permission to create passes / manage objects (per Google UI labels).
2. Cloud IAM: prefer least privilege; avoid owner roles for the signing SA.
3. Restrict key creators; use separate SAs for staging vs production when possible.

---

## JSON key

1. Service account → Keys → Add key → JSON → download **once**.
2. Store in secret manager; place on hosts outside web root.
3. Env:

```bash
export MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON="/absolute/path/mel-wallet-sa.json"
export MEL_WALLET_GOOGLE_ORIGINS="https://www.myeventlane.com.au,https://myeventlane.com.au"
```

Staging origins must match staging public hosts that render save buttons / redirect entry points.

JSON must be `type: service_account` with `client_email` and `private_key`.

---

## Local

1. Point `MEL_WALLET_GOOGLE_SERVICE_ACCOUNT_JSON` at a readable SA JSON (staging SA acceptable only if issuer allows).
2. Set `google_issuer_id` (numeric), `google_enabled` TRUE, origins for local HTTPS if testing real Save (HTTP origins may be rejected by Google).
3. `ddev drush cr`
4. Probe:

```bash
ddev drush php:eval "echo \Drupal::service('myeventlane_wallet.presentation_gate')->isGoogleWalletAvailable() ? 'TRUE' : 'FALSE';"
```

5. Authenticated issued ticket → Digital Pass Google CTA → expect 302 to `pay.google.com/gp/v/save/…`.

---

## Staging

1. Mount SA JSON; set origins to staging public HTTPS origin(s).
2. Use staging Issuer ID if separate, or shared demo issuer with care.
3. Smoke Save on Android Chrome / Google Wallet app.
4. Confirm `[TEST ONLY]` behaviour if issuer not published.

---

## Production

1. Production SA JSON + production Issuer ID + production HTTPS origins.
2. Complete Google publishing / ToS for non-demo customers.
3. `google_enabled` TRUE; `show_wallet_buttons` / `show_wallet_in_email` intentional.
4. Export `google_issuer_id` via approved config workflow (`drush cex` / import review) — numeric only.

---

## Rotation

1. Create new SA key (or new SA + Console grant).
2. Deploy new JSON path via env; reload PHP-FPM; `drush cr`.
3. Confirm gate Google TRUE and a Save succeeds.
4. Disable/delete old key in Cloud Console.
5. Issuer ID usually remains stable.

---

## Recovery

| Symptom | Action |
|---------|--------|
| Gate Google FALSE | Empty/`GOCSPX-` issuer, missing JSON, unreadable path, bad PEM key, `google_enabled` off |
| Redirect 503 | JWT sign failure — fix SA JSON; check watchdog `myeventlane_wallet` |
| Google rejects save | Origins mismatch, SA not authorized on issuer, class/object policy, unpublished issuer |
| Buttons missing with Google OK but Apple OFF | Expected — UI shows only presentable providers |
| Emergency disable | `google_enabled` FALSE and/or `show_wallet_*` FALSE; clear cache |

Never commit SA JSON. Never bypass `WalletPresentationGate` to force CTAs without credentials.
