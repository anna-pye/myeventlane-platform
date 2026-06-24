<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event\Service\FeaturedEventReadinessRenderBuilder;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\node\NodeInterface;

/**
 * Display-only workspace readiness and event health presentation.
 *
 * Does not evaluate readiness; consumes existing facade/controller outputs.
 */
final class EventStudioWorkspacePresentation {

  use StringTranslationTrait;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FeaturedEventReadinessRenderBuilder $homepageReadinessRender,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * @param array{
   *   publish: EventReadinessResult,
   *   promotion: array<string, mixed>,
   *   publish_required: list<string>,
   *   promotion_required: list<string>,
   *   recommended: list<string>,
   *   publish_ready: bool,
   *   promotion_ready: bool,
   * } $readiness_bundle
   *
   * @return array<string, mixed>
   */
  public function buildReadinessSummary(array $readiness_bundle, NodeInterface $node): array {
    $result = $readiness_bundle['publish'];
    $published = $node->isPublished();
    $has_blockers = $result->errors !== [] || $result->warnings !== [];

    return [
      'ready' => $result->ready,
      'errors' => $result->errors,
      'warnings' => $result->warnings,
      'completed' => $result->completed,
      'recommendations' => $readiness_bundle['recommended'],
      'state' => $this->operationalState($result),
      'promotion_ready' => (bool) ($readiness_bundle['promotion_ready'] ?? FALSE),
      'published' => $published,
      'show_publish_strip' => $this->shouldShowPublishStrip($result, $published),
      'strip_title' => $this->readinessStripTitle($result, $published),
    ];
  }

  /**
   * @param array{
   *   publish: EventReadinessResult,
   *   promotion: array<string, mixed>,
   *   promotion_ready: bool,
   * } $readiness_bundle
   * @param array<string, mixed>|null $boost
   *
   * @return array<string, mixed>
   */
  public function buildEventHealth(array $readiness_bundle, NodeInterface $node, ?array $boost): array {
    $result = $readiness_bundle['publish'];
    $published = $node->isPublished();
    $promotion_ready = (bool) ($readiness_bundle['promotion_ready'] ?? FALSE);
    $promotion = is_array($readiness_bundle['promotion'] ?? NULL) ? $readiness_bundle['promotion'] : [];

    $boost_active = is_array($boost) && !empty($boost['active']);
    $boost_detail = '';
    if ($boost_active) {
      if (isset($boost['days_remaining']) && $boost['days_remaining'] !== NULL) {
        $days = (int) $boost['days_remaining'];
        $boost_detail = $days === 1
          ? (string) $this->t('1 day remaining')
          : (string) $this->t('@count days remaining', ['@count' => $days]);
      }
      elseif (!empty($boost['expires'])) {
        $boost_detail = (string) $this->t('Featured until @date', ['@date' => (string) $boost['expires']]);
      }
    }

    return [
      'heading' => (string) $this->t('Event health'),
      'last_updated' => $this->formatLastUpdated($node),
      'items' => [
        [
          'key' => 'publish',
          'label' => (string) $this->t('Publish'),
          'value' => $published ? (string) $this->t('Published') : (string) $this->t('Draft'),
          'detail' => $result->ready
            ? (string) $this->t('Ready')
            : (string) $this->t('Needs attention'),
          'tone' => $result->ready ? 'ready' : 'attention',
        ],
        [
          'key' => 'promotion',
          'label' => (string) $this->t('Homepage promotion'),
          'value' => $promotion_ready
            ? (string) ($promotion['short_status_label'] ?? $promotion['status_label'] ?? $this->t('Ready for homepage promotion'))
            : (string) ($promotion['status_label'] ?? $this->t('Needs attention before homepage promotion')),
          'detail' => '',
          'tone' => $promotion_ready ? 'ready' : 'attention',
        ],
        [
          'key' => 'boost',
          'label' => (string) $this->t('Boost'),
          'value' => $boost_active ? (string) $this->t('Active') : (string) $this->t('Not active'),
          'detail' => $boost_detail,
          'tone' => $boost_active ? 'active' : 'muted',
        ],
      ],
    ];
  }

  /**
   * AJAX publish/readiness payload from the same facade bundle as Event Health.
   *
   * @param array{
   *   publish: EventReadinessResult,
   *   promotion: array<string, mixed>,
   *   recommended: list<string>,
   *   promotion_ready: bool,
   * } $readiness_bundle
   *
   * @return array<string, mixed>
   */
  public function buildAjaxReadinessPayloadFromBundle(array $readiness_bundle, NodeInterface $node): array {
    $result = $readiness_bundle['publish'];
    $published = $node->isPublished();

    return [
      'ready' => $result->ready,
      'errors' => $result->errors,
      'warnings' => $result->warnings,
      'completed' => $result->completed,
      'recommendations' => $readiness_bundle['recommended'] ?? [],
      'state' => $this->operationalState($result),
      'published' => $published,
      'show_publish_strip' => $this->shouldShowPublishStrip($result, $published),
      'strip_title' => $this->readinessStripTitle($result, $published),
      'show_homepage_readiness' => $this->shouldShowHomepageReadinessCard(
        (bool) ($readiness_bundle['promotion_ready'] ?? FALSE),
        $published,
      ),
    ];
  }

  public function operationalState(EventReadinessResult $result): string {
    if (!$result->ready) {
      return (string) $this->t('Needs Attention');
    }
    return (string) $this->t('Ready');
  }

  public function readinessStripTitle(EventReadinessResult $result, bool $published): string {
    if (!$result->ready) {
      return (string) $this->t('Needs attention');
    }
    if ($published) {
      return (string) $this->t('Published and ready');
    }
    return (string) $this->t('Ready to publish');
  }

  public function shouldShowPublishStrip(EventReadinessResult $result, bool $published): bool {
    return !$published || !$result->ready || $result->errors !== [] || $result->warnings !== [];
  }

  public function shouldShowHomepageReadinessCard(bool $promotion_ready, bool $published): bool {
    return !$promotion_ready || !$published;
  }

  /**
   * Homepage readiness card render array for workspace shell and AJAX refresh.
   *
   * @param array{
   *   promotion: array<string, mixed>,
   *   promotion_ready: bool,
   * } $readiness_bundle
   *
   * @return array<string, mixed>|null
   */
  public function buildHomepageReadinessCard(NodeInterface $node, array $readiness_bundle, string $section): ?array {
    $promotion_ready = (bool) ($readiness_bundle['promotion_ready'] ?? FALSE);
    $published = $node->isPublished();
    if (!$this->shouldShowHomepageReadinessCard($promotion_ready, $published)) {
      return NULL;
    }
    if ($section !== 'overview' && $promotion_ready) {
      return NULL;
    }

    return $this->homepageReadinessRender->buildChecklistCardFromPresentation(
      $node,
      is_array($readiness_bundle['promotion'] ?? NULL) ? $readiness_bundle['promotion'] : [],
      TRUE,
      !$promotion_ready,
      FALSE,
      $published,
    );
  }

  private function formatLastUpdated(NodeInterface $node): string {
    if ($node->getChangedTime() <= 0) {
      return (string) $this->t('Not saved yet');
    }
    return (string) $this->t('Last updated @time', [
      '@time' => $this->dateFormatter->format($node->getChangedTime(), 'short'),
    ]);
  }

}
