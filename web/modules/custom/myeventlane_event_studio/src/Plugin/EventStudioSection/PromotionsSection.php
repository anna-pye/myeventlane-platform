<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Promotions section metadata.
 */
#[EventStudioSection(
  id: 'promotions',
  title: 'Promotions',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace_promotions',
  weight: 40,
  icon: 'promotions',
  operationalArea: 'event',
)]
final class PromotionsSection extends EventStudioSectionBase {}
