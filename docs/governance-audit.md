# MyEventLane Governance Baseline Audit

## Scope

This documentation-only audit established a governed baseline beneath the Organiser Manifesto. It inspected the complete `docs/` file structure, documents claiming product or design authority, architecture and design decisions, launch records, repository workflow guidance and named source material.

At audit time, `docs/` contained 811 files, including Markdown, images, archives and operating records.

## Repository evidence reviewed

- `docs/governance/00-organiser-manifesto.md` through `06-engineering-principles.md`
- Vendor Studio PDS, its index, governance lifecycle, ADR and DDR records
- Workspace Zones, Visual Language B.5 and Vendor Component Catalogue
- Vendor Workspace v2 Mission Control, Workspace Foundation and Launch Centre records
- dashboard and workspace governance documents
- product reset records
- checkout, payment and event ownership ADRs
- representative ticket, RSVP, customer, message, marketing and analytics records
- launch acceptance, verification and certification collections
- repository workflow and design contribution guides
- repository-tracked filenames and current filesystem evidence for the named source materials

Repository behaviour was not changed or treated as product authority.

## Confirmed hierarchy

The following is confirmed without conflict:

1. The Organiser Manifesto is the highest product authority.
2. The Product Constitution is subordinate to the Manifesto.
3. Product strategy and product requirements are subordinate to both.
4. Roadmap position does not authorise implementation.
5. Approved and frozen design decisions remain binding within their scope unless a higher constitutional conflict is resolved through governance.
6. Implementation is the lowest authority and may not silently create policy.

The precise order of Product Design Principles, Product Design System, Workspace Zones, Visual Language and Component Catalogue was not consistently stated at discovery time. It was resolved through [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md).

## Conflict register

| ID | Documents involved | Exact conflict | Governing authority | Severity | Recommended resolution | Product Owner approval required |
| --- | --- | --- | --- | --- | --- | --- |
| GOV-001 | `docs/governance/00-organiser-manifesto.md`; `docs/governance/01-product-constitution.md`; task authority chain | The Manifesto and Constitution place Product Design Principles above Workspace Zones and the Product Design System later in the chain. The audit instruction places the Product Design System above Workspace Zones and does not name Product Design Principles. | Organiser Manifesto | Constitutional | Product Owner must confirm the intended canonical wording and approve a versioned constitutional clarification if required. | Yes |
| GOV-002 | `docs/design/vendor-studio/decisions/ADR-0001-design-authority.md`; repository constitutional documents | The PDS ADR calls itself “the constitutional document” and places itself at the top of precedence without naming the Organiser Manifesto or Product Constitution. | Organiser Manifesto; Product Constitution | Constitutional | Preserve the frozen ADR until the Product Owner approves a narrow clarification that its constitution is local and subordinate. | Yes |
| GOV-003 | PDS `README.md`; PDS `INDEX.md`; Workspace Zones; Manifesto hierarchy | The frozen PDS stack states PDS → Zones → B.5 → Catalogue → Implementation, while the Manifesto's conflict order places Product Design Principles first and Product Design System after the Catalogue. | Organiser Manifesto | Constitutional | Record one canonical global design hierarchy, then cross-reference it from the frozen pack through an approved versioned change. | Yes |
| GOV-004 | `docs/product-reset-phase-1-source-of-truth.md`; new strategy and requirements | The older file calls itself a “Source of Truth” but has no status, owner, version or review metadata and overlaps product position, market lessons and design rules now governed elsewhere. | Product Constitution; Product Strategy; Product Requirements | Strategic | Mark it as supporting historical evidence and link to current governance after Product Owner confirmation. | Yes |
| GOV-005 | mission-control and event-first dashboard governance files, including `*-final.md` variants | Several documents claim canonical dashboard hierarchy. “Final” filenames have no status or approval metadata, and their hierarchy differs in places. | Product Constitution; approved Vendor Studio design authority | Design | Product Owner and Design Authority select the current record; merge later or mark the remainder historical. | Yes |
| GOV-006 | `docs/design/vendor-workspace-v2/20-launch-success-experience.md`; Component Catalogue | The Catalogue identifies Launch Success as in progress and names an approved alternative, while the design record's current approval/freeze status is not established in its opening metadata. | Component Catalogue; Product Constitution | Design | Confirm acceptance state and record it in the catalogue and design record through the existing freeze process. | Yes |
| GOV-007 | `docs/DEVELOPMENT_WORKFLOW.md`; `docs/operations/DEV_GIT_RULES.md` | One workflow requires feature worktrees; the other expressly prohibits creating or using worktrees. Both use authoritative language. | Product Constitution; Engineering Principles; repository contribution governance | Engineering | Product Owner and Technical Authority choose the current checkout model; supersede or amend the other document explicitly. | Yes |
| GOV-008 | root-level operational reports and matching `docs/archive/root-notes/` copies | Duplicate filenames exist in active-looking root and archive locations, so historical status is unclear. | Documentation Governance | Editorial | Compare provenance and mark the non-current copy historical; do not delete. | No, unless product meaning differs |
| GOV-009 | governance, design, architecture, launch and implementation records broadly | Many documents omit owner, version, review information or explicit status. Filenames such as “final”, “canonical” and “source of truth” can therefore overstate authority. | Product Constitution; Documentation Governance | Editorial | Populate metadata only when evidence exists; otherwise register `Unknown` and review progressively. | No for metadata discovery; Yes for approval claims |
| GOV-010 | Vendor Studio local roadmap; product roadmap | The local design roadmap includes phased application language that can read as delivery direction but is subordinate to the product roadmap and approved requirements. | Product Strategy; Product Requirements; Product Constitution | Strategic | Add a future approved cross-reference stating that local sequence does not authorise implementation. Do not edit the frozen pack during this baseline. | Yes |

