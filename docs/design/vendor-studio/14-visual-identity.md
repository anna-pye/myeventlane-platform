# Vendor Studio — Visual Identity

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Describe how Vendor Studio should **feel** — the emotional and visual character that makes it unmistakably MyEventLane while serving operations, not discovery.

## Scope

Emotional goals, hierarchy, whitespace, cards, type, colour usage, illustration, photography, iconography, motion, micro-interactions, premium feel, and differentiation. Numeric tokens live in [11](11-design-tokens.md). Brand strategy for public MEL lives in `docs/brand/`.

## Audience

Designers, brand contributors, theme architects, product leaders.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [04-design-language.md](04-design-language.md)
- [11-design-tokens.md](11-design-tokens.md)
- [15-copywriting-guide.md](15-copywriting-guide.md)
- [A02-design-principles-vs-humanitix.md](appendices/A02-design-principles-vs-humanitix.md)

---

## Emotional goals

| Feel | Means |
| --- | --- |
| Calm confidence | Clear next step; no dashboard panic |
| Warm competence | Human, local, precise with money |
| Focused energy | Purple primary is scarce and meaningful |
| Trustworthy | Honest states; sober refunds |
| Light to return to | Organisers *want* to open it daily |

Not: exclusive VIP theatre · clinical admin grey · neon “pro dark” by default · FOMO badge spam.

---

## Visual hierarchy

1. Task title (H1)  
2. Primary action  
3. Attention / status  
4. Supporting content  
5. Help  

If everything is bold, nothing is. Hierarchy is the product.

---

## Whitespace philosophy

Whitespace is a **ranking tool**, not decoration.

- Air around attention lists  
- Compress empty marketing voids in ops heroes  
- Dense tables may tighten rows; page chrome stays breathable  
- Ultra-wide unused side space on forms is intentional calm  

---

## Card philosophy

Cards earn their size ([01](01-vendor-studio-vision.md) principle 9).

| Use a card when | Prefer flat when |
| --- | --- |
| The unit is interactive (action item, event row) | Grouping alone is enough |
| Boundary aids scanning of distinct tasks | Nested cards would stack chrome |

Card-inside-card is an anti-pattern ([19](19-anti-patterns.md)).

---

## Minimalism

Minimalism here means **one job per region**, not emptiness. Remove chrome that does not answer the Three Questions. Keep the data organisers need.

---

## Typography hierarchy

Readable sans; one H1; sentence case; marketing display faces stay on public MEL. Detail: [11](11-design-tokens.md).

---

## Colour usage

Warm cream canvas, white surfaces, scarce purple, coral focus, rare gold. Semantic colours for severity. Full rules: [11](11-design-tokens.md).

---

## Illustration usage

- Optional in empty/success states  
- Guide presence is encouraging, **not a mascot in chrome**  
- Never required to understand state  
- Prefer clarity over character scenes in Door Mode  

---

## Photography

- Event imagery belongs in content previews and public pages  
- Ops heroes are operational, not full-bleed marketing theatres  
- Do not paste public homepage hero patterns into console chrome  

---

## Iconography

- Simple, consistent stroke/weight  
- Paired with text for severity and nav  
- Not a substitute for labels on primary actions  

---

## Animation philosophy

Motion explains state (open, save, success). It does not entertain. Honour reduced motion. Tokens: [11](11-design-tokens.md). Behaviour: [07](07-interaction-guidelines.md).

---

## Micro-interactions

| Allowed | Disallowed |
| --- | --- |
| Button busy, save status, subtle row hover | Confetti FOMO, parallax chrome |
| Focus ring clarity | Hover-only essential info |
| Dialog confirm for money | Playful treatment of refunds |

---

## Premium feel

Premium in Vendor Studio means:

- Precision with money and publish  
- Coherent spacing and type  
- Recoverable errors  
- Speed under door stress  

It does not mean glassmorphism, badge theatres, or imitating fintech dark dashboards.

---

## Compare against

| Surface | Relationship | Why Vendor Studio differs |
| --- | --- | --- |
| **Public MEL** | Shared brand DNA | Discovery vs operations; different hero and card jobs |
| **Drupal admin** | Implementation platform | Organiser language; no CMS IA; warmth over admin grey |
| **Humanitix** | Competitor reference | Philosophy compare only ([A02](appendices/A02-design-principles-vs-humanitix.md)); do not copy chrome |
| **Shopify** | Ops excellence reference | Commerce backstage; event lifecycle and Door Mode are MEL-specific |
| **Stripe** | Money clarity reference | Sober payments UX inspiration; MEL remains community-warm, not pure fintech |
| **Linear** | Focus and speed reference | Issue-tracker density is not the default; attention queues beat keyboard-first culture for door stress |

### Why Vendor Studio is different

Vendor Studio is built for **Australian community event operators** — warm like MEL public, precise like a payments console, structured like a modern ops tool — without becoming admin UI, marketplace FOMO, or a cloned competitor shell.

We do not imitate. We **borrow principles** (clarity, scarcity of primary actions, honest money) and express them through MEL’s Hidden Gem + Guide soul applied to operations.

---

## Design implications

- Visual PRs cite this file + [11](11-design-tokens.md)
- “Make it pop” is not a requirement; “make the next step obvious” is
- Public and Studio themes must not converge into one muddled aesthetic

## Future considerations

- Phase 12 dark mode must still feel MEL-warm  
- Illustration library expansion only with empty-state jobs  
- Organiser brand colour in previews, never shell re-skin  

## Related references

- [01](01-vendor-studio-vision.md) · [04](04-design-language.md) · [11](11-design-tokens.md) · [15](15-copywriting-guide.md) · [19](19-anti-patterns.md) · `docs/brand/`
