# Vendor Studio — Design Maturity Model

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define how Vendor Studio evolves over time — a shared ladder for evaluating features, phases, and quality claims.

## Scope

Maturity levels, evaluation criteria, and how to use the model in planning. Not a scoreboard for individual engineers. Not an analytics implementation.

## Audience

Product Owner, Design Authority, Technical Authority, phase leads.

## Related documents

- [10-roadmap.md](10-roadmap.md)
- [18-product-success-metrics.md](18-product-success-metrics.md)
- [20-vendor-studio-v2-vision.md](20-vendor-studio-v2-vision.md)
- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)

---

## Why a maturity model

Without a ladder, every feature claims to be “delightful” while core flows remain merely workable. The model keeps ambition honest: **reach Level 2 everywhere before celebrating Level 4 anywhere**.

---

## Levels

| Level | Name | Meaning |
| --- | --- | --- |
| **1** | Functional | Organisers can complete the job. States exist. Access holds. Ugly or inconsistent is possible but not blocking. |
| **2** | Consistent | Shell, layout intents, components, and copy follow this Design OS. Three Questions answered. Anti-patterns avoided. |
| **3** | Delightful | Calm confidence: empty/success/error polish, mobile excellence for the job, celebrations that earn their place, Door Mode feels fast. |
| **4** | Intelligent | System anticipates needs with honest recommendations (readiness insights, prioritisation). Still organiser-controlled. |
| **5** | Predictive | Trusted forecasting and proactive ops (e.g. sales risk, staffing hints) with transparent confidence — never silent money/publish actions. |

```text
1 Functional → 2 Consistent → 3 Delightful → 4 Intelligent → 5 Predictive
```

---

## Evaluation dimensions

Rate a surface (e.g. Dashboard, Tickets, Door Mode) per dimension; the **surface level** is the minimum across critical dimensions.

| Dimension | L1 | L2 | L3 | L4 | L5 |
| --- | --- | --- | --- | --- | --- |
| IA / nav | Reachable | OS IA | Effortless context | Suggests next hub | Anticipates cross-event needs |
| Hierarchy | Usable | Golden Rule met | Scannable under stress | Prioritises for you | Predicts attention |
| A11y | Basic | WCAG AA solid | Excellent AT paths | Adaptive assistance* | — |
| Mobile | Possible | OS mobile rules | Door-grade | Context-aware layout | Offline-first ops |
| Money honesty | Correct | Clear copy | Recoverable excellence | Risk hints | Forecast impacts |
| Help | Exists | Contextual | Proactive tips | Assistant panel | Autonomous drafts* |

\*Always human-confirm for money, publish, and sends.

---

## Using the model

1. **Phase exit:** A roadmap phase should lift target surfaces to at least **Level 2**.  
2. **Feature proposals:** State current level, target level, and which [18](18-product-success-metrics.md) metrics move.  
3. **No level-skipping theatre:** Do not ship “AI delight” on a Level 1 refunds flow.  
4. **v2 vision:** Levels 4–5 ideas live in [20](20-vendor-studio-v2-vision.md) until promoted.

---

## Current posture (RC1 documentation)

| Surface | Honest target for near-term implementation |
| --- | --- |
| Design OS itself | Enables Level 2+ product-wide |
| Dashboard / Workspace / Door Mode | Level 2 mandatory; Level 3 aspirational in phase polish |
| Intelligent / Predictive | Out of current [10](10-roadmap.md) until Product Owner promotes |

---

## Design implications

- “Done” means Level 2 unless the phase brief says otherwise  
- Marketing language in release notes must not claim Level 4/5 without evidence  

## Future considerations

- Quarterly maturity review in governance cadence ([README](README.md))  
- Per-surface scorecard living outside this pack once implementation lands  

## Related references

- [10](10-roadmap.md) · [18](18-product-success-metrics.md) · [20](20-vendor-studio-v2-vision.md) · [19](19-anti-patterns.md)
