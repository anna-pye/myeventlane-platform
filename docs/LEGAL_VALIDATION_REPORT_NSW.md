# Legal Validation Report — NSW / Australia

**Date:** 2025-02-12  
**Scope:** Technical compliance design review of MyEventLane legal integration (Mode C).  
**Purpose:** Validate enforcement design and storage architecture against Australian federal and NSW state law.  
**Disclosure:** This is a technical compliance design review, not formal legal advice.

---

## 1. Customer Terms Acceptance

### Legal requirements

| Source | Requirement |
|--------|-------------|
| Electronic Transactions Act 2000 (NSW) s 9 | Electronic signature/consent valid if method identifies signatory and indicates consent |
| ETA (NSW) s 10 | Consent must be attributable to the person |
| Common law | Silent/browse-wrap acceptance insufficient; affirmative opt-in required |
| Evidentiary | Terms must be accessible prior to acceptance; version provable later |

### Implementation

| Element | Implemented? | Evidence |
|---------|--------------|----------|
| Affirmative opt-in | ✓ | Required checkbox; no pre-tick; form validation blocks submit |
| Terms accessible before acceptance | ✓ | Links to `customer_terms_url`, `privacy_url` in config; user must click to read |
| Version storage | ✓ | `field_customer_terms_version`, `field_privacy_version` on user, rsvp_submission, commerce_order |
| Timestamp storage | ✓ | `field_customer_terms_accepted_at`, `field_privacy_accepted_at` |
| IP/User-Agent logging | Optional | Config `store_vendor_ip_ua` (vendor only); default FALSE. Not required for ETA; optional for audit |

**Conclusion:** Implementation meets Electronic Transactions Act 2000 (NSW) and evidentiary standard for enforceability. Section number to be verified for s 9/s 10 applicability to consent forms.

---

## 2. Privacy Act Compliance (APPs)

### Legal requirements

| APP | Requirement |
|-----|-------------|
| APP 1 | Open and transparent management of personal information |
| APP 3 | Collection of solicited personal information only; consent where required |
| APP 5 | Notification of collection at or before collection |
| APP 6 | Use and disclosure consistent with notified purpose |
| APP 11 | Security of personal information |

### Implementation

| Element | Implemented? | Evidence |
|---------|--------------|----------|
| APP 1 | ✓ | Privacy Policy linked; configurable URL; policy content is policy-level |
| APP 3 | ✓ | PI collected for RSVP/order/account; solicited; consent at collection |
| APP 5 | ✓ | **Configurable collection notice** at RSVP (`collection_notice_rsvp`) and checkout (`collection_notice_checkout`). Displayed at point of capture. |
| APP 6 | ✓ | Use/disclosure described in Privacy Policy; consent for marketing separately captured |
| APP 11 | ⚠ | Access controls, Drupal RBAC, field-level storage. Encryption at rest/transit is infrastructure-level — recommend verification by ops |

### RSVP anonymous storage

| Question | Answer |
|----------|--------|
| Is personal information collected? | Yes — name, email, phone, guests |
| Is collection notice present at capture? | ✓ — `collection_notice_rsvp` displayed in legal fieldset |
| Consent vs notification? | Consent for terms/privacy; notification via collection notice. APP 5 requires notification; consent for contractual terms is separate. |

### Marketing opt-in (Spam Act 2003)

| Requirement | Implemented? |
|-------------|--------------|
| Separate from terms | ✓ |
| Voluntary | ✓ |
| Not bundled | ✓ |
| Not pre-ticked | ✓ |
| Stored with timestamp | ✓ — `field_marketing_opt_in`, `field_marketing_opt_in_at` |

---

## 3. Australian Consumer Law (ACL)

### Legal requirements

| Source | Requirement |
|-------|-------------|
| ACL s 64 | Terms cannot exclude consumer guarantees |
| ACL s 18 | No misleading or deceptive conduct |
| ACL s 29 | Refund policy must not misrepresent statutory rights |
| Fair Trading Act 1987 (NSW) | Aligned with ACL; state enforcement |

### Implementation

