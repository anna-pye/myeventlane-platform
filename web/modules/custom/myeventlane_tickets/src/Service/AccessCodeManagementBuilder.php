<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\AccessCode;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a compact access-code management panel for Event Studio.
 *
 * Reuses existing mel_access_code entities, routes, and access checks.
 * Does not duplicate any access-code logic, validation, hashing, or
 * save systems — it only reads and presents.
 */
final class AccessCodeManagementBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the access-code panel render array for a saved event.
   *
   * @return array<string, mixed>
   *   Render array for the panel, or empty array if the event is unsaved.
   */
  public function build(NodeInterface $event): array {
    if ($event->isNew() || $event->id() === NULL) {
      return $this->buildUnsavedState();
    }

    try {
      $codes = $this->loadAccessCodes($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Access code panel failed to load codes for event @event: @message', [
        '@event' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $event_id = (int) $event->id();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-studio-access-codes']],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-studio-access-codes__header']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Access codes'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Use access codes to reveal private ticket types to customers. Tickets with "Access code" visibility stay hidden on the booking page until a valid code is entered.'),
          '#attributes' => ['class' => ['mel-es-card__hint']],
        ],
      ],
    ];

    if ($codes === []) {
      $build['empty'] = $this->buildEmptyState($event_id);
    }
    else {
      $build['list'] = $this->buildCodeList($codes, $event_id);
      $build['create_action'] = $this->buildCreateAction($event_id);
    }

    $code_cache_tags = [];
    foreach ($codes as $code) {
      $code_cache_tags = array_merge($code_cache_tags, $code->getCacheTags());
    }

    $build['#cache'] = [
      'tags' => array_merge(
        $event->getCacheTags(),
        $code_cache_tags,
        ['mel_access_code_list'],
      ),
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

  /**
   * @return \Drupal\myeventlane_tickets\Entity\AccessCode[]
   */
  private function loadAccessCodes(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('event', $event->id())
      ->sort('code', 'ASC')
      ->execute();

    if (!$ids) {
      return [];
    }

    $entities = $storage->loadMultiple($ids);
    return array_filter($entities, static fn($e) => $e instanceof AccessCode);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildUnsavedState(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-studio-access-codes', 'mel-studio-access-codes--disabled']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Access codes'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Save this event before adding access codes.'),
        '#attributes' => ['class' => ['mel-es-card__hint', 'mel-studio-access-codes__disabled-hint']],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEmptyState(int $event_id): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-studio-access-codes__empty']],
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No access codes yet.'),
        '#attributes' => ['class' => ['mel-studio-access-codes__empty-text']],
      ],
      'action' => [
        '#type' => 'link',
        '#title' => $this->t('Create access code'),
        '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_add', [
          'event' => $event_id,
        ]),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--primary', 'mel-studio-access-codes__cta'],
        ],
      ],
    ];
  }

  /**
   * @param \Drupal\myeventlane_tickets\Entity\AccessCode[] $codes
   *
   * @return array<string, mixed>
   */
  private function buildCodeList(array $codes, int $event_id): array {
    $rows = [];
    foreach ($codes as $code) {
      $rows[] = $this->buildCodeRow($code, $event_id);
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-studio-access-codes__list']],
      'items' => $rows,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildCodeRow(AccessCode $code, int $event_id): array {
    $raw = $code->getCode();
    $masked = strlen($raw) > 4 ? '****' . substr($raw, -4) : '****';

    $status_value = (string) $code->get('status')->value;
    $status_label = match ($status_value) {
      AccessCode::STATUS_ACTIVE => $this->t('Active'),
      AccessCode::STATUS_INACTIVE => $this->t('Inactive'),
      AccessCode::STATUS_EXPIRED => $this->t('Expired'),
      default => $this->t('Unknown'),
    };

    $usage_count = (int) $code->get('usage_count')->value;
    $usage_limit = $code->get('usage_limit')->isEmpty() ? NULL : (int) $code->get('usage_limit')->value;
    $usage_text = $usage_limit !== NULL
      ? $this->t('@count / @limit used', ['@count' => $usage_count, '@limit' => $usage_limit])
      : $this->t('@count used', ['@count' => $usage_count]);

    $status_class = match ($status_value) {
      AccessCode::STATUS_ACTIVE => 'mel-studio-access-codes__status--active',
      AccessCode::STATUS_INACTIVE => 'mel-studio-access-codes__status--inactive',
      default => 'mel-studio-access-codes__status--expired',
    };

    $row = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-studio-access-codes__row']],
      'info' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-studio-access-codes__info']],
        'code' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $masked,
          '#attributes' => ['class' => ['mel-studio-access-codes__code']],
        ],
        'status' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $status_label,
          '#attributes' => ['class' => ['mel-studio-access-codes__status', $status_class]],
        ],
        'usage' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $usage_text,
          '#attributes' => ['class' => ['mel-studio-access-codes__usage']],
        ],
      ],
    ];

    if (!$code->get('expires')->isEmpty()) {
      $expires_raw = (string) $code->get('expires')->value;
      if ($expires_raw !== '') {
        $row['info']['expires'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Expires @date', ['@date' => $expires_raw]),
          '#attributes' => ['class' => ['mel-studio-access-codes__expires']],
        ];
      }
    }

    $row['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-studio-access-codes__actions']],
      'edit' => [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_edit', [
          'event' => $event_id,
          'mel_access_code' => $code->id(),
        ]),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--sm', 'mel-btn--secondary'],
        ],
      ],
      'delete' => [
        '#type' => 'link',
        '#title' => $this->t('Delete'),
        '#url' => Url::fromRoute('entity.mel_access_code.delete_form', [
          'event' => $event_id,
          'mel_access_code' => $code->id(),
        ]),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--sm', 'mel-btn--danger'],
        ],
      ],
    ];

    return $row;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildCreateAction(int $event_id): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-studio-access-codes__footer']],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Create access code'),
        '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes_add', [
          'event' => $event_id,
        ]),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--primary', 'mel-studio-access-codes__cta'],
        ],
      ],
    ];
  }

}
