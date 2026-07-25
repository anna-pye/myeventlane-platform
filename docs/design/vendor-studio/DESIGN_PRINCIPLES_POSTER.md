# Vendor Studio — Design Principles Poster

**Product Design System (PDS) v1.0 — FROZEN**  
**Also known as:** Design Operating System  
**Purpose:** A one-page culture document. Not technical. Read this before any specification.

---

## Mission

Help organisers create successful events with calm confidence — from first draft to door check-in to payout.

Vendor Studio is the event operating system organisers enjoy opening every day.

---

## Product Philosophy

Vendor Studio is a **tool for operators**, not a marketing site and not a CMS admin.

- The **event** is the centre of gravity  
- Attention is scarce — lead with what needs action  
- Trust is earned through clarity — especially with money and publishing  
- Complexity belongs backstage  
- Warmth never replaces precision when money, access, or publishing is involved  

---

## Ten Design Principles

1. **One primary action**  
2. **Guide, don’t overwhelm**  
3. **Always show the next step**  
4. **Always explain why**  
5. **Hide platform complexity**  
6. **Celebrate progress**  
7. **Mobile-capable operations**  
8. **Accessible by default**  
9. **Cards earn their size**  
10. **Consistency over novelty**  

*(Full rationale: [01-vendor-studio-vision.md](01-vendor-studio-vision.md))*

---

## Golden Rule

If the organiser cannot answer **“What should I do next?”** within five seconds of landing on a screen, the screen has failed.

---

## Three Question Framework

1. Where am I?  
2. What needs me?  
3. What is the next useful action?  

---

## How we build (v1 stack order)

Future Vendor Studio work is considered in this order — not “design a page then code”:

```text
1. Product Design System (PDS)
        ↓
2. Workspace Zones          ← composition / design test
        ↓
3. Visual Language (B.5)
        ↓
4. Component Catalogue
        ↓
5. Implementation
```

**Workspace Zones** (Identity → Guidance → Work → Outcome) are a **first-class design principle**, not a footnote inside the visual language. Spec: [`../vendor-studio-visual/07-workspace-zones.md`](../vendor-studio-visual/07-workspace-zones.md).

### Zone Test (every Workspace page)

| Zone | Question |
| --- | --- |
| Identity | Where am I? |
| Guidance | What should I do next? |
| Work | How do I do it? |
| Outcome | What happened? |

If a page cannot answer these clearly, it is not finished.

### Zone Gate

Every new Workspace PR must open with a **zone map** (Identity / Guidance / Work / Outcome) **before** screenshots. No map → not designed yet.

---

## Product Personality

**Warm · Capable · Local · Calm · Honest**

Sounds like: “You’re almost ready to publish.”  
Does not sound like: “Incomplete configuration detected.”

---

## Organiser Promise

We will never make you learn Drupal to run your event.  
We will always tell you what needs you, why it matters, and what to do next.  
We will treat your money, your guests, and your live event with sober care.  
We will keep Vendor Studio coherent as it grows — one shell, one language, one standard of quality.

---

Every screen should help organisers confidently create, promote and run successful events.
