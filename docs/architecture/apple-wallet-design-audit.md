# Apple Wallet poster event-pass audit

**Date:** 2026-07-17  
**Scope:** Apple Wallet metadata and presentation in `PkPassBuilder` only.  
**External authority:** current Apple Wallet Pass documentation:

- [Defining the metadata of your Wallet Pass](https://developer.apple.com/documentation/walletpasses/defining-the-metadata-of-your-wallet-pass)
- [Creating a poster event pass using semantic tags](https://developer.apple.com/documentation/walletpasses/creating-an-event-pass-using-semantic-tags)
- [Pass.RelevantDates](https://developer.apple.com/documentation/walletpasses/pass/relevantdates-data.dictionary)

## Current generated pass

`PkPassBuilder::buildPassJson()` emits a legacy `eventTicket` only:

- no `preferredStyleSchemes`;
- no `semantics`;
- one `relevantDate` converted to UTC;
- no `relevantDates`;
- no `locations`;
- no `groupingIdentifier`;
- primary field: event title;
- secondary field: ticket type;
- auxiliary fields: holder and ticket code;
- back field: booking reference only.

It emits a QR `barcode` and a matching first entry in `barcodes`; both use
the canonical QR payload from `UniversalTicketViewModelBuilder`.

## Bundled assets

The clean branch tip contains no committed `assets/pass/` directory, despite
`writePassBundle()` requiring `icon.png`, `logo.png`, and
`paula.r@example.org`. Therefore there is no clean-branch image asset to
inspect, no committed strip/poster asset, and no repository-proven duplicate
logo source in this worktree.

The previous visual-QA complaint cannot be safely corrected here by copying
uncommitted assets from the original dirty worktree.

## Source-field audit

| Value | Confirmed source | Safe for pass metadata? |
|---|---|---|
| Event title | Event node label via `UniversalTicketViewModelBuilder` | Yes |
| Start / end | `field_event_start`, `field_event_end` in the ticket view model | Yes as timestamps; event timezone is not preserved in the model |
| Venue name | `field_venue_name` and `field_venue` exist in config | Field exists, but no canonical Wallet presenter confirmed |
| Venue address / locality / state / country | `field_venue_address` and `field_location` address fields exist | Field existence confirmed; no canonical address formatter is used by Wallet code |
| GPS | `EventGeoResolver` implements an explicit precedence chain | Yes, if the Wallet service is wired to it |
| Organiser | Ticket vendor reference, then `field_event_vendor`, in the ticket view model | Yes |
| Category | `field_category` is a taxonomy reference | Field exists; Apple event-type mapping is not implemented or proven |
| Venue room | No event room/subvenue field or canonical value found | No |
| Performers | No reliable event performer field/value found | No |
| Seats | No ticket seat allocation model found | No |

## Current Apple requirements and consequence

Apple’s current metadata documentation states that poster event tickets are
available only for **sports** and **live performance** event tickets. Apple’s
poster-event guidance requires semantic metadata for the poster layout,
including venue metadata. The repository does not prove:

1. a safe `venueRoom` source;
2. a category mapping that classifies every event as a supported Apple sports
   or live-performance type; or
3. a timezone-preserving event datetime value.

Emitting `posterEventTicket`, guessed room/category values, or UTC values
presented as local event time would violate the task’s no-fabrication
requirement and can make Wallet fall back to legacy layout.

## Decision

**Stop before implementation.** Keep the existing legacy `eventTicket` until
the data contract provides:

- an explicit optional venue-room value (or Apple confirms it is not required
  for the chosen semantic layout);
- a reviewed taxonomy-to-`PKEventType*` mapping;
- timezone-aware event start/end values; and
- committed, approved Apple pass assets that can be audited in the clean
  branch.

No signing, barcode, Google Wallet, access-control, or action-builder code has
been changed.
