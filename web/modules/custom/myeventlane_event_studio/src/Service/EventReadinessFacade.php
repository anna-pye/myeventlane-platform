<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event\Service\FeaturedEventReadinessService;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Single readiness view across publish gates and homepage promotion checks.
 *
 * Reuses EventReadinessService and FeaturedEventReadinessService without
 * duplicating their evaluation logic.
 */
final class EventReadinessFacade {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventReadinessService $publishReadiness,
    private readonly FeaturedEventReadinessService $promotionReadiness,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Unified readiness payload for Studio and merchandising surfaces.
   *
   * @return array{
   *   publish: EventReadinessResult,
   *   promotion: array<string, mixed>,
   *   publish_required: list<string>,
   *   promotion_required: list<string>,
   *   recommended: list<string>,
   *   publish_ready: bool,
   *   promotion_ready: bool,
   * }
   */
  public function evaluate(NodeInterface $event, AccountInterface $account): array {
    $publish = $this->publishReadiness->evaluate($event, $account);

    try {
      $promotion = $this->promotionReadiness->getPresentation($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio promotion readiness failed for event @nid: @message', [
        '@nid' => (string) ($event->id() ?? 'new'),
        '@message' => $e->getMessage(),
      ]);
      $promotion = [
        'ready' => FALSE,
        'items' => [],
        'required' => [],
        'recommended' => [],
      ];
    }

    $promotion_required = $this->incompleteItemLabels(
      is_array($promotion['required'] ?? NULL) ? $promotion['required'] : [],
    );
    $promotion_recommended = $this->incompleteItemLabels(
      is_array($promotion['recommended'] ?? NULL) ? $promotion['recommended'] : [],
    );

    return [
      'publish' => $publish,
      'promotion' => $promotion,
      'publish_required' => $publish->errors,
      'promotion_required' => $promotion_required,
      'recommended' => array_values(array_unique(array_merge(
        $publish->recommendations,
        $promotion_recommended,
      ))),
      'publish_ready' => $publish->ready,
      'promotion_ready' => (bool) ($promotion['ready'] ?? FALSE),
    ];
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return list<string>
   */
  private function incompleteItemLabels(array $items): array {
    $labels = [];
    foreach ($items as $item) {
      if (!is_array($item) || !empty($item['complete'])) {
        continue;
      }
      $label = trim((string) ($item['label_incomplete'] ?? $item['label'] ?? ''));
      if ($label !== '') {
        $labels[] = $label;
      }
    }
    return array_values(array_unique($labels));
  }

}
