<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventIndexViewModelBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Vendor events listing controller.
 */
final class VendorEventsController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorEventIndexViewModelBuilder $eventIndexViewModelBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.event_index_view_model_builder'),
    );
  }

  /**
   * Displays the canonical organiser event index.
   */
  public function list(Request $request): array {
    $model = $this->eventIndexViewModelBuilder->build($this->currentUser, [
      'status' => $request->query->get('status') ?? 'all',
      'sort' => $request->query->get('sort') ?? 'created',
    ]);

    $body = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-events-console-layout']],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/mel_vendor_events',
        ],
      ],
      'index' => [
        '#theme' => 'myeventlane_vendor_events_grid',
        '#vendor_event_index_model' => $model,
        '#events' => [],
        '#cache' => [
          'contexts' => ['user'],
          'max-age' => 0,
        ],
      ],
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => NULL,
      'header_actions' => [],
      'tabs' => [],
      'body' => $body,
    ]);
  }

}
