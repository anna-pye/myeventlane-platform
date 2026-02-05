<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Service to build navigation structure for vendor event management.
 */
final class ManageEventNavigation {

  /**
   * Gets all navigation steps for the manage event flow.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $current_step
   *   The current step route name (e.g., 'myeventlane_vendor.manage_event.edit').
   *
   * @return array
   *   Array of step definitions with 'route', 'label', 'active', 'url'.
   */
  public function getSteps(NodeInterface $event, string $current_step = ''): array {
    $event_id = $event->id();
    $steps = [];

    $step_definitions = [
      'edit' => [
        'route' => 'myeventlane_vendor.manage_event.edit',
        'label' => 'Event information',
        'icon' => 'info',
      ],
      'design' => [
        'route' => 'myeventlane_vendor.manage_event.design',
        'label' => 'Page design',
        'icon' => 'palette',
      ],
      'content' => [
        'route' => 'myeventlane_vendor.manage_event.content',
        'label' => 'Page content',
        'icon' => 'text',
      ],
      'tickets' => [
        'route' => 'myeventlane_vendor.manage_event.tickets',
        'label' => 'Ticket types',
        'icon' => 'ticket',
      ],
      'checkout_questions' => [
        'route' => 'myeventlane_vendor.manage_event.checkout_questions',
        'label' => 'Checkout questions',
        'icon' => 'question',
      ],
      'series' => [
        'route' => 'myeventlane_vendor.manage_event.series',
        'label' => 'Series',
        'icon' => 'calendar',
        'series_only' => TRUE,
      ],
      'promote' => [
        'route' => 'myeventlane_vendor.manage_event.promote',
        'label' => 'Promote',
        'icon' => 'megaphone',
        'coming_soon' => TRUE,
      ],
      'payments' => [
        'route' => 'myeventlane_vendor.manage_event.payments',
        'label' => 'Payments & fees',
        'icon' => 'payment',
        'coming_soon' => TRUE,
      ],
      'comms' => [
        'route' => 'myeventlane_vendor.manage_event.comms',
        'label' => 'Comms',
        'icon' => 'email',
        'coming_soon' => TRUE,
      ],
      'advanced' => [
        'route' => 'myeventlane_vendor.manage_event.advanced',
        'label' => 'Advanced',
        'icon' => 'settings',
        'coming_soon' => TRUE,
      ],
    ];

    foreach ($step_definitions as $key => $def) {
      // Skip series step unless event is a series template.
      if (!empty($def['series_only']) && !$this->isSeriesTemplate($event)) {
        continue;
      }

      $is_active = ($def['route'] === $current_step);
      $url = NULL;

      try {
        $url = Url::fromRoute($def['route'], ['event' => $event_id]);
      }
      catch (\Exception) {
        // Route may not exist yet.
      }

      $steps[$key] = [
        'route' => $def['route'],
        'label' => $def['label'],
        'icon' => $def['icon'] ?? 'circle',
        'active' => $is_active,
        'url' => $url,
        'coming_soon' => $def['coming_soon'] ?? FALSE,
      ];
    }

    return $steps;
  }

  /**
   * Gets the next step route after the current one.
   *
   * Skips steps marked as "coming_soon" and finds the next available step.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $current_step
   *   The current step route name.
   *
   * @return \Drupal\Core\Url|null
   *   URL to next step, or NULL if none.
   */
  public function getNextStep(NodeInterface $event, string $current_step): ?Url {
    $steps = $this->getSteps($event, $current_step);
    $step_keys = array_keys($steps);
    $route_column = array_column($steps, 'route');
    $current_index = array_search($current_step, $route_column, TRUE);

    if ($current_index === FALSE) {
      return NULL;
    }

    // Ensure $current_index is an integer for proper loop arithmetic.
    $current_index = (int) $current_index;

    // Loop through subsequent steps to find the next available one.
    for ($i = $current_index + 1; $i < count($step_keys); $i++) {
      if (!isset($step_keys[$i])) {
        break;
      }

      $next_key = $step_keys[$i];
      $next_step = $steps[$next_key];

      // Return the first step that has a URL and is not coming soon.
      if ($next_step['url'] && !$next_step['coming_soon']) {
        return $next_step['url'];
      }
    }

    return NULL;
  }

  /**
   * Gets the previous step route before the current one.
   *
   * Skips steps marked as "coming_soon" and finds the previous available step.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $current_step
   *   The current step route name.
   *
   * @return \Drupal\Core\Url|null
   *   URL to previous step, or NULL if none.
   */
  public function getPreviousStep(NodeInterface $event, string $current_step): ?Url {
    $steps = $this->getSteps($event, $current_step);
    $step_keys = array_keys($steps);
    $route_column = array_column($steps, 'route');
    $current_index = array_search($current_step, $route_column, TRUE);

    if ($current_index === FALSE) {
      return NULL;
    }

    // Ensure $current_index is an integer for proper loop arithmetic.
    $current_index = (int) $current_index;

    if ($current_index === 0) {
      return NULL;
    }

    // Loop backwards through previous steps to find the previous available one.
    for ($i = $current_index - 1; $i >= 0; $i--) {
      if (!isset($step_keys[$i])) {
        break;
      }

      $prev_key = $step_keys[$i];
      $prev_step = $steps[$prev_key];

      // Return the first step that has a URL and is not coming soon.
      if ($prev_step['url'] && !$prev_step['coming_soon']) {
        return $prev_step['url'];
      }
    }

    return NULL;
  }

  /**
   * Checks if an event is a series template.
   */
  private function isSeriesTemplate(NodeInterface $event): bool {
    if (!$event->hasField('field_is_series_template') || $event->get('field_is_series_template')->isEmpty()) {
      return FALSE;
    }
    return (bool) $event->get('field_is_series_template')->value;
  }

}
