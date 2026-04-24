<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * 301 redirects from legacy /vendor/event/{node}/... to /vendor/events/{node}/... .
 */
final class LegacyVendorEventAttendeePathController extends ControllerBase {

  public function redirectListToCanonical(NodeInterface $node): RedirectResponse {
    return $this->redirect('myeventlane_event_attendees.vendor_list', [
      'node' => $node->id(),
    ], [], 301);
  }

  public function redirectExportToCanonical(NodeInterface $node): RedirectResponse {
    return $this->redirect('myeventlane_event_attendees.vendor_export', [
      'node' => $node->id(),
    ], [], 301);
  }

}
