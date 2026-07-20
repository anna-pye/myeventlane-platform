# Apple Wallet ticket presentation

## Responsibility boundaries

`WalletEventPresentation` owns the attendee-facing event-ticket projection:

- event-ticket fields;
- venue name and address formatting;
- event date presentation;
- Wallet location and semantic metadata;
- event-hero selection and strip-image generation.

`PkPassBuilder` owns package mechanics only:

- pass identifier, serial number, organisation branding, and barcode insertion;
- `pass.json` assembly;
- required static asset packaging;
- manifest creation, signing input creation, and archive generation.

Signing remains exclusively in `WalletSigner`. QR payload creation remains in
`UniversalTicketViewModelBuilder` / `TicketQrPayload`.

## Image selection

The pass package always includes the module assets `icon.png`, `icon@2x.png`,
`icon@3x.png`, `logo.png`, and `logo@2x.png`.

For `strip.png`, WalletEventPresentation first resolves the event's
`field_event_image` (direct file, or image Media's `field_media_image`). It
creates a 750×196 PNG crop, preferring an `event_hero` crop, then a
`focal_point` crop, then a centred crop.

If the event image is absent, unreadable, unsupported, too small, cannot be
decoded, GD is unavailable, or the generated PNG cannot be written, the pass
omits `strip.png`. Wallet must never replace an attendee's event artwork with
MEL branding, a mascot, decorative artwork, or text.

Homepage merchandising eligibility is never part of this decision.
`BoostedEventQualityGate` checks editorial readiness for discovery surfaces;
it must not suppress the branded image of an already purchased ticket.

## Event title

The attendee-facing event title is the event node label exposed by
`UniversalTicketViewModelBuilder` as `model.event.label`. There is no separate
public-title field in the event schema; checkout and ticket-mailer attendee
surfaces use the same node label. Wallet therefore uses this canonical ticket
model value and does not construct a title from internal operational metadata.

## Venue and address resolution

Venue name precedence:

1. event `field_venue_name`;
2. referenced `field_venue` entity label.

Address precedence:

1. event `field_venue_address`;
2. event `field_location`;
3. referenced Venue entity `primary_address`;
4. the legacy `event.location` string in the issued-ticket model.

Structured event addresses are rendered as comma-separated address line 1,
address line 2, locality, administrative area, postal code, and country code.
The address is emitted as both an auxiliary event-ticket field and back-field
detail.

## Semantic metadata

Only values proven by MEL's issued ticket and event data are emitted:

- `eventName`;
- `eventStartDate`;
- `eventEndDate`;
- `venueName`;
- `venueLocation` when `EventGeoResolver` returns valid coordinates.

The pass also retains legacy `eventTicket` fields and root `locations` for
compatible rendering and relevance.

No performer, venue-room, seat, or Apple event-type fields are emitted: MEL
does not provide a proven canonical source or mapping for them.

## Poster event tickets

`posterEventTicket` and `preferredStyleSchemes` are intentionally deferred.
They require reviewed event-type and other semantic data contracts that are
not available in the current model. The legacy `eventTicket` structure remains
the compatible production presentation.