| Element | Implemented? | Evidence |
|---------|--------------|----------|
| Refund policy linked at checkout | ✓ | `refund_policy_url` in config; LegalConsentPane links Terms, Privacy, Refund Policy |
| Customer terms exclusion of guarantees | Policy-level | Customer terms content must not exclude s 54–59 guarantees. Code ensures link is present. |
| Vendor terms ACL acknowledgments | Policy-level | VendorTermsForm captures acceptance of Vendor Terms + Privacy. **Vendor Terms content** must include: responsibility for consumer guarantees, refund handling, accurate event representations. This is policy drafting, not code. |
| Agency relationship | Confirmed | MEL operates as **agent** for vendor ticket sales; vendors sell tickets, MEL provides the platform. MEL acts as **principal** for add-ons (e.g. Boosting events). Platform terms must reflect this. |

---

## 4. Cookie Consent & Tracking

### Legal requirements

| Source | Requirement |
|-------|-------------|
| Privacy Act 1988 | Tracking that collects identifiable info = personal information |
| OAIC guidance | Consent for non-essential cookies/tracking |
| Context | If no tracking, consent framework may be precautionary |

### Implementation

| Element | Implemented? | Evidence |
|---------|--------------|----------|
| Cookie banner | ✓ | `/cookies`, `/cookies/preferences`, `mel_consent` cookie |
| Analytics/marketing scripts | Planned | MEL will use analytics tracking. **MANDATORY:** Scripts must not load before consent; gate via `mel_consent`. |
| Third-party scripts | Stripe JS (payments), Google Fonts | Stripe necessary for payment; fonts may be arguable |
| Gating | ✓ | Framework in place; scripts MUST attach only when `mel_consent` allows. Non-essential tracking must not load before consent. |

**Conclusion:** Cookie consent framework is in place. When analytics are added, they **must not load before consent** (unless strictly necessary). Implementation requirement for future analytics.

---

## 5. Data Retention & Security

### Storage locations

| Data | Entity / Table | Notes |
|------|----------------|-------|
| RSVP | `rsvp_submission` | name, email, phone, guests, legal fields |
| Orders | `commerce_order` + field tables | Buyer details, legal consent, version, timestamp |
| Users | `user` + field tables | Registration legal fields |
| Vendors | `myeventlane_vendor` | Vendor terms acceptance, optional IP/UA |

### Access controls

| Area | Evidence |
|------|----------|
| Vendor isolation | Server-side ownership checks; no UI-only hiding |
| Admin access | Drupal RBAC; `administer myeventlane legal` |
| Field-level | Legal fields on entities; access via entity permissions |

### Sensitive fields (IP, UA)

| Field | Configurable? | Default |
|-------|---------------|---------|
| `field_vendor_terms_accepted_ip` | `store_vendor_ip_ua` | FALSE |
| `field_vendor_terms_accepted_ua` | `store_vendor_ip_ua` | FALSE |

**Conclusion:** IP/UA storage is justified for vendor terms audit trail when enabled; optional and documented.

### Retention policy

| Status | Note |
|--------|------|
| No automated deletion | Retention policy should be declared in Privacy Policy |
| Recommendation | Document retention periods; implement deletion if instructed |

---

## 6. Compliance Matrix

| Area | Law | Requirement | Implemented? | Notes |
|------|-----|-------------|--------------|-------|
| Customer terms | ETA 2000 (NSW) | Affirmative consent, attributable | ✓ | Checkbox + timestamp + version |
| Customer terms | ETA 2000 (NSW) | Terms accessible pre-acceptance | ✓ | Links before checkbox |
| Customer terms | Evidentiary | Version provable | ✓ | Stored on entity |
| Privacy | APP 5 | Notification at collection | ✓ | Collection notice RSVP + checkout |
| Privacy | APP 1, 6 | Policy linked, use disclosed | ✓ | Privacy Policy URL |
| Marketing | Spam Act 2003 | Separate, voluntary, unticked | ✓ | Marketing opt-in |
| ACL | s 64, 29 | Refund policy linked | ✓ | refund_policy_url |
| ACL | s 64 | No exclusion of guarantees | Policy | Terms content must comply |
| Vendor | ACL | Vendor acknowledgments | Policy | Vendor Terms content |
| Cookie | Privacy Act | Consent for tracking | ✓ | Framework; analytics to be gated when added |
| Security | APP 11 | Adequate protection | ⚠ | Infra/ops verification |
| Retention | APP 11 | Retention declared | Recommend | Document in policy |

