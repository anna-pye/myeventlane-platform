<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Ticket Groups listing in vendor console.
 */
final class VendorEventTicketGroupsController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

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
    );
  }

  /**
   * Lists ticket groups for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   The render array.
   */
  public function list(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');

    // Query ticket groups filtered by event, sorted by weight.
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('event', $event->id())
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC');

    $group_ids = $query->execute();
    $groups = $group_ids ? $storage->loadMultiple($group_ids) : [];

    // Build content render array.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-tickets-groups-list']],
    ];
    $build['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-alert', 'mel-alert--info']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Create clear ticket sections or sell several tickets as one bundle.'),
      ],
      'detail' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('For a bundle, choose the included event tickets, set the quantity of each, then enter one total price. Ticket and event capacity still apply.'),
      ],
    ];

    // Add header action button.
    $header_actions = [
      [
        'label' => $this->t('Add section or bundle'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_groups_add', ['event' => $event->id()])->toString(),
        'style' => 'primary',
      ],
    ];

    if (empty($groups)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No groups yet. Your tickets will continue to appear as one list until you add a group.') . '</p>',
      ];
    }
    else {
      // Build table header.
      $header = [
        ['data' => $this->t('Booking section')],
        ['data' => $this->t('Tickets')],
        ['data' => $this->t('Shown to buyers')],
        ['data' => $this->t('Operations')],
      ];

      // Build table rows.
      $rows = [];
      foreach ($groups as $group) {
        /** @var \Drupal\myeventlane_tickets\Entity\TicketGroup $group */
        $operations = [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('myeventlane_tickets.event_tickets_groups_edit', [
                  'event' => $event->id(),
                  'mel_ticket_group' => $group->id(),
                ]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => $group->toUrl('delete-form', ['event' => $event->id()]),
              ],
            ],
          ],
        ];

        $rows[] = [
          'data' => [
            [
              'data' => [
                '#type' => 'container',
                'name' => [
                  '#type' => 'html_tag',
                  '#tag' => 'strong',
                  '#value' => $group->getName(),
                ],
                'type' => [
                  '#type' => 'html_tag',
                  '#tag' => 'div',
                  '#value' => ((string) ($group->get('group_mode')->value ?? 'section')) === 'bundle'
                    ? $this->t('Purchasable bundle')
                    : $this->t('Booking-page section'),
                  '#attributes' => ['class' => ['mel-text--muted']],
                ],
              ],
            ],
            $group->hasField('ticket_types') ? count($group->get('ticket_types')) : 0,
            $group->get('status')->value ? $this->t('Yes') : $this->t('No'),
            $operations,
          ],
        ];
      }

      $build['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No ticket groups have been created for this event.'),
      ];
    }

    return $this->buildTicketsPage(
      $event,
      $build,
      'groups',
      $header_actions
    );
  }

}
