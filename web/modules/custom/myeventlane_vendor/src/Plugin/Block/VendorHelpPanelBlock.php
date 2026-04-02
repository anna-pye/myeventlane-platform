<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Plugin\Block;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_event_studio\Service\EventRepository;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_help_assistant\Service\EventSuggestionService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contextual help panel shown in the Vendor Studio sidebar.
 */
#[Block(
  id: 'myeventlane_vendor_help_panel',
  admin_label: new \Drupal\Core\StringTranslation\TranslatableMarkup('Vendor Help Assistant Panel'),
  category: new \Drupal\Core\StringTranslation\TranslatableMarkup('MyEventLane')
)]
final class VendorHelpPanelBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a VendorHelpPanelBlock instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrentVendorResolverInterface $currentVendorResolver,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly RouteMatchInterface $routeMatch,
    private readonly TimeInterface $time,
    private readonly ?EventSuggestionService $eventSuggestionService,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly EventRepository $eventRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('current_user'),
      $container->get('logger.channel.myeventlane_vendor'),
      $container->get('current_route_match'),
      $container->get('datetime.time'),
      $container->has('myeventlane_help_assistant.event_suggestions')
        ? $container->get('myeventlane_help_assistant.event_suggestions')
        : NULL,
      $container->get('csrf_token'),
      $container->get('myeventlane_core.entity_id_normalizer'),
      $container->get('myeventlane_event_studio.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $payload = [
      'global' => [
        'revenue' => 300,
        'events_total' => 0,
        'events_published' => 0,
        'conversion_hint' => (string) $this->t('Events with multiple ticket types perform better.'),
      ],
      'event' => NULL,
    ];
    $show_boost = FALSE;

    try {
      $vendor = $this->currentVendorResolver->resolveFromCurrentUser();
      if (!$vendor instanceof Vendor) {
        $payload['global']['conversion_hint'] = (string) $this->t('Finish your organiser profile to unlock smarter suggestions.');
      }
      else {
        $payload = $this->buildVendorPayload($vendor);
        $show_boost = $this->shouldShowBoost($payload['event'] ?? NULL);
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor help panel failed for uid=@uid: @message', [
        '@uid' => (int) $this->currentUser->id(),
        '@message' => $exception->getMessage(),
      ]);

      $payload['global']['conversion_hint'] = (string) $this->t('We could not load contextual guidance right now.');
    }

    $cache_tags = ['node_list'];
    $event = $payload['event']['entity'] ?? NULL;
    if ($event instanceof NodeInterface) {
      $cache_tags[] = 'node:' . (int) $event->id();
      unset($payload['event']['entity']);
    }

    $build = [
      '#theme' => 'myeventlane_vendor_help_panel',
      '#data' => $payload,
      '#show_boost' => $show_boost,
      '#cache' => [
        'contexts' => ['user', 'route'],
        'tags' => $cache_tags,
        'max-age' => 300,
      ],
    ];

    if ($event instanceof NodeInterface) {
      $build['#attached']['library'][] = 'myeventlane_vendor/vendor_help_panel';
      $build['#attached']['drupalSettings']['melVendorHelp'] = [
        'csrfToken' => $this->csrfToken->get('mel_help_action'),
        'eventId' => (int) $event->id(),
        'helpActionBaseUrl' => '/api/help-action/',
      ];
    }

    return $build;
  }

  /**
   * Builds panel payload from vendor event state.
   *
   * @return array<string, mixed>
   *   Twig payload.
   */
  private function buildVendorPayload(Vendor $vendor): array {
    $event_stats = $this->loadVendorEventStats((int) $vendor->id());
    $focus_event = $this->resolveEventContext();
    if ($focus_event !== NULL && !$this->isEventOwnedByCurrentUser($focus_event, $vendor)) {
      $this->logger->warning('Vendor help panel ignored non-owned event context for uid=@uid event=@event', [
        '@uid' => (int) $this->currentUser->id(),
        '@event' => (int) $focus_event->id(),
      ]);
      $focus_event = NULL;
    }

    return [
      'global' => [
        'revenue' => 300,
        'events_total' => $event_stats['events_total'],
        'events_published' => $event_stats['events_published'],
        'conversion_hint' => (string) $this->t('Events with multiple ticket types perform better.'),
      ],
      'event' => $focus_event ? $this->buildEventPayload($focus_event) : NULL,
    ];
  }

  /**
   * Builds event section payload for the hybrid panel.
   *
   * @return array<string, mixed>
   */
  private function buildEventPayload(NodeInterface $event): array {
    $insights = $this->eventSuggestionService?->buildWizardInsights([], $event) ?? [];
    $score = (int) ($insights['score'] ?? 68);
    $status = $this->mapStatusFromScore($score);

    $suggestions = [];
    if ($this->eventSuggestionService !== NULL) {
      try {
        $raw = $this->eventSuggestionService->getSuggestions([], $event);
        foreach ($raw as $item) {
          if (!is_array($item)) {
            continue;
          }
          $action = NULL;
          if (!empty($item['action']) && is_array($item['action'])) {
            $action = $item['action'];
          }
          elseif (!empty($item['cta']['url']) && is_string($item['cta']['url'])) {
            $label = trim((string) ($item['cta']['label'] ?? $this->t('Learn more')));
            $action = [
              'type' => 'link',
              'label' => $label,
              'url' => (string) $item['cta']['url'],
            ];
          }
          $suggestions[] = [
            'id' => (string) ($item['id'] ?? ''),
            'title' => (string) ($item['title'] ?? $this->t('Suggestion')),
            'message' => (string) ($item['message'] ?? ''),
            'action' => $action,
          ];
        }
      }
      catch (\Throwable $exception) {
        $this->logger->error('Suggestion service failed for event=@event: @message', [
          '@event' => (int) $event->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    return [
      'entity' => $event,
      'title' => $event->label() !== '' ? $event->label() : (string) $this->t('Friday Night Social'),
      'score' => max(0, min(100, $score)),
      'status' => $status,
      'suggestions' => array_slice($suggestions, 0, 3),
    ];
  }

  /**
   * Loads vendor-scoped event counts.
   *
   * @return array{events_total: int, events_published: int}
   *   Event count totals for global panel section.
   */
  private function loadVendorEventStats(int $vendor_id): array {
    if ($vendor_id <= 0) {
      return ['events_total' => 0, 'events_published' => 0];
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return ['events_total' => 0, 'events_published' => 0];
    }

    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', $uid);

    // Before: query execute() keyed list; mixed scalar types possible.
    // After: int nids for loadMultiple().
    $all_ids = $this->entityIdNormalizer->normalizeNodeIds(array_values($query->execute()));
    if ($all_ids === []) {
      return ['events_total' => 0, 'events_published' => 0];
    }

    $published = 0;
    foreach ($this->eventRepository->loadMany($all_ids) as $dto) {
      if ($dto->published) {
        $published++;
      }
    }

    return [
      'events_total' => count($all_ids),
      'events_published' => $published,
    ];
  }

  /**
   * Resolves route-based event context.
   */
  private function resolveEventContext(): ?NodeInterface {
    $event = $this->routeMatch->getParameter('event');
    if ($event instanceof NodeInterface && $event->bundle() === 'event') {
      return $event;
    }
    return NULL;
  }

  /**
   * Determines if event is owned by current account.
   */
  private function isEventOwnedByCurrentUser(NodeInterface $event, ?Vendor $vendor = NULL): bool {
    if ((int) $event->getOwnerId() === (int) $this->currentUser->id()) {
      return TRUE;
    }

    if ($vendor instanceof Vendor && $event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      return (int) $event->get('field_event_vendor')->target_id === (int) $vendor->id();
    }

    return FALSE;
  }

  /**
   * Maps score value to MEL status labels.
   */
  private function mapStatusFromScore(int $score): string {
    if ($score >= 85) {
      return 'excellent';
    }
    if ($score >= 70) {
      return 'good';
    }
    return 'needs work';
  }

  /**
   * Phase-1 low-engagement logic and boost visibility rule.
   *
   * @param array<string, mixed>|null $event_payload
   *   Event payload data.
   */
  private function shouldShowBoost(?array $event_payload): bool {
    $event = $event_payload['entity'] ?? NULL;
    $has_event_context = $event instanceof NodeInterface && $event->bundle() === 'event';
    if (!$has_event_context) {
      return FALSE;
    }

    $now = $this->time->getCurrentTime();
    $published_time = $event->getCreatedTime();
    $hours_since_publish = ($now - $published_time) / 3600;

    // Phase 1 placeholders until engagement analytics are wired.
    $sales_count = 0;
    $has_tickets = TRUE;
    $is_boosted = FALSE;

    $is_low_engagement = $event->isPublished()
      && $has_tickets
      && $sales_count === 0
      && $hours_since_publish >= 48;

    return $has_event_context && $is_low_engagement && !$is_boosted;
  }

  /**
   * Checks whether an event should be treated as published/live.
   */
  private function isEventPublished(?NodeInterface $event): bool {
    if (!$event instanceof NodeInterface) {
      return FALSE;
    }
    if ($event->isPublished()) {
      return TRUE;
    }
    if ($event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()) {
      $state = (string) $event->get('moderation_state')->value;
      return in_array($state, ['published', 'live'], TRUE);
    }
    return FALSE;
  }

}
