# MEL Guide Character System

**Version:** 1.0  
**Purpose:** Define guide archetypes, phrases, placement, and rules for the MEL Guide framework.

Guides are a **tone and illustration system**, not a mascot. They appear at moments where users benefit from encouragement, orientation, or affirmation.

---

## Guide archetypes

### Explorer Guide

| Attribute | Value |
|-----------|-------|
| **Purpose** | Discovery |
| **Phrase** | “Come look at this.” |
| **When to use** | Homepage discovery rails, Hidden Gem callouts, “Near you” empty-to-content transitions, browse nudges |
| **Tone** | Curious, inviting, peer-to-peer |
| **MEL example** | Beside a “Hidden Gems this weekend” rail: Explorer Guide illustration with short copy “Come look at this — locals are loving these.” |

### Host Guide

| Attribute | Value |
|-----------|-------|
| **Purpose** | Welcome |
| **Phrase** | “You’re welcome here.” |
| **When to use** | First visit prompts, event detail reassurance, inclusive event types, community listings |
| **Tone** | Warm, grounding, inclusive |
| **MEL example** | On a free community event page: “You’re welcome here — no experience needed, just turn up.” |

### Curator Guide

| Attribute | Value |
|-----------|-------|
| **Purpose** | Recommendations |
| **Phrase** | “I think you’ll love this.” |
| **When to use** | Editor’s Pick, personalised rails, “Because you liked…”, email recommendations |
| **Tone** | Thoughtful, confident but not pushy |
| **MEL example** | Editor’s Pick card footer: “I think you’ll love this — hand-picked for curious locals.” |

### Helper Guide

| Attribute | Value |
|-----------|-------|
| **Purpose** | Onboarding |
| **Phrase** | “Let’s get started.” |
| **When to use** | Account creation, vendor Event Studio first steps, filter tutorials, RSVP walkthrough |
| **Tone** | Patient, clear, step-oriented |
| **MEL example** | New vendor in Event Studio: Helper Guide with “Let’s get started — publish your first event in a few steps.” |

### Celebrating Guide

| Attribute | Value |
|-----------|-------|
| **Purpose** | Success moments |
| **Phrase** | “Great choice.” |
| **When to use** | Post-purchase, RSVP confirmed, saved event, review submitted |
| **Tone** | Affirming, brief, joyful without exaggeration |
| **MEL example** | Checkout complete: “Great choice — your tickets are confirmed. See you there.” |

---

## Visual direction

- **Style:** Simple, modern illustration — human figures or abstract guide forms, not cartoon mascots with names and costumes.
- **Colour:** Use brand tokens (Primary Purple, Lavender, Discovery Gold, Warm Cream) — see [design-tokens.md](design-tokens.md).
- **Scale:** Guides support content; they do not dominate the layout. On mobile, illustration height should not push primary CTAs below the fold.
- **Diversity:** Guides should read as inclusive and age-neutral; avoid stereotyped characters.

---

## Placement rules

| Surface | Allowed guides | Notes |
|---------|----------------|-------|
| Homepage | Explorer, Curator | Hero or first discovery rail only — not every section |
| Event browse | Explorer | Sparse — prefer badge and copy over illustration |
| Event detail | Host, Curator | One guide moment maximum per page |
| Checkout / RSVP | Helper | Only if a step needs explanation |
| Success states | Celebrating | Single affirmation line + optional small illustration |
| Vendor Event Studio | Helper | Onboarding and milestone moments |
| Email | Curator, Celebrating | Transactional emails — Celebrating; digests — Curator |

---

## Copy rules

1. **Use the archetype phrase as inspiration**, not a mandatory exact string every time. Variations must keep the same intent.
2. **One guide voice per screen** — do not mix Explorer and Helper on the same view.
3. **Australian English** — “organise”, “favourite”, “neighbourhood”.
4. **Short** — One sentence plus optional subline; never a paragraph from the guide.

---

## Hard rules

| Rule | Rationale |
|------|-----------|
| **Not a mascot** | No named character universe, merchandise, or child-targeted branding |
| **Not childish** | No baby talk, excessive exclamation marks, or clipart aesthetic |
| **Not sarcastic** | MEL is warm and sincere |
| **Not sales-focused** | No “Buy now”, “Don’t miss out”, or fake urgency in guide copy |
| **Always encouraging** | Even empty states suggest a positive next step |

---

## What guides must never say

- “Exclusive access”
- “VIP only”
- “Secret event”
- “You’d be crazy to miss this”
- “Members only”

See [copy-guidelines.md](copy-guidelines.md) for the full avoided vocabulary.

---

## Asset location

Guide illustrations are stored under `docs/brand/assets/guide/` as they are produced in Phase 2 of the [implementation roadmap](implementation-roadmap.md).
