<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_boost\Service\BoostHelpContent;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Boost management controller for vendor console.
 */
final class VendorBoostController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BoostManager $boostManager,
    private readonly LoggerInterface $logger,
    private readonly BoostHelpContent $boostHelpContent,
    private readonly EventStateResolverInterface $eventStateResolver,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays Boost campaigns and controls.
   */
  public function boost(RouteMatchInterface $route_match): array {
    // Get store for current vendor/user.
    $store = $this->getCurrentUserStore();
    if (!$store) {
      // No store found - show empty state with helpful message.
      return $this->buildVendorPage('myeventlane_vendor_console_page', [
        'title' => 'Boost',
        'body' => [
          '#theme' => 'myeventlane_vendor_boost',
          '#campaigns' => [],
          '#events' => [],
          '#no_store' => TRUE,
        ],
      ]);
    }

    // Get all events for this store using canonical API.
    $storeEvents = $this->boostManager->getEventsForStore($store, [
      'published_only' => TRUE,
      'access_check' => TRUE,
      'limit' => 100,
    ]);

    // Build campaigns and events by checking boost status on each event individually.
    // This ensures we catch boosted events even if the store query misses them
    // (e.g., if field_event_store is not set but event belongs to vendor).
    $campaigns = [];
    $events = [];

    foreach ($storeEvents as $eventNode) {
      // Use canonical API to check boost status for each event.
      $boostStatus = $this->boostManager->getBoostStatusForEvent($eventNode);
      $isBoosted = $boostStatus['active'];
      $eventState = $this->eventStateResolver->resolveState($eventNode);

      // Add to campaigns if actively boosted.
      if ($isBoosted) {
        $campaigns[] = [
          'event_title' => $eventNode->label(),
          'label' => $eventNode->label(),
          'status' => 'Active',
          'ends' => $boostStatus['end_timestamp'] ? date('M j, Y g:ia', $boostStatus['end_timestamp']) : NULL,
          'boost_url' => Url::fromRoute('myeventlane_boost.boost_page', ['node' => $eventNode->id()])->toString(),
        ];
      }

      // Add to events list.
      $events[] = [
        'id' => $eventNode->id(),
        'title' => $eventNode->label(),
        'is_boosted' => $isBoosted,
        'mel_event_state' => $eventState,
        'boost_url' => Url::fromRoute('myeventlane_boost.boost_page', ['node' => $eventNode->id()])->toString(),
      ];
    }

    // Debug logging for troubleshooting.
    if (empty($events)) {
      $this->logger->debug('VendorBoostController: No events found for store', [
        'store_id' => $store->id(),
        'vendor_uid' => $this->currentUser->id(),
        'total_events_in_store' => count($storeEvents),
      ]);
    }
    elseif (empty($campaigns)) {
      // Check if any events have field_promoted set but are expired/not active.
      $promotedCount = 0;
      $expiredCount = 0;
      $activeButNotInQuery = 0;
      foreach ($storeEvents as $eventNode) {
        if ($eventNode->hasField('field_promoted') && (bool) $eventNode->get('field_promoted')->value) {
          $promotedCount++;
          $boostStatus = $this->boostManager->getBoostStatusForEvent($eventNode);
          if ($boostStatus['expired']) {
            $expiredCount++;
          }
          elseif ($boostStatus['active']) {
            $activeButNotInQuery++;
          }
        }
      }

      $this->logger->debug('VendorBoostController: Events found but no active boosts in campaigns', [
        'store_id' => $store->id(),
        'vendor_uid' => $this->currentUser->id(),
        'total_events' => count($storeEvents),
        'promoted_count' => $promotedCount,
        'expired_count' => $expiredCount,
        'active_but_missing' => $activeButNotInQuery,
      ]);
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Boost',
      'body' => [
        '#theme' => 'myeventlane_vendor_boost',
        '#campaigns' => $campaigns,
        '#events' => $events,
        '#no_store' => FALSE,
        '#boost_faq' => $this->boostHelpContent->getFaqContent(),
      ],
    ]);
  }

  /**
   * Gets the store for the current user.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or NULL if not found.
   */
  private function getCurrentUserStore(): ?StoreInterface {
    $userId = (int) $this->currentUser->id();
    if ($userId === 0) {
      return NULL;
    }

    // Try vendor → store relationship first.
    $vendor = $this->getCurrentVendorOrNull();
    if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    // Fallback: find store by user ID (store owner).
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (!empty($storeIds)) {
      $store = $storeStorage->load(reset($storeIds));
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    return NULL;
  }

}
