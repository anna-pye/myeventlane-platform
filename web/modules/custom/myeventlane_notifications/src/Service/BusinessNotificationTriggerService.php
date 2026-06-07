<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Business and personal lifecycle notifications (sales, RSVPs, boosts, followers).
 */
final class BusinessNotificationTriggerService {

  public function __construct(
    private readonly NotificationManager $notificationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AudienceResolver $audienceResolver,
    private readonly LoggerChannelInterface $logger,
    private readonly TranslationInterface $translation,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
    private readonly ?OperationalVariationStockResolver $stockResolver = NULL,
  ) {}

  /**
   * After a paid order, notify vendors when capacity is reached or stock is low.
   */
  public function onOrderPaidCapacitySignals(OrderInterface $order): void {
    if ($this->capacityService === NULL) {
      return;
    }

    $eventIds = $this->eventIdsFromOrder($order);
    $nearlySoldOutPercent = (int) ($this->configFactory->get('myeventlane_automation.settings')
      ->get('thresholds.nearly_sold_out_percent') ?? 85);
    if ($nearlySoldOutPercent < 1 || $nearlySoldOutPercent > 99) {
      $nearlySoldOutPercent = 85;
    }

    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }
      $organiserUid = $this->audienceResolver->resolveEventOrganiserUid($event);
      if ($organiserUid === NULL) {
        continue;
      }

      if ($this->capacityService->isSoldOut($event)) {
        $this->queueNotification(
          [$organiserUid],
          NotificationContext::BUSINESS,
          NotificationDomain::SALES,
          MelNotification::PRIORITY_HIGH,
          75,
          (string) $this->translation->translate('Event at capacity'),
          (string) $this->translation->translate('@event has reached capacity.', ['@event' => $event->label()]),
          'capacity_reached',
          'capacity_reached:' . $eventId,
          (string) $this->translation->translate('Manage attendees'),
          'myeventlane_event_attendees.vendor_list',
          ['node' => $eventId],
        );
        continue;
      }

      $total = $this->capacityService->getCapacityTotal($event);
      if ($total !== NULL && $total > 0) {
        $sold = $this->capacityService->getSoldCount($event);
        $percentSold = (int) round(($sold / $total) * 100);
        if ($percentSold >= $nearlySoldOutPercent) {
          $remaining = max(0, $total - $sold);
          $this->queueNotification(
            [$organiserUid],
            NotificationContext::BUSINESS,
            NotificationDomain::SALES,
            MelNotification::PRIORITY_HIGH,
            65,
            (string) $this->translation->translate('Low ticket stock'),
            (string) $this->translation->translate('@event has @count ticket(s) remaining.', [
              '@event' => $event->label(),
              '@count' => (string) $remaining,
            ]),
            'low_stock',
            'low_stock:' . $eventId . ':' . $remaining,
            (string) $this->translation->translate('Manage attendees'),
            'myeventlane_event_attendees.vendor_list',
            ['node' => $eventId],
          );
        }
      }

