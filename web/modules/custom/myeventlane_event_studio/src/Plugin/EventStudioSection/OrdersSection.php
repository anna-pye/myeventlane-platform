<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Orders section metadata.
 */
#[EventStudioSection(
  id: 'orders',
  title: 'Orders',
  group: 'Operations',
  routeName: 'myeventlane_event_studio.workspace_orders',
  weight: 220,
  icon: 'orders',
  operationalArea: 'commerce',
  deferred: TRUE,
)]
final class OrdersSection extends EventStudioSectionBase {}
