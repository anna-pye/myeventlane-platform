# QR Compatibility

The canonical MEL QR family remains `mel:v1:`.

Phase 2A preserves the existing signed admission payload:

```text
mel:v1:{ticket_code}:{event_id}:{issued_ts}:{signature}
```

Universal ticket metadata uses the existing QR service rather than a second QR ecosystem. Structured payloads stay inside the `mel:v1:` family and include entitlement type, expiry timestamp when present, and safe redemption capability metadata.

Structured payloads must not expose raw ownership data. Existing parsers continue to accept older structured payloads that contain previously emitted owner metadata, but new payloads no longer include it.

Scanner compatibility rules:

- Existing admission tickets continue to build and parse as signed `mel:v1:` payloads.
- Legacy non-prefixed ticket-code scans remain supported by the current parser.
- Existing wallet QR rendering still calls the existing ticket QR service.
- Scanner operations continue through the existing MEL scanner infrastructure.
- Operational UX and new scanner actions are intentionally out of scope for Phase 2A.
