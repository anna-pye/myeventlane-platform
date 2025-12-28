<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\node\NodeInterface;

/**
 * Boost management controller for vendor console.
 */
final class VendorBoostController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(DomainDetector $domain_detector, AccountProxyInterface $current_user, private readonly BoostStatusService $boostStatusService) {
    parent::__construct($domain_detector, $current_user);
  }

  /**
   * Displays Boost campaigns and controls.
   */
  public function boost(RouteMatchInterface $route_match): array {
    // Check for event route parameter (optional - this is a list page).
    $event = $route_match->getParameter('node');
    
    $campaigns = [];
    $events = [];
    
    // Only call getBoostStatuses if we have a valid event.
    if ($event && $event instanceof NodeInterface) {
      $event_nid = (int) $event->id();
      
      // Hard guard against invalid event ID.
      if ($event_nid <= 0) {
        return [
          '#markup' => 'Invalid event.',
        ];
      }
      
      $boostData = $this->boostStatusService->getBoostStatuses($event_nid);
      $campaigns = [$boostData];
    }
    
    // getBoostableEvents() doesn't exist yet - return empty array.
    // TODO: Implement getBoostableEvents() in BoostStatusService if needed.

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Boost',
      'body' => [
        '#theme' => 'myeventlane_vendor_boost',
        '#campaigns' => $campaigns,
        '#events' => $events,
      ],
    ]);
  }

}
