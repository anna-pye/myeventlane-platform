# Test Checklist — AI Wayfinding + Support Navigation (Sprint 1)

## Prerequisites
```bash
ddev drush cr
```

## 1. Vendor sidebar
- [ ] Log in as vendor (or admin viewing as vendor)
- [ ] Navigate to vendor console
- [ ] Verify **Support** item appears in Management section (sidebar)
- [ ] Click Support → goes to `/vendor/support`
- [ ] Verify Support highlights (is-active) when on `/vendor/support`
- [ ] Open an escalation `/vendor/support/{id}` → Support still highlights

## 2. Customer account menu
- [ ] Log in as customer
- [ ] Open account menu (e.g. My Account or user dropdown)
- [ ] Verify **Support** link appears
- [ ] Click Support → goes to `/my/support`

## 3. Customer support list CTAs
- [ ] Visit `/my/support` as customer
- [ ] Verify top help block with:
  - [ ] **Help Centre** link → `/help` (if myeventlane_help_centre enabled)
  - [ ] **Ask a question** link → `/help/ask` (if myeventlane_help_centre_ai enabled)
  - [ ] **Contact support** link → `/my/support/escalations/add`
- [ ] Verify BEM classes: mel-support-actions, mel-support-actions__item, mel-button

## 4. Vendor support list CTA
- [ ] Visit `/vendor/support` as vendor
- [ ] Verify helper text above table: "Browse the Help Centre for guides and policies"
- [ ] Link goes to `/vendor/help` (if myeventlane_help_centre enabled)
- [ ] No AI link on this page (AI requires escalation context)

## 5. Admin menu links
- [ ] Log in as admin
- [ ] Go to admin menu (Structure or Configuration)
- [ ] Verify **Escalations** link → `/admin/myeventlane/escalations`
- [ ] Under Escalations, verify:
  - [ ] **Escalation Analytics** → `/admin/myeventlane/escalations/analytics`
  - [ ] **Escalation Capacity** → `/admin/myeventlane/escalations/capacity`
  - [ ] **Escalation Policy Report** → `/admin/myeventlane/escalations/policy`
- [ ] All require appropriate permissions (view escalation analytics, etc.)
