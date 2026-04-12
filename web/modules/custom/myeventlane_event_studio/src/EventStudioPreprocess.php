<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Theme preprocess helpers for Event Studio templates.
 */
final class EventStudioPreprocess {

  /**
   * Adds contextual action URLs for the mel_event_studio theme hook.
   *
   * @param array<string, mixed> $variables
   *   Theme variables for mel_event_studio; expects element['#mel_studio_node'].
   */
  public function preprocess(array &$variables): void {
    $element = $variables['element'] ?? [];
    $node = $element['#mel_studio_node'] ?? NULL;

    $actions = [
      'view' => NULL,
      'booking' => NULL,
      'scan' => NULL,
    ];

    if ($node instanceof NodeInterface && !$node->isNew()) {
      $nid = (int) $node->id();

      $view = $node->toUrl();
      if ($view->access()) {
        $actions['view'] = $view->toString();
      }

      $event_type = '';
      if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
        $event_type = (string) $node->get('field_event_type')->value;
      }
      if ($event_type !== 'external') {
        $booking = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $nid]);
        if ($booking->access()) {
          $actions['booking'] = $booking->toString();
        }
      }

      $scan = Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $nid]);
      if ($scan->access()) {
        $actions['scan'] = $scan->toString();
      }
    }

    $variables['mel_event_actions'] = $actions;
  }

}
