<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Tickets section metadata.
 */
#[EventStudioSection(
  id: 'tickets',
  title: 'Ticketing',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_tickets',
  section_state: 'active',
  weight: 50,
  icon: 'tickets',
  renderTarget: 'tickets_stack',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 40,
  operationalArea: 'ticket',
)]
final class TicketsSection extends EventStudioSectionBase {}
