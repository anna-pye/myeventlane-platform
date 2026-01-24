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
      '#attributes' => ['class' => ['mel-tickets-access-codes-list']],
    ];

    // Add header action button.
    $header_actions = [
      [
        'label' => $this->t('Add access code'),
        'url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_add', ['event' => $event->id()])->toString(),
        'style' => 'primary',
      ],
    ];

    if (empty($codes)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No access codes have been created for this event.') . '</p>',
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
                'url' => $code->toUrl('delete-form', ['event' => $event->id()]),
              ],
            ],
          ],
        ];

        $rows[] = [
          'data' => [
            $code->getCode(),
            $code->get('status')->value,
            $usage_text,
            $operations,
          ],
        ];
      }

      $build['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No access codes have been created for this event.'),
      ];
    }

    return $this->buildTicketsPage(
      $event,
      $build,
      $this->t('Access codes'),
      'access_codes',
      $header_actions
    );
  }

}
