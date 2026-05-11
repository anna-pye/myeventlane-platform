<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Merchandise section metadata.
 */
#[EventStudioSection(
  id: 'merchandise',
  title: 'Merchandise',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_merchandise',
  section_state: 'deferred',
  weight: 130,
  icon: 'merchandise',
  renderTarget: 'deferred_empty',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'deferred',
  mobile_priority: 160,
  operationalArea: 'commerce_product',
)]
final class MerchandiseSection extends EventStudioSectionBase {}
