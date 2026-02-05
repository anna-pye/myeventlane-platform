<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;

/**
 * Event settings controller.
 */
final class VendorEventSettingsController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorEventTabsService $eventTabsService,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays settings for an event.
   */
  public function settings(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $this->assertStripeConnected();
    $tabs = $this->eventTabsService->getTabs($event, 'settings');

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Settings',
      'tabs' => $tabs,
      'header_actions' => [
        [
          'label' => 'Edit Event',
          // Use wizard route for editing (vendors never see default node edit form).
          'url' => Url::fromRoute('myeventlane_event.wizard.edit', ['node' => $event->id()])->toString(),
          'class' => 'mel-btn--primary',
        ],
      ],
      'body' => [
        '#theme' => 'myeventlane_vendor_event_settings',
        '#event' => $event,
      ],
    ]);
  }

}
