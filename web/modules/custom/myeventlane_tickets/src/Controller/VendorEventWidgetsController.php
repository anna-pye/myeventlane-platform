<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_tickets\Entity\PurchaseSurface;
use Drupal\myeventlane_tickets\Service\PurchaseSurfaceEmbedCodeBuilder;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Purchase Surface Widgets listing in vendor console.
 */
final class VendorEventWidgetsController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    VendorEventTabsService $eventTabsService,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly PurchaseSurfaceEmbedCodeBuilder $embedCodeBuilder,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger, $eventTabsService);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.service.event_tabs'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_tickets.purchase_surface_embed_code_builder'),
    );
  }

  /**
   * Lists purchase surface widgets for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   The render array.
   */
  public function list(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('mel_purchase_surface');

    // Query widgets filtered by event, sorted by created date.
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('event', $event->id())
      ->sort('created', 'DESC');

    $widget_ids = $query->execute();
    $widgets = $widget_ids ? $storage->loadMultiple($widget_ids) : [];

    $items = [];
    foreach ($widgets as $widget) {
      if (!$widget instanceof PurchaseSurface) {
        continue;
      }
      $items[] = [
        'id' => (int) $widget->id(),
        'label' => $widget->getLabel(),
        'type_label' => $this->surfaceTypeLabel($widget->getSurfaceType()),
        'status_label' => $widget->get('status')->value ? $this->t('Active') : $this->t('Paused'),
        'active' => (bool) $widget->get('status')->value,
        'embed_code' => $this->embedCodeBuilder->build($widget),
        'edit_url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets_edit', [
          'event' => $event->id(),
          'mel_purchase_surface' => $widget->id(),
        ])->toString(),
        'delete_url' => Url::fromRoute('entity.mel_purchase_surface.delete_form', [
          'event' => $event->id(),
          'mel_purchase_surface' => $widget->id(),
        ])->toString(),
      ];
    }

    $build = [
      '#theme' => 'mel_event_ticket_widgets',
      '#event' => $event,
      '#widgets' => $items,
      '#add_url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets_add', ['event' => $event->id()])->toString(),
      '#ticketing_url' => Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event->id()])->toString(),
      '#attached' => [
        'library' => ['myeventlane_tickets/purchase_surface_admin'],
      ],
    ];

    return $this->buildTicketsPage(
      $event,
      $build,
      'widgets'
    );
  }

  /**
   * Returns an honest organiser-facing label for the stored widget type.
   */
  private function surfaceTypeLabel(string $type): string {
    return match ($type) {
      PurchaseSurface::TYPE_POPUP => (string) $this->t('Booking button'),
      PurchaseSurface::TYPE_EMBEDDED_CHECKOUT => (string) $this->t('Event card'),
      PurchaseSurface::TYPE_COLLECTION => (string) $this->t('Compact event card'),
      default => (string) $this->t('Ticket widget'),
    };
  }

}
