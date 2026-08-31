<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Venue section — place, map, and access notes (shared information form).
 */
#[EventStudioSection(
  id: 'venue',
  title: 'Venue/Location',
  group: 'Set up',
  routeName: 'myeventlane_event_studio.workspace_venue',
  section_state: 'active',
  weight: 30,
  icon: 'venue',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventInformationForm',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 18,
  operationalArea: 'event',
)]
final class VenueSection extends EventStudioSectionBase {}
