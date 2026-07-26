# MyEventLane Document Register

This register records documents that establish, claim or materially interpret product, design, architecture, operational or repository authority. It does not make a document authoritative merely by listing it.

`Unknown` means repository evidence does not establish the value. Recommended actions use the controlled actions defined by the governance baseline.

## Constitutional and active product governance

| Document | Path | Category | Authority | Status | Owner | Version | Last reviewed | Governing parent | Notes or required action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| The MyEventLane Organiser Manifesto | `docs/governance/00-organiser-manifesto.md` | Constitutional governance | Highest product authority | Constitutional Document | Product Owner | 1.1 | 2026-07-26 | None | Keep |
| MyEventLane Product Constitution | `docs/governance/01-product-constitution.md` | Constitutional governance | Subordinate to Manifesto | Permanent governance | Product Owner | 1.1 | 2026-07-26 | Organiser Manifesto | Keep |
| Governance Baseline Authority | `docs/product-decisions/PDR-001-governance-baseline-authority.md` | Product decision | Repository-wide hierarchy and classification | Approved | Product Owner | Unknown | 2026-07-26 | Organiser Manifesto; Product Constitution | Keep |
| Product Delivery Traceability | `docs/product-delivery-traceability.md` | Active product governance | “Now” initiative traceability baseline | Baseline | Product Owner | Unknown | 2026-07-26 | Product Roadmap; Product Requirements | Keep |
| Discovery and Research Initiative Brief | `docs/product/initiatives/TRACE-NOW-01-discovery-research.md` | Active product governance | Discovery-only initiative boundary | Deferred | Product Owner | Unknown | 2026-07-26 | Product Delivery Traceability | Keep |
| Discovery Evidence Refresh | `docs/research/discovery/2026-07-26-evidence-refresh.md` | Research evidence | Current repository and DDEV discovery evidence | Evidence | Unknown | Unknown | 2026-07-26 | TRACE-NOW-01 initiative brief | Keep |
| Public Discovery Research Protocol | `docs/research/discovery/research-protocol.md` | Research governance | Moderated discovery research method | Deferred draft | Product Owner | 0.1 | 2026-07-26 | TRACE-NOW-01 initiative brief | Keep |
| Public Discovery Evidence-Collection Plan | `docs/research/discovery/evidence-collection-plan.md` | Research governance | Tasks, evidence and decision-readiness rules | Deferred draft | Unknown | Unknown | 2026-07-26 | Public Discovery Research Protocol | Keep |
| Vendor Studio Acceptance Initiative Brief | `docs/product/initiatives/TRACE-NOW-02-vendor-studio-acceptance.md` | Active product governance | Acceptance-only initiative boundary | VL-5 accepted; not frozen | Product Owner | Unknown | 2026-07-26 | Product Delivery Traceability | Keep |
| MyEventLane Product Strategy | `docs/governance/02-product-strategy.md` | Active product governance | Strategy | Permanent strategic governance | Unknown | Unknown | Unknown | Organiser Manifesto; Product Constitution | Keep |
| MyEventLane Product Roadmap | `docs/governance/03-product-roadmap.md` | Active product governance | Directional | Directional governance | Unknown | Unknown | Unknown | Product Strategy; Product Constitution | Keep |
| MyEventLane Product Requirements | `docs/governance/04-product-requirements.md` | Active product governance | Product-area responsibilities | Permanent product-area governance | Unknown | Unknown | Unknown | Product Constitution; Product Strategy | Keep |
| MyEventLane Operations | `docs/governance/05-operations.md` | Active operational governance | Operational principles | Permanent operational governance | Unknown | Unknown | Unknown | Organiser Manifesto; Product Constitution | Keep |
| MyEventLane Engineering Principles | `docs/governance/06-engineering-principles.md` | Engineering governance | Engineering principles | Permanent engineering governance | Unknown | Unknown | Unknown | Organiser Manifesto; Product Constitution | Keep |
| MyEventLane Documentation | `docs/README.md` | Documentation governance | Repository navigation | Active | Unknown | Unknown | Unknown | Product Constitution | Keep |
| MyEventLane Documentation Governance | `docs/GOVERNANCE.md` | Documentation governance | Governance process | Active | Unknown | Unknown | Unknown | Product Constitution | Keep |
| Contributing to MyEventLane | `CONTRIBUTING.md` | Repository workflow | Contributor entry point | Active | Unknown | Unknown | Unknown | Product Constitution; Engineering Principles | Keep |

