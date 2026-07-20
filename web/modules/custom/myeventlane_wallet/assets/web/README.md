# Official wallet CTA assets

Do **not** invent Apple or Google wallet badges. Use only platform-provided artwork.

## Installed (this directory)

| File | Source |
|------|--------|
| `add-to-apple-wallet.svg` | [Apple Add to Apple Wallet guidelines](https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/) |
| `add-to-google-wallet.svg` | [Google Wallet brand guidelines](https://developers.google.com/wallet/generic/resources/brand-guidelines) (enAU primary button) |
| `add-to-google-wallet.png` | Same Google pack — PNG for email clients |

Canonical CTA builder: `myeventlane_wallet.action_builder` (`WalletActionBuilder`).

Canonical Twig fragment: `wallet-buttons.html.twig` (theme hook `myeventlane_wallet_buttons`).

## Refresh / reinstall

1. Apple: download official SVG from Apple’s badge guidelines (Wallet Marketing Agreement).
2. Google: download SVG/PNG packs from Google’s brand guidelines (`add-to-wallet-svg.zip` / `add-to-wallet-png.zip`).
3. Prefer Australian English (`enAU_*`) when available.
4. Clear Drupal caches after replacing files.

## Presentation rules

- Do not recreate, recolour, flip, animate, or shadow the badges.
- Keep badges secondary to MEL product identity.
- Maintain clear space; keep minimum height ~48px on interactive surfaces.
- On very dark backgrounds, Apple artwork may sit on a light clear-space pad (see `css/wallet-badges.css`).
