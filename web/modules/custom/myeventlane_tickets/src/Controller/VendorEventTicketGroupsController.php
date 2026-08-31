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

    // Build collection content without changing group operations.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-tool-collection', 'mel-tickets-groups-list']],
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
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-tool-empty']],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '≡',
          '#attributes' => ['aria-hidden' => 'true'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Your tickets are currently one list'),
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Add a section to organise the booking page, or create a bundle that sells fixed ticket quantities together.'),
        ],
        'action' => [
          '#type' => 'link',
          '#title' => $this->t('Add section or bundle'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_groups_add', ['event' => $event->id()]),
          '#attributes' => ['class' => ['mel-ticket-tool-button', 'mel-ticket-tool-button--primary']],
        ],
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

      $build['table_wrap'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-tool-table-scroll']],
        'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $this->t('No ticket groups have been created for this event.'),
        ],
      ];
    }

    $page = [
      '#theme' => 'mel_event_ticket_tool_collection',
      '#eyebrow' => $this->t('Shape the booking page'),
      '#title' => $this->t('Ticket groups'),
      '#description' => $this->t('Organise choices under clear headings or sell a fixed mix of tickets as one bundle.'),
      '#guide_title' => $this->t('Sections organise. Bundles sell together.'),
      '#guide_text' => $this->t('A section keeps tickets individually priced. A bundle includes set quantities for one total price. Ticket capacity and checkout remain attached to this event.'),
      '#content' => $build,
    ];

    return $this->buildTicketsPage(
      $event,
      $page,
      'groups',
      $header_actions
    );
  }

}