## Product Design System and approved design authority

| Document | Path | Category | Authority | Status | Owner | Version | Last reviewed | Governing parent | Notes or required action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Vendor Studio PDS | `docs/design/vendor-studio/README.md` | Approved design authority | Required for Vendor Studio implementation | Frozen | Unknown | 1.0.3 | 2026-07-26 | Repository constitutional governance | Keep |
| Vendor Studio PDS Index | `docs/design/vendor-studio/INDEX.md` | Approved design authority | PDS navigation and local hierarchy | Frozen | Unknown | 1.0.3 | 2026-07-26 | Vendor Studio PDS | Keep |
| Design Authority Constitution | `docs/design/vendor-studio/decisions/ADR-0001-design-authority.md` | Design decision | Local PDS precedence | Accepted; Frozen pack | Product Owner; Design Authority; Technical Authority | 1.0.3 | 2026-07-26 | Repository constitutional governance; Vendor Studio PDS | Keep |
| Vendor Studio Vision | `docs/design/vendor-studio/01-vendor-studio-vision.md` | Approved design authority | Design philosophy | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Cross-reference |
| Information Architecture | `docs/design/vendor-studio/02-information-architecture.md` | Approved design authority | Vendor Studio IA | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Layout System | `docs/design/vendor-studio/03-layout-system.md` | Approved design authority | Layout intents | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Design Language | `docs/design/vendor-studio/04-design-language.md` | Approved design authority | Design language | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Component Library | `docs/design/vendor-studio/05-component-library.md` | Approved design authority | Component contracts | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Workspace Patterns | `docs/design/vendor-studio/06-workspace-patterns.md` | Approved design authority | Workspace composition patterns | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Interaction Guidelines | `docs/design/vendor-studio/07-interaction-guidelines.md` | Approved design authority | Interaction behaviour | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Mobile Guidelines | `docs/design/vendor-studio/08-mobile-guidelines.md` | Approved design authority | Mobile operation | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Drupal Mapping | `docs/design/vendor-studio/09-drupal-mapping.md` | Design implementation mapping | Drupal and Commerce mapping | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Cross-reference |
| Vendor Studio Roadmap | `docs/design/vendor-studio/10-roadmap.md` | Design roadmap | Local design sequencing | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Cross-reference |
| Design Tokens | `docs/design/vendor-studio/11-design-tokens.md` | Approved design authority | Vendor Studio tokens | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Dashboard Philosophy | `docs/design/vendor-studio/12-dashboard-philosophy.md` | Approved design authority | Current dashboard design authority | Design authority; documentation only | Unknown | RC1 | 2026-07-26 | PDS ADR-0001; repository governance | Keep |
| Event Workspace Philosophy | `docs/design/vendor-studio/13-event-workspace-philosophy.md` | Approved design authority | Workspace purpose | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Visual Identity | `docs/design/vendor-studio/14-visual-identity.md` | Approved design authority | Visual identity | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Copywriting Guide | `docs/design/vendor-studio/15-copywriting-guide.md` | Approved design authority | Product language | Design authority; documentation only | Unknown | RC1 | Unknown | PDS ADR-0001 | Keep |
| Design Review Checklist | `docs/design/vendor-studio/16-design-review-checklist.md` | Design assurance | Review gate | Frozen pack | Unknown | 1.0 | Unknown | Vendor Studio PDS | Keep |
| Product Success Metrics | `docs/design/vendor-studio/18-product-success-metrics.md` | Design assurance | Design success measures | Design authority; documentation only | Unknown | RC1 | Unknown | Vendor Studio PDS | Cross-reference |
| Definition of Done | `docs/design/vendor-studio/21-definition-of-done.md` | Design assurance | Completion gate | Frozen pack | Unknown | 1.0 | Unknown | Vendor Studio PDS | Keep |
| Design System Health | `docs/design/vendor-studio/22-design-system-health.md` | Design assurance | PDS health assessment | Design authority; documentation only | Unknown | RC1 | Unknown | Vendor Studio PDS | Keep |
| Design Governance Lifecycle | `docs/design/vendor-studio/23-governance-lifecycle.md` | Design governance | Local PDS change process | Design authority; documentation only | Unknown | RC1.1 | Unknown | PDS ADR-0001 | Cross-reference |
| Vendor Studio Visual Philosophy | `docs/design/vendor-studio-visual/01-philosophy.md` | Approved design authority | Visual-language philosophy | Approved; Frozen | Product Owner | Unknown | 2026-07-25 | Vendor Studio PDS | Keep |
| Visual Directions | `docs/design/vendor-studio-visual/02-visual-directions.md` | Design evidence | Options considered | Approved pack; exact state unclear | Unknown | Unknown | Unknown | Visual Philosophy | Mark historical |
| Visual Language B.5 | `docs/design/vendor-studio-visual/03-option-b5.md` | Approved design authority | Vendor Studio visual language | Approved; Frozen | Product Owner | 1 | 2026-07-25 | Vendor Studio PDS; Workspace Zones | Keep |
| Component Examples | `docs/design/vendor-studio-visual/04-component-examples.md` | Design reference | Examples | Approved pack; exact state unclear | Unknown | Unknown | Unknown | Visual Language B.5 | Cross-reference |
| Before and After | `docs/design/vendor-studio-visual/05-before-after.md` | Design assurance | Comparative evidence | Approved pack; exact state unclear | Unknown | Unknown | Unknown | Visual Language B.5 | Cross-reference |
| Visual Implementation Guide | `docs/design/vendor-studio-visual/06-implementation-guide.md` | Implementation guidance | Visual delivery guidance | Approved pack; exact state unclear | Unknown | Unknown | Unknown | Visual Language B.5 | Cross-reference |
| Workspace Zones | `docs/design/vendor-studio-visual/07-workspace-zones.md` | Approved design authority | Workspace composition | Approved; Frozen | Product Owner | Unknown | 2026-07-25 | Vendor Studio PDS | Keep |
| Vendor Component Catalogue | `docs/design/vendor-workspace-v2/23-vendor-component-catalogue.md` | Approved design authority | Freeze and status ledger | Product Owner endorsed; mixed component states | Product Owner | Unknown | 2026-07-25 | PDS Component Library; Workspace Zones; B.5 | Keep |
| Vendor Studio Current-State and Catalogue Reconciliation | `docs/design/vendor-workspace-v2/24-current-state-catalogue-reconciliation.md` | Design assurance | Reconciled implementation and acceptance state | Active reconciliation record | Product Owner | Unknown | 2026-07-26 | Vendor Component Catalogue; Vendor Studio PDS | Keep |
| VL-5 Acceptance Review | `docs/design/vendor-studio-visual/reviews/vl5/README.md` | Design assurance | Launch Success and ticket workspace acceptance evidence | Product Owner accepted; not frozen | Product Owner | Unknown | 2026-07-26 | Vendor Component Catalogue; TRACE-NOW-02 | Keep |

