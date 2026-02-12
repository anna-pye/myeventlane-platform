# Test Checklist — A+B+C Visible AI UX

## Prerequisites
- [ ] Enable all three modules: `myeventlane_vendor_ai`, `myeventlane_escalations_ai_draft`, `myeventlane_help_centre_ai`
- [ ] Set `myeventlane_ai.settings` enabled = 1 (Admin > Configuration > AI settings)
- [ ] Add API key in settings.php: `$settings['myeventlane_ai']['api_key'] = '...';`

## Module 1: Vendor AI (myeventlane_vendor_ai)
- [ ] Grant permission "use vendor ai assistant" to vendor role
- [ ] As vendor, visit `/vendor/support/{id}` (an escalation assigned to your vendor)
- [ ] Verify "Ask MEL Assistant" button/link is visible (replaces previous placeholder)
- [ ] Click link → goes to `/vendor/support/{id}/ai`
- [ ] Submit a question (≥10 chars) → see answer + "Relevant articles" list with links
- [ ] Verify no escalation thread text, notes, or customer PII in context
- [ ] Verify vendors without permission do not see the AI link

## Module 2: Staff AI Draft (myeventlane_escalations_ai_draft)
- [ ] Grant permission "generate escalation ai drafts" to staff
- [ ] As staff, visit `/admin/myeventlane/escalations/{id}` (admin escalation view)
- [ ] Verify "Draft response" button appears in `#mel-ai-draft-root`
- [ ] Click "Draft response" → modal shows draft
- [ ] Click "Insert into reply" → draft appears in reply textarea
- [ ] Verify reply textarea is found (comment form or EscalationReplyForm)
- [ ] Verify vendors/customers do NOT see the Draft response button
- [ ] Verify modal closes on Escape key

## Module 3: Help Centre AI (myeventlane_help_centre_ai)
- [ ] Visit `/help` (public help centre)
- [ ] Verify "Ask a question" link appears (when module enabled)
- [ ] Visit `/help/ask` (works for anonymous and authenticated)
- [ ] Submit question (≥10 chars) → see answer grounded in public articles only
- [ ] Verify "Relevant articles" list with links
- [ ] If excerpts insufficient, AI responds: "I can't confirm from our Help Centre yet..."
- [ ] Verify rate limiting by IP for anonymous (flood scope)

## Guardrails
- [ ] Vendor AI: never uses escalation thread; only help centre excerpts
- [ ] Customer AI: only public help articles; no personalised outcomes
- [ ] Staff draft AI: can see thread; emails/phones masked; notes NEVER included
