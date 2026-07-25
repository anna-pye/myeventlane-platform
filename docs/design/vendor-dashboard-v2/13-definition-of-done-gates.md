# Definition of Done Gates — Vendor Dashboard v2 Foundation

Copied from [21-definition-of-done.md](../vendor-studio/21-definition-of-done.md).

### Design and product

- [x] **Design review** — Package + checklist ready; **human Design Authority still open**
- [x] **Information Architecture** — Fits 02; organiser labels
- [x] **Three Question Framework** — Where / needs me / next action
- [x] **Golden Rule** — Queue within five seconds after identity
- [x] **Component Library compliance** — Extends 05; DDR-004
- [x] **Design Tokens compliance** — 11 / 03 layout intent

### Accessibility and interaction

- [x] **Accessibility (WCAG AA)** — Code-reviewed; contrast/focus/severity text
- [x] **Mobile** — 08 / DDR-005 stacking + 44px CTAs
- [x] **Keyboard** — Primary tasks via native links; focus visible

### Content and states

- [x] **Copywriting** — 15; AU English; no CMS jargon
- [x] **Loading states** — Skeleton; no fake metrics
- [x] **Empty states** — Caught-up + next steps
- [x] **Error states** — N/A new; existing queue CTAs retained
- [x] **Success states** — Caught-up / Pro welcome next actions

### Platform integrity

- [x] **Security** — No access bypass; Door Mode URLs access-checked
- [x] **Drupal architecture** — Logic in builder/preprocess; cache set
- [x] **Commerce architecture** — No invented payment/order states
- [x] **Performance** — No new vanity queries; max-age 300

### Documentation and process

- [x] **Documentation updated** — `docs/design/vendor-dashboard-v2/*` + PR cites
- [x] **Design Review Checklist completed** — [12](12-design-review-checklist.md)

### Explicit open items (do not block code completeness; block merge until signed)

- [ ] Design Authority acknowledgement  
- [ ] Technical Authority acknowledgement (cache max-age)  
- [ ] Commit + PR (await instruction)