## Approved design decisions

| Document | Path | Category | Authority | Status | Owner | Version | Last reviewed | Governing parent | Notes or required action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Single global shell navigation | `docs/design/vendor-studio/decisions/DDR-001-shell-navigation.md` | Design decision | Vendor Studio shell | Accepted | Design Authority; Product Owner; Technical Authority | RC1 | 2026-07-25 | PDS ADR-0001 | Keep |
| One Event Workspace | `docs/design/vendor-studio/decisions/DDR-002-event-workspace.md` | Design decision | Event Workspace | Accepted | Design Authority; Product Owner; Technical Authority | RC1 | 2026-07-25 | PDS ADR-0001 | Keep |
| Intent-based content containers | `docs/design/vendor-studio/decisions/DDR-003-layout-intents.md` | Design decision | Layout | Accepted | Design Authority; Technical Authority | RC1 | 2026-07-25 | PDS ADR-0001 | Keep |
| Component philosophy | `docs/design/vendor-studio/decisions/DDR-004-component-philosophy.md` | Design decision | Components | Accepted | Design Authority; Technical Authority | RC1 | 2026-07-25 | PDS ADR-0001 | Keep |
| Mobile first-class operating surface | `docs/design/vendor-studio/decisions/DDR-005-mobile-first.md` | Design decision | Mobile | Accepted | Design Authority; Product Owner | RC1 | 2026-07-25 | PDS ADR-0001 | Keep |
| Payments hub | `docs/design/vendor-studio/decisions/DDR-006-payments-hub.md` | Design decision | Payments workspace | Accepted | Design Authority; Product Owner; Technical Authority | RC1.1 | 2026-07-25 | PDS ADR-0001 | Keep |
| Marketing separate from Analytics | `docs/design/vendor-studio/decisions/DDR-007-marketing-analytics-separation.md` | Design decision | Navigation and product boundaries | Accepted | Design Authority; Product Owner | RC1.1 | 2026-07-25 | PDS ADR-0001 | Keep |
| Canonical Event Workspace | `docs/design/vendor-studio/decisions/DDR-008-canonical-event-workspace.md` | Design decision | Workspace path and shell | Accepted | Design Authority; Product Owner; Technical Authority | 1.0 | 2026-07-25 | PDS ADR-0001 | Keep |
| Workspace Navigation | `docs/design/vendor-studio/decisions/DDR-009-workspace-navigation.md` | Design decision | Workspace navigation | Accepted | Design Authority; Product Owner; Technical Authority | 1.0 | 2026-07-25 | PDS ADR-0001 | Keep |

