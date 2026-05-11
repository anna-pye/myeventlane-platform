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
  section_state: 'active',
  weight: 40,
  icon: 'promotions',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventPromotionForm',
  writable: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 40,
  operationalArea: 'event',
)]
final class PromotionsSection extends EventStudioSectionBase {}
