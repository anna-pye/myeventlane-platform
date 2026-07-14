# Official wallet CTA assets (required for branded buttons)

Do **not** invent Apple or Google wallet badges.

## Install

1. Download official **Add to Apple Wallet** SVG from [Apple’s badge guidelines](https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/) (Apple Developer Program membership required).
2. Download official **Add to Google Wallet** SVG from [Google Wallet brand guidelines](https://developers.google.com/wallet/generic/resources/brand-guidelines) (PNG/SVG asset packs).
3. Place files here (names expected by theme SCSS background hooks when enabled):

   - `add-to-apple-wallet.svg`
   - `add-to-google-wallet.svg`

4. Clear Drupal/theme caches after deploy.

Until these files exist, Digital Pass shows accessible text CTAs that use the official product names only.