## Workspace, Mission Control, Launch Centre and dashboard records

| Document | Path | Category | Authority | Status | Owner | Version | Last reviewed | Governing parent | Notes or required action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Mission Control Model | `docs/design/vendor-workspace-v2/04-mission-control-model.md` | Product design | Mission Control discovery model | Discovery; no implementation | Unknown | Unknown | 2026-07-25 | PDS design chain | Cross-reference |
| Workspace Mission Control | `docs/design/vendor-workspace-v2/10-workspace-mission-control.md` | Product design | Ideal workspace | Product design philosophy; documentation only | Unknown | Unknown | 2026-07-25 | PDS and Mission Control Model | Cross-reference |
| Workspace Foundation Review | `docs/design/vendor-workspace-v2/13-workspace-foundation-review.md` | Implementation assurance | Foundation delivery review | Implementation complete; awaiting Product Owner review | Unknown | Unknown | 2026-07-25 | PDS and DDRs | Keep |
| Launch Centre Wireframes | `docs/design/vendor-workspace-v2/18-launch-centre-wireframes.md` | Approved design authority | Launch Centre hierarchy | High-fidelity design; Home frozen | Unknown | Unknown | 2026-07-25 | PDS and publishing state model | Keep |
| Launch Success Experience | `docs/design/vendor-workspace-v2/20-launch-success-experience.md` | Product design | Post-publish outcome | Implemented and accepted; not frozen | Product Owner | Unknown | 2026-07-26 | Launch Centre; Component Catalogue | Keep |
| Mission Control Dashboard Governance | `docs/mission-control-dashboard-governance.md` | Implementation governance | Dashboard hierarchy record | Historical | Unknown | Unknown | 2026-07-26 | PDS Dashboard Philosophy | Mark historical |
| Mission Control Principles | `docs/mission-control-dashboard-governance-final.md` | Implementation governance | Dashboard hierarchy record | Historical | Unknown | Unknown | 2026-07-26 | PDS Dashboard Philosophy | Mark historical |
| Event-First Dashboard Governance | `docs/event-first-dashboard-governance.md` | Implementation governance | Dashboard hierarchy record | Historical | Unknown | Unknown | 2026-07-26 | PDS Dashboard Philosophy | Mark historical |
| Event-First Dashboard Principles | `docs/event-first-dashboard-governance-final.md` | Implementation governance | Dashboard hierarchy record | Historical | Unknown | Unknown | 2026-07-26 | PDS Dashboard Philosophy | Mark historical |
| Dashboard vs Event Workspace Governance | `docs/dashboard-vs-workspace-governance.md` | Implementation governance | Dashboard/workspace boundary record | Historical | Unknown | Unknown | 2026-07-26 | PDS Dashboard and Event Workspace philosophies | Mark historical |
| Mission Control Dashboard Audit | `docs/mission-control-dashboard-audit.md` | Design audit | Dashboard evidence | Unknown | Unknown | Unknown | Unknown | Mark historical |
| Dashboard Workspace Boundary Audit | `docs/dashboard-workspace-boundary-audit.md` | Design audit | Boundary evidence | Unknown | Unknown | Unknown | Unknown | Cross-reference |

