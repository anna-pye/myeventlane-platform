# MyEventLane Documentation

This directory is the governed record of MyEventLane product intent, design authority, technical decisions, operations and delivery evidence.

The [MyEventLane Organiser Manifesto](governance/00-organiser-manifesto.md) is the highest product authority. Every other document is subordinate to it.

## Constitutional hierarchy

The confirmed global hierarchy begins:

1. [Organiser Manifesto](governance/00-organiser-manifesto.md)
2. [Product Constitution](governance/01-product-constitution.md)
3. [Product Strategy](governance/02-product-strategy.md) and [Product Requirements](governance/04-product-requirements.md)
4. [Vendor Studio Product Design System](design/vendor-studio/README.md), where applicable
5. [Workspace Zones](design/vendor-studio-visual/07-workspace-zones.md)
6. [Visual Language B.5](design/vendor-studio-visual/03-option-b5.md)
7. [Component Catalogue](design/vendor-workspace-v2/23-vendor-component-catalogue.md)
8. Approved design decisions and assurance records
9. Implementation

The Product Owner confirmed this canonical order in [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md). Local design hierarchies remain valid within their scope but are subordinate to this repository-wide chain.

The Product Roadmap is directional. Roadmap position does not authorise implementation.

## Document categories

| Category | Purpose | Primary location |
| --- | --- | --- |
| Constitutional governance | Purpose, authority and enduring product obligations | [`governance/`](governance/) |
| Active product governance | Strategy, roadmap, requirements and product decisions | [`governance/`](governance/) and future product records |
| Approved design authority | Frozen principles, zones, visual language, components and design decisions | [`design/`](design/) |
| Architecture | Technical decisions, boundaries and confirmed system design | [`architecture/`](architecture/) and [`adr/`](adr/) |
| Operations | Support, incidents, releases, environments and runbooks | [`operations/`](operations/), [`release/`](release/) and [`launch/`](launch/) |
| Implementation records | Evidence of delivered or investigated implementation | [`implementation/`](implementation/), [`audits/`](audits/) and scoped reports |
| Research and evidence | Discovery, market research and product audits | Purpose-specific research or audit locations |
| Historical records | Superseded material retained for traceability | [`archive/`](archive/) |

Directory location alone does not establish authority. Use the [document register](document-register.md) and the document's own metadata.

## Document states

- **Approved** - explicitly accepted by the named authority. It remains changeable through governance.
- **Frozen** - approved and protected from ordinary change. Unfreezing requires the stated authority and recorded reason.
- **Draft** - proposed and not authoritative.
- **Superseded** - replaced by a named document. Retained for traceability and not used for current decisions.
- **Historical** - evidence of past work or conditions; not current authority.
- **Unknown** - the repository does not establish the state. Unknown must not be treated as approved.

Every new permanent governance document should state its status, owner, version, governing parent and review information. Use `Unknown` rather than inventing metadata.

## How to navigate

1. Begin with the [Organiser Manifesto](governance/00-organiser-manifesto.md).
2. Read the [Product Constitution](governance/01-product-constitution.md) and the relevant product governance document.
3. Consult the [document register](document-register.md) to identify the current design or technical authority.
4. Read relevant approved decisions and assurance records.
5. Inspect implementation records and repository behaviour as evidence, not product authority.
6. If sources conflict, follow [GOVERNANCE.md](GOVERNANCE.md) and do not resolve the conflict in code.

## Governance and maintenance

- [Repository governance](GOVERNANCE.md)
- [Document register](document-register.md)
- [Governance baseline audit](governance-audit.md)
- [Product decision template](templates/product-decision-record.md)
- [Initiative brief template](templates/initiative-brief.md)
- [Repository contribution guide](../CONTRIBUTING.md)

No document may gain authority from a filename containing words such as “final”, “canonical”, “governance” or “source of truth” without supporting status and approval evidence.
