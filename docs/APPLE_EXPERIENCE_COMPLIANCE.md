# Apple experience compliance

This is the implementation contract for every MyEventLane Apple sign-in and
Apple Wallet surface. It is based on Apple's current Human Interface Guidelines:

- [Sign in with Apple](https://developer.apple.com/design/human-interface-guidelines/sign-in-with-apple/)
- [Wallet](https://developer.apple.com/design/human-interface-guidelines/wallet)

## Sign in with Apple

- Use Apple's generated button artwork from the reviewed local asset. Do not
  draw, recolour, crop, or replace the Apple mark.
- Use only Apple's supported title: **Sign in with Apple**.
- Keep the Apple control at least as prominent as the other sign-in methods and
  visible without a separate disclosure step.
- Request only the `name` and `email` scopes needed to connect an account.
- Respect Apple's private relay email. Never require a different personal email.
- Never ask an Apple-authenticated person to create a MyEventLane password.
- Social providers may sign in an existing account only. New accounts must use
  MyEventLane's consent-first registration and organiser-terms workflow.
- Apple's scoped response is a cross-site `form_post`; the secure Drupal session
  cookie must use `SameSite=None` so the OAuth state can be verified.

## Apple Wallet

- Render the official **Add to Apple Wallet** badge from
  `myeventlane_wallet/assets/web/`. Do not recreate or restyle it.
- Show Wallet actions only after an issued ticket exists and the signing service
  is operational.
- Build event passes with the native `eventTicket` style, structured fields,
  semantic event and venue data, a system QR barcode, relevant date, and
  expiration date where the event supplies one.
- Keep essential ticket information in pass fields, not inside images. Images
  are artwork only and must be PNG assets at the required scales.
- Do not embed customer identifiers, email addresses, text, or barcodes in pass
  artwork. Only the reviewed icon and logo asset allow-list may be packaged.
- Do not use pass updates or change messages for marketing.
- A future installed-pass state must use **View in Wallet**, not **Add to Apple
  Wallet**. MyEventLane does not currently receive a reliable installed-state
  signal, so it must not guess.
- Revoked tickets must remain ineligible for download. Any future Wallet web
  service must set `voided` and send an update rather than silently replacing a
  pass.

## Release check

Before release, verify:

1. Apple sign-in succeeds through the real Apple sheet on staging.
2. Existing customer and organiser accounts return to the intended MEL route.
3. A social identity cannot create an account without MEL consent.
4. The Apple button is Apple's generated black **Sign in with Apple** artwork.
5. A signed pass opens on a real iPhone and shows event name, date, time, venue,
   holder, QR code, and expiration behaviour correctly.
6. Booking pages and confirmation email use the official Wallet badge.
