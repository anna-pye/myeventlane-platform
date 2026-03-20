1. Event Creation Workflow

Entry points
	•	web/modules/custom/myeventlane_event/myeventlane_event.routing.yml
	•	web/modules/custom/myeventlane_event/src/Controller/EventWizardCreateController.php
	•	web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml

Flow
	1.	Vendor accesses event creation route
	2.	EventWizardCreateController initializes event creation
	3.	Forms under myeventlane_event/src/Form collect data
	4.	Vendor context resolved via vendor module
	5.	Event entity/node is created or updated
	6.	Event state handled via myeventlane_event_state
	7.	Event saved and linked to vendor
	8.	Redirect to dashboard or next step

Modules
	•	myeventlane_event
	•	myeventlane_vendor
	•	myeventlane_event_state

⸻

2. Ticket Purchase Workflow

Entry points
	•	web/modules/custom/myeventlane_commerce/src/Controller/BookController.php
	•	Commerce routes
	•	MelEventCheckoutFlow.php

Flow
	1.	User views event
	2.	Ticket selection rendered
	3.	Add to cart via Commerce
	4.	Cart enhanced by myeventlane_cart
	5.	Checkout initiated
	6.	Custom flow: MelEventCheckoutFlow
	7.	Checkout panes render attendee inputs
	8.	Order processed
	9.	Ticket entities generated
	10.	Optional wallet pass generated

Modules
	•	myeventlane_commerce
	•	myeventlane_checkout_flow
	•	myeventlane_checkout_paragraph
	•	myeventlane_tickets
	•	myeventlane_cart
	•	myeventlane_wallet

⸻

3. RSVP Workflow

Entry points
	•	web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml
	•	web/modules/custom/myeventlane_rsvp/src/Form
	•	RsvpRedirectController.php

Flow
	1.	User clicks RSVP
	2.	RSVP form rendered
	3.	User submits form
	4.	Data processed and stored
	5.	Redirect handled by controller
	6.	Confirmation displayed
	7.	Optional email or calendar output

Modules
	•	myeventlane_rsvp
	•	myeventlane_messaging
	•	myeventlane_donations

⸻

4. Vendor Dashboard Workflow

Entry points
	•	Vendor dashboard routes
	•	Controllers in vendor/dashboard modules

Flow
	1.	Vendor logs in
	2.	Vendor context resolved
	3.	Dashboard loads vendor events
	4.	Metrics aggregated via service
	5.	Events displayed with stats
	6.	Actions available (edit, view, export)
	7.	Analytics rendered

Modules
	•	myeventlane_vendor
	•	myeventlane_dashboard
	•	myeventlane_vendor_analytics
	•	myeventlane_metrics

⸻

5. Attendee Capture Workflow

Entry points
	•	Checkout panes
	•	TicketHolderParagraphPane.php

Flow
	1.	User enters checkout
	2.	Ticket quantities selected
	3.	Attendee forms rendered per ticket
	4.	Extra questions loaded from paragraphs
	5.	User submits details
	6.	Data saved to paragraph entities
	7.	Linked to order and tickets
	8.	Available for dashboard and export

Modules
	•	myeventlane_checkout_paragraph
	•	myeventlane_questions
	•	myeventlane_attendee
	•	myeventlane_tickets

⸻

6. Event Check-in Workflow

Entry points
	•	Check-in interface
	•	QR scanning logic

Flow
	1.	QR code scanned
	2.	Payload decoded
	3.	Ticket validated
	4.	Order status checked
	5.	Check-in recorded
	6.	Ticket marked as used

Modules
	•	myeventlane_checkin
	•	myeventlane_tickets

⸻

7. Escalations Workflow

Entry points
	•	Escalation entity
	•	Portal controllers

Flow
	1.	Escalation created
	2.	Entity stored
	3.	SLA and policy applied
	4.	Portal displays thread
	5.	Staff interact
	6.	AI suggestions optionally generated
	7.	Resolution recorded
	8.	Analytics updated

Modules
	•	myeventlane_escalations
	•	myeventlane_escalations_portal
	•	myeventlane_escalations_sla
	•	myeventlane_escalations_ai

⸻

8. Help Centre + AI Workflow

Entry points
	•	Help centre routes
	•	AI query form

Flow
	1.	User opens help centre
	2.	Articles rendered
	3.	User submits query
	4.	Query processed via form
	5.	Content retrieved
	6.	AI generates response
	7.	Response returned

Modules
	•	myeventlane_help_centre
	•	myeventlane_help_assistant
	•	myeventlane_help_centre_ai

⸻

9. Domain Events Workflow

Entry points
	•	Domain event store
	•	Queue workers

Flow
	1.	Domain event triggered
	2.	Event stored
	3.	Queue worker processes event
	4.	Projection updates read model
	5.	Admin can inspect projections

Modules
	•	myeventlane_domain_events

⸻

10. Messaging Workflow

Entry points
	•	Messaging services
	•	Automation triggers

Flow
	1.	System event occurs
	2.	Messaging triggered
	3.	Template selected
	4.	Notification sent
	5.	Optional follow-up scheduled

Modules
	•	myeventlane_messaging
	•	myeventlane_automation

⸻

Done.