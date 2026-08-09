<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects legacy vendor attendee URLs to canonical routes.
 */
final class LegacyVendorEventAttendeePathController extends ControllerBase {

  /**
   * Redirects the retired combined check-in surface to Door Mode.
   */
  public function redirectCheckinToDoorMode(NodeInterface $node): RedirectResponse {
    return $this->redirect('myeventlane_event_attendees.vendor_operations_door', [
      'node' => $node->id(),
    ], [], 301);
  }

  /**
   * Redirects the singular legacy attendee list URL.
   */
  public function redirectListToCanonical(NodeInterface $node): RedirectResponse {
    return $this->redirect('myeventlane_event_attendees.vendor_list', [
      'node' => $node->id(),
    ], [], 301);
  }

  /**
   * Redirects the singular legacy attendee export URL.
   */
  public function redirectExportToCanonical(NodeInterface $node): RedirectResponse {
    return $this->redirect('myeventlane_event_attendees.vendor_export', [
      'node' => $node->id(),
    ], [], 301);
  }

}