---

## 7. Residual Legal Risk

1. **Privacy Act small business exemption** — Annual turnover under $3M: MEL may be exempt from Privacy Act (s 6D). OAIC recommends voluntary compliance as best practice. If turnover exceeds $3M, APPs apply. Architecture is compliant regardless.

2. **Analytics gating** — MEL will use analytics tracking. **MANDATORY:** When analytics scripts are added, they must NOT load before user consent (gate via `mel_consent` cookie). Failure to gate risks Privacy Act / OAIC breach.

3. **Vendor Terms content** — Code captures acceptance; content must include ACL acknowledgments (consumer guarantees, refund responsibility, accurate event info). Policy drafting responsibility.

4. **Event-level refund policy** — Events may have `field_refund_policy`. Checkout links central `refund_policy_url`. Consider event-specific link if events have differing policies.

5. **Dual payment model** — Vendor tickets: agent model, vendors must have Stripe or PayPal. Add-ons (e.g. Boosting): MEL as principal, processes directly. Ensure add-on terms clearly allocate liability.

---

## 8. Confirmed Business Parameters (as at 2025-02-12)

| # | Question | Answer |
|---|----------|--------|
| 1 | Is MEL operating as agent or principal? | **Agent** for vendor ticket sales; **principal** for add-ons (e.g. Boosting events). Vendors sell tickets; MEL provides the platform. |
| 2 | Expected annual turnover? | **Under $3M** — Privacy Act small business exemption may apply (s 6D). |
| 3 | Payment processing? | Vendor tickets: via vendor Stripe/PayPal (agent). Add-ons (Boosting): MEL processes directly (principal). Vendors must have Stripe or PayPal. |
| 4 | Will MEL use analytics tracking? | **Yes.** Analytics scripts **must** be gated by `mel_consent`; do not load before consent. |

---

## 9. Final Statement

**Based on architectural review and confirmed business parameters (agent/principal, turnover, payment flow, analytics), this implementation appears compliant with Australian federal law and NSW state law** as at current understanding, subject to:

- **Policy-level compliance:** Customer Terms, Vendor Terms, Privacy Policy, and Refund Policy **content** must not exclude consumer guarantees, misrepresent statutory rights, or omit required disclosures. Agency/principal split for tickets vs add-ons must be clearly stated.
- **Analytics implementation:** When analytics tracking is added, scripts **must not load before consent** (gate via `mel_consent` cookie). Non-compliance risks Privacy Act/OAIC breach.
- **Infrastructure:** APP 11 security (encryption, access controls) — recommend verification by ops.

**This is a technical compliance design review, not formal legal advice.** Legal content of policy documents should be reviewed by a qualified solicitor.

---

## 10. Implementation Requirement: Analytics Gating

When adding analytics (GA, Matomo, gtag, etc.):

1. **Do NOT** load analytics scripts on page load by default.
2. **Check** `mel_consent` cookie; load analytics only when user has consented to analytics/marketing category.
3. **Attach** via `myeventlane_legal` page attachments or equivalent — scripts loaded conditionally based on consent state.
4. **Document** in Privacy Policy that analytics are used and consent is obtained.

---

## 11. Implementation Adjustments Applied

| Adjustment | Purpose |
|------------|---------|
| `collection_notice_rsvp` | APP 5 notification at RSVP |
| `collection_notice_checkout` | APP 5 notification at checkout |
| `refund_policy_url` | ACL; link Refund Policy in checkout consent |
| LegalConsentPane | Display collection notice; link Refund Policy |
| RsvpLegalConsentHelper | Display collection notice |
| LegalSettingsForm | Admin UI for new config keys |
| myeventlane_legal_update_9002 | Add new config keys for existing installs |
