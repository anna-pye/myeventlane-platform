<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Vendor console: operational add-on orders for an event (read-only).
 */
final class VendorOperationalAddonOrdersController extends VendorConsoleBaseController {

  private const SALES_HELP_PATH = '/help/organisers/managing-event-sales-orders-add-ons-and-refunds';

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
    foreach ($document['orders'] ?? [] as $key => $order) {
      if (!empty($order['order_id'])) {
        $document['orders'][$key]['order_url'] = Url::fromRoute('myeventlane_vendor.console.event_order_view', [
          'event' => $event->id(),
          'order' => $order['order_id'],
        ])->toString();
      }
    }
    $cache_tags = $this->vendorOperationalAddonOrderBuilder->collectCacheTagsForEvent($event);

    $content = [
      '#theme' => 'mel_vendor_operational_addon_orders',
      '#document' => $document,
      '#event' => $event,
      '#help_url' => self::SALES_HELP_PATH,
      '#orders_url' => Url::fromRoute('myeventlane_event_studio.workspace_orders', ['node' => $event->id()])->toString(),
      '#refunds_url' => $this->safeRouteUrl('myeventlane_refunds.vendor_refund_requests', ['node' => $event->id()]),
      '#extras_url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()])->toString(),
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
      'workspace_chrome_after_content' => TRUE,
      'content' => $content,
    ]);
  }

  /**
   * Builds an optional sales-operation route without breaking add-on orders.
   *
   * @param array<string, mixed> $parameters
   *   Route parameters.
   */
  private function safeRouteUrl(string $routeName, array $parameters): ?string {
    try {
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

}
