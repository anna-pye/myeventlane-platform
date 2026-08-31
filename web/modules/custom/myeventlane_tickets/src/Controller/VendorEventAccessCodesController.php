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
 * Controller for Access Codes listing in vendor console.
 */
final class VendorEventAccessCodesController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

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
   * Lists access codes for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   The render array.
   */
  public function list(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('mel_access_code');

    // Query access codes filtered by event, sorted by code.
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('event', $event->id())
      ->sort('code', 'ASC');

    $code_ids = $query->execute();
    $codes = $code_ids ? $storage->loadMultiple($code_ids) : [];

    // Build content render array.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-tool-collection', 'mel-tickets-access-codes-list']],
    ];

    // Add header action button.
    $header_actions = [
      [
        'label' => $this->t('Create access code'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_add', ['event' => $event->id()])->toString(),
        'class' => 'mel-btn--cta',
      ],
    ];

    if (empty($codes)) {
      $build['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-tool-empty']],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '◌',
          '#attributes' => ['aria-hidden' => 'true'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('No private access codes yet'),
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Create a code when selected guests need access to particular ticket choices for this event.'),
        ],
        'action' => [
          '#type' => 'link',
          '#title' => $this->t('Create access code'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_add', ['event' => $event->id()]),
          '#attributes' => ['class' => ['mel-ticket-tool-button', 'mel-ticket-tool-button--primary']],
        ],
      ];
    }
    else {
      // Build table header.
      $header = [
        ['data' => $this->t('Code')],
        ['data' => $this->t('Status')],
        ['data' => $this->t('Usage')],
        ['data' => $this->t('Operations')],
      ];

      // Build table rows.
      $rows = [];
      foreach ($codes as $code) {
        /** @var \Drupal\myeventlane_tickets\Entity\AccessCode $code */
        $raw_code = $code->getCode();
        $masked = strlen($raw_code) > 4
          ? '****' . substr($raw_code, -4)
          : '****';

        $usage_count = (int) $code->get('usage_count')->value;
        $usage_limit = $code->get('usage_limit')->isEmpty() ? NULL : (int) $code->get('usage_limit')->value;
        if ($usage_limit !== NULL) {
          $usage_text = $this->t('@count / @limit', ['@count' => $usage_count, '@limit' => $usage_limit]);
        }
        else {
          $usage_text = $this->t('@count (unlimited)', ['@count' => $usage_count]);
        }

        $operations = [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_edit', [
                  'event' => $event->id(),
                  'mel_access_code' => $code->id(),
                ]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('entity.mel_access_code.delete_form', [
                  'event' => $event->id(),
                  'mel_access_code' => $code->id(),
                ]),
              ],
            ],
          ],
        ];

        $status = (string) $code->get('status')->value;
        $status_label = match ($status) {
          'active' => $this->t('Active'),
          'expired' => $this->t('Expired'),
          default => $this->t('Inactive'),
        };

        $rows[] = [
          'data' => [
            $masked,
            [
              'data' => [
                '#type' => 'html_tag',
                '#tag' => 'span',
                '#value' => $status_label,
                '#attributes' => ['class' => ['mel-ticket-tool-status', 'is-' . $status]],
              ],
            ],
            $usage_text,
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
          '#empty' => $this->t('No access codes have been created for this event.'),
        ],
      ];
    }

    $page = [
      '#theme' => 'mel_event_ticket_tool_collection',
      '#eyebrow' => $this->t('Private ticket access'),
      '#title' => $this->t('Access codes'),
      '#description' => $this->t('Create and manage private codes for selected ticket choices in this event.'),
      '#guide_title' => $this->t('Use codes for controlled access'),
      '#guide_text' => $this->t('Set an optional usage limit or expiry, then share the code privately. The full code is shown once when it is created.'),
      '#content' => $build,
    ];

    return $this->buildTicketsPage(
      $event,
      $page,
      'access_codes',
      $header_actions
    );
  }

}