## Product areas, architecture and assurance

| Document | Path | Category | Authority | Status | Owner | Version | Last reviewed | Governing parent | Notes or required action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Product Reset Phase 1 Source of Truth | `docs/product-reset-phase-1-source-of-truth.md` | Product evidence | Discovery, Event Studio and booking rationale | Historical | Unknown | Unknown | 2026-07-26 | Current Product Strategy and Requirements | Mark historical |
| Product Reset Phase 1 Audit | `docs/product-reset-phase-1-audit.md` | Product audit | Phase 1 evidence | Unclear | Unknown | Unknown | Unknown | Product Reset source | Mark historical |
| Product Reset Phase 1 Deferred | `docs/product-reset-phase-1-deferred.md` | Product backlog record | Deferred work | Unclear | Unknown | Unknown | Unknown | Product Reset source | Cross-reference |
| Canonical Checkout Flow | `docs/architecture/ADR-0001-canonical-checkout-flow.md` | Architecture decision | Checkout architecture | Accepted | Unknown | Unknown | Unknown | Product Requirements; Engineering Principles | Keep |
| Checkout ADR Implementation | `docs/architecture/ADR-0001-implementation.md` | Implementation record | Checkout implementation evidence | Implemented with validation notes | Unknown | Unknown | Unknown | Canonical Checkout ADR | Keep |
| Canonical Event Ownership | `docs/adr/ADR-0008-canonical-event-ownership.md` | Architecture decision | Event ownership | Accepted; workstreams implemented | Unknown | Unknown | 2026-07-21 | Product Requirements; Engineering Principles | Keep |
| Payment Runtime Architecture | `docs/adr/ADR-002-payment-runtime.md` | Architecture decision | Current payment runtime | Accepted as current-runtime documentation | Engineering documentation | Unknown | 2026-07-20 | Product Requirements; Engineering Principles | Keep |
| Stripe Connect Strategy | `docs/adr/ADR-003-stripe-connect-strategy.md` | Architecture proposal | Stripe Connect direction | Proposed; pending Product ratification | Unknown | Unknown | 2026-07-20 | Product Strategy; Payments requirement | Needs Product Owner decision |
| Advanced Ticketing Foundations Audit | `docs/ADVANCED_TICKETING_FOUNDATIONS_AUDIT.md` | Engineering audit | Ticketing evidence | Unclear | Unknown | Unknown | Unknown | Ticket requirement | Cross-reference |
| MEL RSVP Audit Report | `docs/MEL_RSVP_AUDIT_REPORT.md` | Engineering audit | RSVP evidence | Unclear | Unknown | Unknown | Unknown | RSVP requirement | Cross-reference |
| Checkout Discovery Report | `docs/PHASE_0_CHECKOUT_DISCOVERY_REPORT.md` | Product and engineering audit | Checkout evidence | Unclear | Unknown | Unknown | Unknown | Checkout and Orders requirements | Cross-reference |
| Customer Operational Commerce Experience | `docs/customer-operational-commerce-experience.md` | Product/implementation record | Customer commerce journey | Unclear | Unknown | Unknown | Unknown | Product Requirements | Cross-reference |
| Messages Hub | `docs/implementation/vx2-06-messages-hub.md` | Implementation record | Messages surface | Unclear | Unknown | Unknown | Unknown | Messages requirement | Cross-reference |
| Payments Hub | `docs/implementation/vx2-07-payments-hub.md` | Implementation record | Payments surface | Unclear | Unknown | Unknown | Unknown | Payments requirement; DDR-006 | Cross-reference |
| Analytics Hub | `docs/implementation/vx2-08-analytics-hub.md` | Implementation record | Analytics surface | Unclear | Unknown | Unknown | Unknown | Analytics requirement | Cross-reference |
| Marketing Hub | `docs/implementation/vx2-09-marketing-hub.md` | Implementation record | Marketing surface | Unclear | Unknown | Unknown | Unknown | Marketing requirement; DDR-007 | Cross-reference |
| Organiser Acceptance | `docs/launch/organiser-acceptance/organiser-acceptance.md` | Launch assurance | Organiser acceptance evidence | Unclear | Unknown | Unknown | Unknown | Product requirements; approved designs | Cross-reference |
| Customer Acceptance | `docs/launch/customer-acceptance/customer-acceptance.md` | Launch assurance | Customer acceptance evidence | Unclear | Unknown | Unknown | Unknown | Product requirements | Cross-reference |
| Launch Sign-off | `docs/launch/launch-certification/launch-signoff.md` | Launch assurance | Launch decision record | Unclear | Unknown | Unknown | Unknown | Operations; release governance | Needs Product Owner decision |
| Launch Evidence | `docs/launch/launch-certification/launch-evidence.md` | Launch assurance | Launch evidence | Unclear | Unknown | Unknown | Unknown | Launch Sign-off | Cross-reference |

