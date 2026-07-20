# Apple Wallet ticket presentation

## Responsibility boundaries

`WalletEventPresentation` owns the attendee-facing `eventTicket` projection:
the event, date, venue, address, admission details, and location metadata.
`PkPassBuilder` owns `pass.json` assembly, static identity assets, manifest
creation, signing input, and archive generation.

Signing remains exclusively in `WalletSigner`. QR payload creation remains
exclusively in `UniversalTicketViewModelBuilder` / `TicketQrPayload`.

## Apple authority and implementation principles

MEL follows Apple's current [Wallet Human Interface
Guidelines](https://developer.apple.com/design/human-interface-guidelines/wallet),
[Event Ticket guidance](https://developer.apple.com/documentation/walletpasses/creating-an-event-pass-using-semantic-tags),
[Pass Images](https://developer.apple.com/documentation/walletpasses/creating-a-pass-with-pass-designer),
[Creating the source for a
Pass](https://developer.apple.com/documentation/walletpasses/creating-the-source-for-a-pass),
and [Building a Pass](https://developer.apple.com/documentation/walletpasses/building-a-pass).

MyEventLane intentionally follows Apple’s Wallet conventions rather than
reproducing the MyEventLane visual identity inside Wallet. The attendee
experience should feel native to the platform while preserving organiser and
event identity through Apple’s supported pass fields.

Applied principles:

- use the Event Ticket pass type for admission;
- make the entry decision glanceable: event, date, time, venue, attendee, and
  QR code take precedence;
- preserve the QR code as the admission mechanism and do not compete with it;
- let Wallet determine its native field layout and wrapping; and
- keep issuer identity subtle, with no duplicated brand treatment.

## HIG audit checklist

- [x] Entry-first hierarchy: the front contains only event title, date, time,
  venue or locality fallback, attendee when available, and the QR code.
- [x] Admission-specific information is reverse-only. This includes the
  current admission label and supports future values such as VIP, Early Bird,
  Weekend Pass, Session, or Reserved Seating without putting them on the
  front.
- [x] Long street addresses are reverse-only. The front never truncates an
  address to make it fit.
- [x] The event title is the sole primary field. Wallet controls field
  visibility when values wrap or exceed device capacity.
- [x] MEL’s icon, logo, and logo text are the only issuer identity assets;
  there is no duplicate branded event image or static strip.
- [x] QR payload, pass signing, and repository-proven semantic metadata remain
  separate from presentation policy.

## Front and reverse hierarchy

The compatible `eventTicket` front contains:

1. Event title — primary field.
2. Date — secondary field.
3. Time — secondary field.
4. Venue — auxiliary field. When no venue name is available, locality and
   region may appear instead.
5. Attendee — auxiliary field when available.
6. QR code — supplied by the canonical ticket QR service.

The reverse/details contains:

- complete formatted venue address;
- attendee name;
- admission-specific information;
- booking reference;
- ticket code;
- organiser; and
- issuing platform.

Support, refund, and website information are not currently emitted because the
issued-ticket model does not provide canonical Wallet-specific values. They
must not be inferred from checkout content or duplicated from another surface.

The full address uses the single Wallet venue formatter. Structured addresses
retain address line 1, address line 2, locality, administrative area, postal
code, and country code. Venue-name precedence is event `field_venue_name`,
then the referenced `field_venue` label. Address precedence is event
`field_venue_address`, event `field_location`, referenced Venue
`primary_address`, then the issued-ticket model's legacy `event.location`.

## Wallet image policy

MEL has one explicit Event Ticket policy: **never package `strip.png`.**

Apple’s current Pass Designer image matrix marks strip images unavailable for
Event Tickets. This policy is intentionally stricter than suitability detection
because the repository cannot reliably prove that an uploaded hero is free of
screenshots, embedded text, logos, character sheets, marketing graphics,
decorative borders, or empty space. Therefore MEL does not:

- assess heroes for conditional strip use;
- crop, infer a focal point, or create a Wallet-specific derivative;
- substitute issuer branding or any MEL artwork as filler; or
- package static event art that can impair readability or QR scanning.

The supplied icon and logo assets remain required identity assets at their
provided resolutions. A future image change requires an Apple-documented Event
Ticket placement for MEL’s supported devices and a separate implementation
decision; existing focal-point metadata has no Wallet runtime role.

## Poster Event Ticket ADR

**Decision:** defer `preferredStyleSchemes: ["posterEventTicket",
"eventTicket"]` until after launch.

Apple documents poster Event Tickets for iOS 26 and later and watchOS 26 and
later. They can use semantic tags to create a richer native event experience
and retain legacy `eventTicket` fields for devices that fall back to the
traditional design.

- Benefits: better native event context and system-generated layout on
  supported devices.
- Trade-offs: Apple explicitly says poster Event Tickets are incompatible with
  QR or barcode entry. MEL’s QR is the production admission contract.
- Compatibility: legacy `eventTicket` fields remain necessary for unsupported
  devices and as the current universal entry pass.
- Migration effort: introduce and validate a non-barcode admission path, then
  govern required semantics including venue region, venue room, and applicable
  event-type data. Do not invent missing semantics.
- Roadmap: launch the legacy native Event Ticket with QR; reassess only after a
  non-barcode admission capability and the required data contracts are proven.

## Semantics and constraints

Only repository-proven metadata is emitted: `eventName`, event start/end,
`venueName`, and `venueLocation` when `EventGeoResolver` returns valid
coordinates. Root `locations` remains for compatible relevance behaviour.

Apple controls final field wrapping, truncation, and device-specific capacity.
MEL therefore keeps the entry information minimal and preserves operational
details on the reverse.
