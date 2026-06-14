# MEL Brand System v1.0

**Primary brand system document for MyEventLane**

---

## Brand Vision

MyEventLane is Australia’s most welcoming way to discover local experiences. We connect curious people with community-led events, independent organisers, and hidden gems — making it easy to explore, join, and belong.

---

## Brand Promise

**There is always something amazing happening nearby.**

Every surface should reinforce that promise through discovery patterns, encouraging guides, and optimistic visual language.

---

## Positioning

**MyEventLane helps people discover experiences they never knew existed.**

MEL competes on discovery quality and belonging — not exclusivity, not corporate event management aesthetics, not anxiety-driven urgency.

---

## Brand Pillars

| Pillar | Definition | MEL example |
|--------|------------|-------------|
| **Discovery** | Reveal worthwhile nearby experiences | “Hidden Gem” badge on a neighbourhood jazz night with 40 seats |
| **Belonging** | Welcome all audiences without gatekeeping | RSVP and ticket flows use plain language; no “members only” framing |
| **Participation** | Show people doing things, not empty venues | Event cards use photography of audiences and makers in action |
| **Local Culture** | Reflect place and community | Homepage rails scoped to city or suburb, not generic national feeds |
| **Optimism** | Encourage trying something new | Guide copy: “Come look at this” — inviting, not salesy |

---

## Hidden Gem Framework

**Purpose:** Identify and present experiences that are high value but low visibility — the events people are glad they found.

### What qualifies as a Hidden Gem

- Strong community or creative merit with limited mainstream promotion
- Local relevance (venue, organiser, or audience rooted in place)
- Genuine discovery moment for a typical MEL user

### How Hidden Gem appears

- **Badge:** `Hidden Gem` on event cards and detail pages (see [event-card-system.md](event-card-system.md))
- **Copy:** “A local favourite you might not have found yet” — not “secret” or “exclusive”
- **Placement:** Discovery rails, “Near you” sections, editorial picks
- **Photography:** Participation and warmth, not empty stages

### What Hidden Gem is not

- A pay-to-play label for vendors
- A synonym for “obscure” or “underground”
- An exclusivity signal

---

## Guide Framework

**Purpose:** Provide a consistent, human presence that helps users explore, decide, and complete actions — without becoming a mascot or sales character.

Guides are expressed through:

- Illustration direction (see [guide-character-system.md](guide-character-system.md))
- Microcopy tone
- Placement at decision points (homepage hero, empty states, onboarding, success moments)

### Guide principles

- Encouraging, never sarcastic
- Helpful, never pushy
- Warm, never childish
- Present at moments of uncertainty, absent when the UI is self-explanatory

**MEL example:** After a successful ticket purchase, Celebrating Guide energy: “Great choice — see you there.” Not: “You’re in the VIP club now.”

---

## Voice & Tone

### Voice attributes

| Attribute | Description |
|-----------|-------------|
| **Warm** | Friendly Australian English; contractions acceptable where natural |
| **Clear** | Short sentences; plain words for actions and outcomes |
| **Curious** | Questions and invitations, not commands |
| **Inclusive** | No assumed insider knowledge |
| **Optimistic** | Assume good options exist nearby |

### Tone by context

| Context | Tone | Example |
|---------|------|---------|
| Homepage hero | Inviting discovery | “Discover what’s on near you this week” |
| Event card | Informative, light | “Community market · Sat 9am · 2 km away” |
| Empty state | Helpful, calm | “No events match those filters. Try a wider date range or explore nearby suburbs.” |
| Checkout success | Affirming | “You’re all set. Your tickets are on the way.” |
| Vendor-facing | Professional, supportive | “Your event is live. Here’s how to reach more locals.” |

### Words to prefer and avoid

See [copy-guidelines.md](copy-guidelines.md) for the full vocabulary list.

---

## Discovery Principles

1. **Local first** — Default to nearby and place-relevant content before national or generic feeds.
2. **Surface the unexpected** — Editorial and algorithmic rails should include Hidden Gems, not only top sellers.
3. **Scannable hierarchy** — Title, time, place, and one primary action per card or screen on mobile.
4. **Honest badges** — Only use approved badges with clear meaning; never stack badges to create false urgency.
5. **Progressive disclosure** — Show enough to decide; full detail on the event page.
6. **Search and browse parity** — Discovery language and card patterns stay consistent across entry points.

**MEL example:** The homepage “Tonight near you” rail uses the same card component and badge rules as `/events` browse — no duplicate card chrome.

---

## Belonging Principles

1. **Welcome before transaction** — Users should understand what the event is and who it is for before payment.
2. **No exclusivity theatre** — Avoid VIP, members-only, and secret-access language (see copy guidelines).
3. **Accessible information** — Venue access, pricing, and age suitability visible where possible.
4. **Inclusive imagery** — Show diverse ages, bodies, and community contexts authentically.
5. **Error states that respect the user** — Explain what happened and offer a next step; never blame the user.

**MEL example:** An all-ages community picnic lists “Free entry · Dogs welcome · Wheelchair access via side gate” in the card meta line.

---

## Participation Principles

1. **Show people in action** — Photography and illustration prioritise arrival, making, performing, and gathering.
2. **Celebrate organisers** — Credit community hosts and local creators where appropriate.
3. **Join-oriented CTAs** — “Get tickets”, “RSVP”, “Join waitlist” — not “Unlock access”.
4. **Post-purchase affirmation** — Success states reinforce participation (“See you there”) not status (“You’re in”).
5. **Social proof without pressure** — “Community Favourite” is acceptable; fake scarcity is not.

---

## Accessibility Principles

1. **WCAG AA minimum** — Colour contrast, focus states, and touch targets meet AA for all public surfaces.
2. **Readable type** — Body text legible on mobile without zoom; avoid low-contrast pastel-on-pastel combinations.
3. **Keyboard and screen reader support** — Interactive cards, filters, and CTAs have accessible names and focus order.
4. **Motion respect** — Honour `prefers-reduced-motion`; essential information never conveyed by animation alone.
5. **Plain language** — Short sentences, meaningful link text, no jargon for core actions.

**MEL example:** Primary Purple (`#6B46FF`) on Warm Cream (`#FFF7EE`) is used for text only where contrast passes AA; large button labels may use white on purple.

---

## Mobile First Principles

1. **390px baseline** — Design and review at mobile width first; enhance for tablet and desktop.
2. **One primary action per screen** — Especially on checkout, RSVP, and filter flows.
3. **Thumb-friendly targets** — Minimum 44×44px touch targets for primary controls.
4. **Vertical hierarchy** — Hero → discovery rails → categories → footer; no horizontal-only critical paths.
5. **Performance-aware** — Hero and card images use responsive styles; avoid layout shift on load.
6. **Consistent components** — Reuse event cards, buttons, and badges; no one-off mobile patterns.

**MEL example:** Homepage hero collapses to a single-column stack: headline, location/date quick filters, primary CTA, supporting discovery rail below the fold.

---

## Related documents

| Document | Topic |
|----------|-------|
| [mel-brand-strategy.md](mel-brand-strategy.md) | Strategy summary |
| [design-tokens.md](design-tokens.md) | Visual tokens |
| [guide-character-system.md](guide-character-system.md) | Guide archetypes |
| [drupal-design-governance.md](drupal-design-governance.md) | Drupal surface mapping |
| [implementation-roadmap.md](implementation-roadmap.md) | Rollout phases |