## Engineering standards and repository workflow

| Document | Path | Category | Authority | Status | Owner | Version | Last reviewed | Governing parent | Notes or required action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| MyEventLane Development Workflow | `docs/DEVELOPMENT_WORKFLOW.md` | Repository workflow | Standard local workflow | Active | Product Owner | Unknown | 2026-07-26 | CONTRIBUTING; Engineering Principles | Keep |
| Git and Repository Rules | `docs/operations/DEV_GIT_RULES.md` | Repository workflow | Historical workflow | Superseded | Unknown | Unknown | 2026-07-26 | DEVELOPMENT_WORKFLOW; CONTRIBUTING | Mark historical |
| Git Push Workflow | `docs/GIT_PUSH_WORKFLOW.md` | Repository workflow | Legacy push guidance | Unclear | Unknown | Unknown | Unknown | CONTRIBUTING | Mark historical |
| Testing Guide | `docs/TESTING_GUIDE.md` | Engineering standard | Testing guidance | Unclear | Unknown | Unknown | Unknown | Engineering Principles | Cross-reference |
| Workflows | `docs/workflows.md` | Repository workflow | Unknown | Unknown | Unknown | Unknown | Unknown | CONTRIBUTING | Mark historical |
| Vendor Studio Contributing | `docs/design/vendor-studio/CONTRIBUTING.md` | Design workflow | Frozen PDS contribution process | Frozen | Unknown | 1.0 | Unknown | PDS ADR-0001; repository CONTRIBUTING | Cross-reference |

## Source and reference material classification

| Material | Repository path | Classification | Status | Recommended action |
| --- | --- | --- | --- | --- |
| MEL style guide | Unknown | Brand/reference asset | Not found | Needs Product Owner decision |
| Product Reset Source of Truth source file | Unknown | Research/product reference | Not found under the supplied source title | Needs Product Owner decision |
| MEL Plan | Unknown | Prompt/working guidance | Not found | Needs Product Owner decision |
| Visual Direction | Unknown | Research/design reference | Not found under the supplied source title | Needs Product Owner decision |
| MEL Context Primer | Unknown | Prompt/working guidance | Not found | Needs Product Owner decision |
| MEL Meta Prompt Generator and Reviewer | Unknown | Prompt/working guidance | Not found | Needs Product Owner decision |
| Market-grounding sources | Unknown | Research and market evidence | Not found | Needs Product Owner decision |
| Prompt guidance | Unknown | Prompt/working guidance | Not found | Needs Product Owner decision |
| MyEventLane login image | `web/sites/default/files/myeventlane-login-image.png` and generated derivatives | Brand/runtime asset | Multiple runtime copies; tracked authority not established | Needs Product Owner decision |

The login image is outside `/docs` and has multiple generated/runtime copies. This audit does not identify a safe canonical asset path and does not move it.
