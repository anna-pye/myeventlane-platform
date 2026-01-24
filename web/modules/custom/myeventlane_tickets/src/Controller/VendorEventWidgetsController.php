<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
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
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($domainDetector, $currentUser);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
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

    // Build content render array.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-tickets-widgets-list']],
    ];

    // Add header action button.
    $header_actions = [
      [
        'label' => $this->t('Add widget'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets_add', ['event' => $event->id()])->toString(),
        'style' => 'primary',
      ],
    ];

    if (empty($widgets)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No embedded widgets have been created for this event.') . '</p>',
      ];
    }
    else {
      // Build table header.
      $header = [
        ['data' => $this->t('Label')],
        ['data' => $this->t('Type')],
        ['data' => $this->t('Status')],
        ['data' => $this->t('Operations')],
      ];

      // Build table rows.
      $rows = [];
      foreach ($widgets as $widget) {
        /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $widget */
        $operations = [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets_edit', [
                  'event' => $event->id(),
                  'mel_purchase_surface' => $widget->id(),
                ]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => $widget->toUrl('delete-form', ['event' => $event->id()]),
              ],
            ],
          ],
        ];

        $rows[] = [
          'data' => [
            $widget->getLabel(),
            $widget->get('surface_type')->value,
            $widget->get('status')->value ? $this->t('Active') : $this->t('Inactive'),
            $operations,
          ],
        ];
      }

      $build['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No embedded widgets have been created for this event.'),
      ];
    }

    return $this->buildTicketsPage(
      $event,
      $build,
      $this->t('Embedded widgets'),
      'widgets',
      $header_actions
    );
  }

}
