<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Home section metadata — organiser event home (machine id remains overview).
 */
#[EventStudioSection(
  id: 'overview',
  title: 'Home',
  group: 'Set up',
  routeName: 'myeventlane_event_studio.workspace',
  section_state: 'active',
  weight: 0,
  icon: 'overview',
  renderTarget: 'overview',
  writable: FALSE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 0,
  operationalArea: 'event',
)]
final class OverviewSection extends EventStudioSectionBase {}
