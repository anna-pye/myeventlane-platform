# Apple Wallet Event Ticket design audit

**Scope:** `myeventlane_wallet` Apple Event Ticket presentation only.  
**Canonical implementation owner:** `Drupal\myeventlane_wallet\Service\PkPassBuilder`.  
**Out of scope:** signing, certificate loading, PKCS#7, route access, QR payload generation, and Google Wallet.

## Sources reviewed

- [Apple Wallet: Pass](https://developer.apple.com/documentation/walletpasses/pass)
- [Apple Wallet Developer Guide: Pass Design and Creation](https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Creating.html)
- [Supporting semantic tags in Wallet passes](https://developer.apple.com/documentation/passkit/wallet/supporting_semantic_tags_in_wallet_passes)
- MEL [design tokens](../brand/design-tokens.md)

## Current MEL behaviour

`PkPassBuilder` creates an `eventTicket` pass with:

- an event name as the sole primary field;
- ticket type as the sole secondary field;
- holder name and ticket code as auxiliary fields;
- booking reference, a single combined location string, and MEL issuer on the back;
- a QR barcode sourced from the canonical ticket view model;
- `relevantDate` from `field_event_start`;
- one Apple location resolved by the existing `EventGeoResolver`;
- `icon.png`, `logo.png`, and a `strip.png`; when present, the event hero is centre-cropped into the strip.

The current assets are not a complete Event Ticket asset set:

- `strip.png` is the correct 750 × 196 px @2x size, but its fallback has no canonical logo.
- `logo.png` is 160 × 50 px and `icon.png` is 29 × 29 px; neither has the standard @2x companion.
- `thumbnail.png` and `background.png` are absent.

## Apple guidance and MEL decisions

| Area | Apple guidance (summary) | MEL decision |
|---|---|---|
| Pass style | Use the Event Ticket style for event admission. Fields render in Apple-controlled regions and can reflow. | Retain `eventTicket`; do not imitate a webpage layout. |
| Field hierarchy | Put the information needed at entry first; use short labels and values because Wallet can truncate/reflow fields. | Promote date/time and venue to the front. Keep booking reference and ticket code on the back. |
| Strip / background / thumbnail | Event Tickets support strip, background, and thumbnail imagery, but a strip must not be combined with a background or thumbnail. | Use only `strip.png`. Do not add `background.png` or `thumbnail.png`. |
| Image quality | Provide appropriate @2x assets; preserve aspect ratio and account for crop-safe areas. | Keep `strip.png` at 750 × 196 px. Generate it with crop-to-fill only; never stretch. |
| Hero suitability | Wallet imagery should aid recognition, not carry UI or dense text. | Reuse `BoostedEventQualityGate::isMarketplaceReady()` as the existing editorial suitability signal, then require a decodable local image with a viable crop. The generated MEL strip is the safe fallback. |
| `logo.png` / `icon.png` | The logo identifies the issuer; the icon supports system display contexts. | Retain MEL logo/icon but add @2x assets. Branding remains smaller than event information. |
| Barcode | Wallet controls placement. Barcode content must be preserved for scanners. | Preserve the existing QR message, encoding, and Event Ticket barcode structure exactly. |
| `relevantDate` | A top-level relevant date lets Wallet surface a pass at the relevant time. | Retain event start as `relevantDate`. |
| Locations | Top-level locations can surface the pass near a venue. | Retain one validated location from `EventGeoResolver`; do not recreate geo resolution. |
| `expirationDate` | Intended for passes that become invalid at a specific time. | Do not add it. Ticket validity and entry policy are not reliably represented by a single event-end timestamp, and premature expiration could hide a valid pass. |
| Organisation metadata | `organizationName`, `logoText`, and `description` provide concise identity and purpose. | Use `MyEventLane` consistently for organisation and logo text; keep the event name as description. |
| Colours | Colour must preserve foreground/label readability on device-controlled Wallet surfaces. | Use Warm Cream background, MEL Navy foreground, and Accent Purple labels. Do not use Coral as a large text surface. |
| Accessibility | Respect system typography and varying layouts; no fixed text positioning or image-embedded essential text. | Keep essential information as fields, avoid text in strip artwork, use short labels/values, and permit omitted optional fields. |
| Semantic tags | Event Ticket semantic tags can enrich system features. | Do not add them in this slice: they need a separately verified mapping for event type and venue semantics. Existing relevance is sufficient and safer. |

## Adopt in this implementation

1. Front-of-pass hierarchy:
   - primary: event name;
   - secondary: event date and time;
   - auxiliary: venue name and ticket type;
   - back: venue address, holder name, booking reference, ticket code, organiser, and a venue-directions link where coordinates exist.
2. Separate venue name from address using existing event/venue fields; fall back safely where individual fields are absent.
3. Preserve the event hero's focal-point data where available; otherwise centre-crop only images that meet the strip crop requirements.
4. Make the MEL fallback strip branded but quiet: Warm Cream/Lavender/CORAL/Purple accents with a small canonical MEL mark and no informational text.
5. Bundle @2x logo and icon assets alongside the base assets.
6. Preserve `relevantDate`, validated `locations`, and the unmodified QR payload.

## Deliberately not adopted

- **`thumbnail.png` and `background.png`:** Apple documents these as mutually exclusive with a strip for Event Tickets. The strip is the clearer, lower-risk recognition image.
- **A website screenshot or web hero treatment:** Wallet is native and its pass layout is system-owned; web UI/text would crop badly and compete with fields.
- **`expirationDate`:** MEL has no repository-confirmed single expiration policy that applies to all ticket types.
- **Oversized organiser branding, refund policy, or terms on the front:** they hinder entry scanning and ticket recognition.
- **Directions / support / refund links without verified canonical URLs:** no existing safe, public per-event links are confirmed in the pass builder. Venue directions can be generated from validated geo only.
- **Semantic tags / iOS upcoming-event enhancements:** valuable, but they are a separate compatibility and data-model task rather than a visual-polish change.

## Verification target

Validate a generated `.pkpass` contains a readable front hierarchy, an undistorted strip, no background/thumbnail conflict, stable QR fields, `relevantDate`, and valid coordinates when available. Final import, lock-screen, and scanner checks require a physical Apple device.
