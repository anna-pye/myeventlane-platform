<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Ticketing section metadata.
 */
#[EventStudioSection(
  id: 'tickets',
  title: 'Tickets',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_tickets',
  weight: 100,
  icon: 'tickets',
  readinessParticipant: TRUE,
  operationalArea: 'ticket',
)]
final class TicketsSection extends EventStudioSectionBase {}
