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
  weight: 130,
  icon: 'merchandise',
  operationalArea: 'commerce_product',
  deferred: TRUE,
)]
final class MerchandiseSection extends EventStudioSectionBase {}
