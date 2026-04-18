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

    $event_id = (int) $event->id();

    $header_actions = [
      [
        'label' => $this->t('Open Event Studio'),
        'url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event_id])->toString(),
        'class' => 'mel-btn mel-btn--primary',
      ],
      [
        'label' => $this->t('View Page'),
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString(),
        'class' => 'mel-btn mel-btn--secondary',
        'external' => TRUE,
      ],
      [
        'label' => $this->t('Booking Page'),
        'url' => Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event_id])->toString(),
        'class' => 'mel-btn mel-btn--secondary',
        'external' => TRUE,
      ],
    ];

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $event,
      'tabs' => $tabs,
      'actions' => $header_actions,
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => [
        '#theme' => 'myeventlane_vendor_event_settings_page',
        '#event' => $event,
      ],
    ]);
  }

}
