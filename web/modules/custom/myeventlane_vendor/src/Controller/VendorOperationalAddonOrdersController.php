<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;

/**
 * Vendor console: operational add-on orders for an event (read-only).
 */
final class VendorOperationalAddonOrdersController extends VendorConsoleBaseController {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorOperationalAddonOrderBuilder $vendorOperationalAddonOrderBuilder,
    private readonly VendorEventTabsService $eventTabsService,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Lists operational add-on purchases for the event.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function addons(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $tabs = $this->eventTabsService->getTabs($event, 'addon_orders');
    $document = $this->vendorOperationalAddonOrderBuilder->buildForEvent($event, $this->currentUser);
    $cache_tags = $this->vendorOperationalAddonOrderBuilder->collectCacheTagsForEvent($event);

    $content = [
      '#theme' => 'mel_vendor_operational_addon_orders',
      '#document' => $document,
      '#attached' => [
        'library' => ['myeventlane_commerce/vendor_operational_addon_orders'],
      ],
      '#cache' => [
        'keys' => ['mel_vendor_operational_addon_orders', 'event', (string) $event->id()],
        'tags' => $cache_tags,
        'contexts' => ['user', 'user.permissions', 'languages:language_interface'],
        'max-age' => VendorOperationalAddonOrderBuilder::VENDOR_ADDON_ORDERS_MAX_AGE,
      ],
    ];

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $event,
      'tabs' => $tabs,
      'actions' => [],
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => $content,
    ]);
  }

}