## Product Owner resolutions

The Product Owner approved the recommended resolutions on 2026-07-26. [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md) is the decision record.

| Conflict | Resolution |
| --- | --- |
| GOV-001 | Canonical global hierarchy approved and recorded in Manifesto v1.1 and Constitution v1.1. |
| GOV-002 | PDS ADR clarified as a local constitution subordinate to repository-wide governance; design remains frozen. |
| GOV-003 | PDS build stack retained as local implementation sequence beneath the canonical global hierarchy. |
| GOV-004 | Product Reset Phase 1 classified as historical product evidence. |
| GOV-005 | PDS Dashboard Philosophy confirmed as current authority; competing root records classified historical. |
| GOV-006 | Launch Success Alternative A confirmed as approved direction; implementation completion and freeze remain unconfirmed. |
| GOV-007 | Feature worktrees approved; Development Workflow is current and the contrary rule is superseded. |
| GOV-008 | No bulk duplicate-file classification performed; provenance review remains future editorial work. |
| GOV-009 | Unknown metadata remains `Unknown` pending evidence. |
| GOV-010 | Local roadmaps remain subordinate and do not authorise implementation. |

## Missing governance information

- Owners, versions and review information are absent from several new permanent governance documents.
- Many older governance and launch records do not establish status or authority.
- The source titles supplied for the MEL style guide, MEL Plan, Visual Direction, Context Primer, Meta Prompt Generator, market-grounding sources and prompt guidance were not found.
- Multiple login-image files exist under runtime-managed files; a canonical brand-source asset is not confirmed.
- I cannot confirm that Launch Success implementation is complete or frozen.

## Documents needing Product Owner decisions

The governance conflicts required for delivery traceability have been resolved in PDR-001.

Remaining decisions:

1. Canonical locations for the supplied reference material and login brand asset.
2. Whether and when to perform the separate editorial provenance review for duplicated root and archive records.
3. Launch Success final freeze, only after implementation and acceptance evidence exists.

## Safe documentation changes made

- Created a repository documentation landing page.
- Created documentation governance rules.
- Created this audit and the document register.
- Created product decision and initiative templates.
- Created a root contribution guide that exposes, rather than resolves, the worktree conflict.
- Added minimal cross-links to the Product Constitution, Product Requirements and Engineering Principles.
- Preserved the seven constitutional documents together under `docs/governance/`.

## Work deliberately not performed

- No Drupal runtime, Commerce, theme, configuration or application code was changed.
- No roadmap initiative was started.
- No approved or frozen design decision was changed. Narrow, versioned PDS governance cross-references were added after Product Owner approval.
- No conflict was silently resolved.
- No source or binary asset was moved.
- No existing document was renamed, moved, superseded or marked historical in place.
- No file was deleted.
- No dependency was installed.
- No commit, push, pull, merge or branch switch was performed.

## Source material classification

- **Constitutional governance:** `docs/governance/00` and `01`.
- **Active product governance:** `docs/governance/02` through `06`.
- **Approved design authority:** Vendor Studio PDS, Workspace Zones, Visual Language B.5, Component Catalogue and accepted DDRs.
- **Research and market evidence:** Product Reset source and audit records; supplied market sources were not found.
- **Prompt and working guidance:** supplied prompt, plan and context titles were not found and therefore cannot be classified by repository path.
- **Brand assets:** runtime login-image copies were found; the MEL style guide was not found.
- **Historical or superseded material:** several candidates were identified, but status was not changed without approval.

## Recommended next phase

The governance decisions needed for the next phase are recorded. The recommended next phase is **Product Delivery Traceability**.

That phase should map each “Now” roadmap initiative to:

- Manifesto principle;
- strategic goal;
- product requirement;
- existing architecture;
- design authority; and
- acceptance evidence.

This audit does not begin that work.