      $this->checkOperationalProductLowStock($event, $organiserUid);
    }
  }

  public function onBoostPurchased(NodeInterface $event, int $vendorUid): void {
    if ($vendorUid < 1) {
      return;
    }
    $this->queueNotification(
      [$vendorUid],
      NotificationContext::BUSINESS,
      NotificationDomain::BOOSTS,
      MelNotification::PRIORITY_NORMAL,
      45,
      (string) $this->translation->translate('Boost purchased'),
      (string) $this->translation->translate('Your boost for @event is active.', ['@event' => $event->label()]),
      'boost_purchased',
      'boost_purchased:' . $event->id(),
      (string) $this->translation->translate('View boost'),
      'myeventlane_boost.vendor_event_boost',
      ['event' => (int) $event->id()],
    );
  }

  public function onBoostExpiring(NodeInterface $event, int $vendorUid): void {
    if ($vendorUid < 1) {
      return;
    }
    $this->queueNotification(
      [$vendorUid],
      NotificationContext::BUSINESS,
      NotificationDomain::BOOSTS,
      MelNotification::PRIORITY_HIGH,
      60,
      (string) $this->translation->translate('Boost expiring soon'),
      (string) $this->translation->translate('The boost for @event expires in about 24 hours.', ['@event' => $event->label()]),
      'boost_expiring',
      'boost_expiring:' . $event->id(),
      (string) $this->translation->translate('Extend boost'),
      'myeventlane_boost.vendor_event_boost',
      ['event' => (int) $event->id()],
    );
  }

  public function onBoostCompleted(NodeInterface $event, int $vendorUid): void {
    if ($vendorUid < 1) {
      return;
    }
    $this->queueNotification(
      [$vendorUid],
      NotificationContext::BUSINESS,
      NotificationDomain::BOOSTS,
      MelNotification::PRIORITY_NORMAL,
      40,
      (string) $this->translation->translate('Boost completed'),
      (string) $this->translation->translate('The boost period for @event has ended.', ['@event' => $event->label()]),
      'boost_completed',
      'boost_completed:' . $event->id(),
      (string) $this->translation->translate('Boost again'),
      'myeventlane_boost.vendor_event_boost',
      ['event' => (int) $event->id()],
    );
  }

  public function onOrganiserFollowed(Vendor $vendor, UserInterface $follower): void {
    $vendorUid = (int) $vendor->getOwnerId();
    if ($vendorUid < 1 || (int) $follower->id() === $vendorUid) {
      return;
    }
    $this->queueNotification(
      [$vendorUid],
      NotificationContext::BUSINESS,
      NotificationDomain::FOLLOWERS,
      MelNotification::PRIORITY_LOW,
      25,
      (string) $this->translation->translate('New follower'),
      (string) $this->translation->translate('@name is now following @vendor.', [
        '@name' => $follower->getDisplayName(),
        '@vendor' => $vendor->label(),
      ]),
      'follower_gained',
      'follower_gained:' . $vendor->id() . ':' . $follower->id(),
      (string) $this->translation->translate('View profile'),
      'entity.myeventlane_vendor.canonical',
      ['myeventlane_vendor' => (int) $vendor->id()],
    );
  }

  /**
   * Notifies vendors with published events in a category when it gains a follower.
   */
  public function onCategoryFollowed(int $termId, UserInterface $follower): int {
    if ($termId < 1 || (int) $follower->id() < 1) {
      return 0;
    }

    $eventIds = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_category', $termId)
      ->execute();
    if ($eventIds === []) {
      return 0;
    }

    /** @var \Drupal\node\NodeInterface[] $events */
    $events = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($termId);
    $termLabel = $term ? $term->label() : (string) $this->translation->translate('a category');

    $notified = 0;
    $seenVendors = [];
    foreach ($events as $event) {
      $vendorUid = $this->audienceResolver->resolveEventOrganiserUid($event);
      if ($vendorUid === NULL || isset($seenVendors[$vendorUid]) || $vendorUid === (int) $follower->id()) {
        continue;
      }
      $seenVendors[$vendorUid] = TRUE;
      $this->queueNotification(
        [$vendorUid],
        NotificationContext::BUSINESS,
        NotificationDomain::FOLLOWERS,
        MelNotification::PRIORITY_LOW,
        22,
        (string) $this->translation->translate('New category follower'),
        (string) $this->translation->translate('@name followed @category, which includes your event @event.', [
          '@name' => $follower->getDisplayName(),
          '@category' => $termLabel,
          '@event' => $event->label(),
        ]),
        'category_follower_gained',
        'category_follower:' . $termId . ':' . $vendorUid . ':' . $follower->id(),
        (string) $this->translation->translate('View event'),
        'entity.node.canonical',
        ['node' => (int) $event->id()],
      );
      $notified++;
    }
    return $notified;
  }

  public function onRsvpCancelled(EntityInterface $attendee, NodeInterface $event): void {
    $organiserUid = $this->audienceResolver->resolveEventOrganiserUid($event);
    if ($organiserUid === NULL) {
      return;
    }
    $this->queueNotification(
      [$organiserUid],
      NotificationContext::BUSINESS,
      NotificationDomain::RSVPS,
      MelNotification::PRIORITY_NORMAL,
      35,
      (string) $this->translation->translate('RSVP cancelled'),
      (string) $this->translation->translate('An attendee cancelled their RSVP for @event.', ['@event' => $event->label()]),
      'rsvp_cancelled',
      'rsvp_cancelled:' . $attendee->id(),
      (string) $this->translation->translate('View attendees'),
      'myeventlane_event_attendees.vendor_list',
      ['node' => (int) $event->id()],
    );
  }

  public function onWaitlistPromotedVendor(NodeInterface $event, int $attendeeId): void {
    $organiserUid = $this->audienceResolver->resolveEventOrganiserUid($event);
    if ($organiserUid === NULL) {
      return;
    }
    $this->queueNotification(
      [$organiserUid],
      NotificationContext::BUSINESS,
      NotificationDomain::RSVPS,
      MelNotification::PRIORITY_NORMAL,
      38,
      (string) $this->translation->translate('Waitlist promoted'),
      (string) $this->translation->translate('A waitlisted guest was promoted for @event.', ['@event' => $event->label()]),
      'waitlist_promoted_vendor',
      'waitlist_promoted_vendor:' . $attendeeId,
      (string) $this->translation->translate('View attendees'),
      'myeventlane_event_attendees.vendor_list',
      ['node' => (int) $event->id()],
    );
  }

  public function onWaitlistPromotedAttendee(int $attendeeUid, NodeInterface $event): void {
    if ($attendeeUid < 1) {
      return;
    }
    $this->queueNotification(
      [$attendeeUid],
      NotificationContext::PERSONAL,
      NotificationDomain::RSVPS,
      MelNotification::PRIORITY_NORMAL,
      50,
      (string) $this->translation->translate('You are off the waitlist'),
      (string) $this->translation->translate('You have been confirmed for @event.', ['@event' => $event->label()]),
      'waitlist_promoted_attendee',
      'waitlist_promoted_attendee:' . $event->id() . ':' . $attendeeUid,
      (string) $this->translation->translate('View event'),
      'entity.node.canonical',
      ['node' => (int) $event->id()],
    );
  }

  private function checkOperationalProductLowStock(NodeInterface $event, int $organiserUid): void {
    if ($this->stockResolver === NULL || !$event->hasField('field_event_product')) {
      return;
    }
    if ($event->get('field_event_product')->isEmpty()) {
      return;
    }
    $product = $event->get('field_event_product')->entity;
    if (!$product instanceof ProductInterface) {
      return;
    }
    $summary = $this->stockResolver->summarizeProductStock($product);
    if (empty($summary['low_stock'])) {
      return;
    }
    $this->queueNotification(
      [$organiserUid],
      NotificationContext::BUSINESS,
      NotificationDomain::SALES,
      MelNotification::PRIORITY_HIGH,
      62,
      (string) $this->translation->translate('Low add-on stock'),
      (string) $this->translation->translate('Add-on stock is running low for @event.', ['@event' => $event->label()]),
      'low_stock',
      'low_stock_addon:' . $event->id(),
      (string) $this->translation->translate('Manage extras'),
      'myeventlane_event_studio.workspace_extras',
      ['node' => (int) $event->id()],
    );
  }

  /**
   * @return list<int>
   */
  private function eventIdsFromOrder(OrderInterface $order): array {
    $eventIds = [];
    foreach ($order->getItems() as $item) {
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $eventIds[] = (int) $item->get('field_target_event')->target_id;
      }
    }
    return array_values(array_unique(array_filter($eventIds)));
  }

  /**
   * @param list<int> $userIds
   * @param array<string, scalar> $routeParameters
   */
  private function queueNotification(
    array $userIds,
    string $context,
    string $domain,
    string $priority,
    int $priorityScore,
    string $title,
    string $message,
    string $actionContext,
    string $suppressionKey,
    string $actionLabel,
    string $routeName,
    array $routeParameters,
  ): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($userIds === []) {
      return;
    }
    try {
      $notification = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_SYSTEM,
        'title' => $title,
        'message' => $message,
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => $userIds],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => $priority,
        'priority_score' => $priorityScore,
        'suppression_key' => $suppressionKey,
        'context' => $context,
        'domain' => $domain,
        'action_label' => $actionLabel,
        'route_name' => $routeName,
        'route_parameters' => $routeParameters,
        'action_context' => $actionContext,
      ]);
      $this->notificationManager->queueNotification($notification);
    }
    catch (\RuntimeException) {
      // Suppressed duplicate.
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue business notification (@ctx): @message', [
        '@ctx' => $actionContext,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
