<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventCard;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\EventCtaResolver;
use Drupal\node\NodeInterface;

/**
 * Normalises public event card variables for Twig (keeps templates dumb).
 */
final class EventCardViewModel {

  public const VIEW_MODE_EDITORIAL = 'editorial_magazine';

  public const VIEW_MODE_COMPACT = 'compact_commerce';

  public const VIEW_MODE_LIST = 'list_card';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly FileSystemInterface $fileSystem,
    private readonly ?FlagServiceInterface $flagService,
    private readonly EventMerchandisingPresenter $merchandisingPresenter,
  ) {}

  /**
   * View modes that receive card presentation variables.
   *
   * @return list<string>
   */
  public function getCardViewModes(): array {
    return [
      self::VIEW_MODE_EDITORIAL,
      self::VIEW_MODE_COMPACT,
      self::VIEW_MODE_LIST,
      'card',
      'event_card',
      'teaser',
      'teaser_tonight',
      'teaser_featured',
      'event_card_poster',
      'event_card_compact',
      'event_home_card',
    ];
  }

  public function isCardViewMode(string $viewMode): bool {
    return in_array($viewMode, $this->getCardViewModes(), TRUE);
  }

  /**
   * Builds a normalised, access-safe card view model for an event node.
   *
   * @param array{
   *   event_ui?: array,
   *   mel_display_pricing?: array|null,
   *   mel_event_state?: string,
   *   cta_type?: string,
   *   event_cta?: array,
   *   event_domain_state?: array,
   *   mel_category_label?: string|null,
   *   mel_category_url?: string|null,
   *   mel_category_slug?: string,
   *   mel_social_proof_mode?: string,
   *   mel_source?: string|null,
   *   mel_tonight_urgency_label?: string|null,
   * } $context
   *   Values already resolved in node preprocess (module + theme).
   */
  public function build(NodeInterface $node, string $viewMode, array $context = []): array {
    if ($node->bundle() !== 'event') {
      return [];
    }

    $cacheability = CacheableMetadata::createFromObject($node);
    $this->addCategoryCacheability($node, $cacheability);
    $canView = $node->access('view', $this->currentUser);
    $url = '';
    if ($canView) {
      $canonicalUrl = $node->toUrl('canonical');
      $cacheability->addCacheableDependency($canonicalUrl);
      $url = $canonicalUrl->toString();
    }

    $presentation = $this->resolvePresentation($viewMode);
    $image = $this->resolveCardImage($node, $viewMode, $cacheability);
    $whereText = $this->locationLabel($node);

    $displayPricing = is_array($context['mel_display_pricing'] ?? NULL)
      ? $context['mel_display_pricing']
      : NULL;
    $badgeText = NULL;
    if (is_array($displayPricing) && ($displayPricing['type'] ?? NULL) !== BookingFlowResolver::MODE_EXTERNAL) {
      $label = trim((string) ($displayPricing['label'] ?? ''));
      $badgeText = $label !== '' ? $label : NULL;
    }
    $pricingType = is_array($displayPricing) ? (string) ($displayPricing['type'] ?? '') : '';

    $state = (string) ($context['mel_event_state'] ?? '');
    $ctaType = (string) ($context['cta_type'] ?? 'none');
    $eventUi = is_array($context['event_ui'] ?? NULL) ? $context['event_ui'] : [];
    $eventCta = is_array($context['event_cta'] ?? NULL) ? $context['event_cta'] : [];

    $cardType = $this->eventTypeLabel($node);
    $statusPrimary = $this->statusPrimary($node, $badgeText, $state, $ctaType);
    $statusSecondary = ($state === 'scheduled' && $ctaType === EventCtaResolver::CTA_PAID)
      ? trim((string) ($eventCta['label'] ?? ''))
      : NULL;

    $ctaLabel = $this->ctaLabel($eventUi, $eventCta, $state, $ctaType);
    $statusModifier = $this->statusModifier($state, $ctaType, $cardType, $pricingType);

    $domainState = is_array($context['event_domain_state'] ?? NULL) ? $context['event_domain_state'] : [];
    $isPromoted = !empty($domainState['is_boosted'])
      || ($node->hasField('field_promoted') && !$node->get('field_promoted')->isEmpty() && (bool) $node->get('field_promoted')->value);
    $isHiddenGem = $node->hasField('field_hidden_gem')
      && !$node->get('field_hidden_gem')->isEmpty()
      && (bool) $node->get('field_hidden_gem')->value;

    $categoryLabel = $context['mel_category_label'] ?? NULL;
    $categoryUrl = $context['mel_category_url'] ?? NULL;
    $category = ($categoryLabel !== NULL && $categoryUrl !== NULL)
      ? ['label' => $categoryLabel, 'url' => $categoryUrl]
      : NULL;

    [$cardDay, $cardMonth] = $this->startDayMonth($node);
    $startTimestamp = $this->startTimestamp($node);
    $whenText = $this->formatStartLabel($node);
    $hasDateBadge = $cardDay !== NULL && $cardMonth !== NULL;
    $timeDisplay = ($hasDateBadge && $startTimestamp !== NULL)
      ? $this->formatTimeLabel($startTimestamp)
      : ($whenText ?? '');
    $datetimeIso = $startTimestamp !== NULL ? date('c', $startTimestamp) : NULL;
    $socialProofMode = (string) ($context['mel_social_proof_mode'] ?? 'auto');
    [$lowStock, $attendance] = $this->capacitySignals($node, $cacheability, $socialProofMode);

    $capacityHint = trim(strip_tags((string) ($eventUi['capacity_hint'] ?? '')));
    $merchandising = $this->merchandisingPresenter->present([
      'layout' => $presentation['layout'],
      'variant' => $presentation['variant'],
      'attendance' => $attendance,
      'is_sold_out' => !empty($eventUi['is_sold_out']),
      'is_low_stock' => $lowStock,
      'capacity_hint' => $capacityHint !== '' ? $capacityHint : NULL,
      'is_promoted' => $isPromoted,
      'is_hidden_gem' => $isHiddenGem,
      'price_label' => $badgeText,
      'card_type' => $cardType,
      'tonight_urgency_label' => $context['mel_tonight_urgency_label'] ?? NULL,
      'mel_source' => $context['mel_source'] ?? NULL,
    ]);

    // Flag personalisation is placeholdered via buildSaveFlagLink(); keep the
    // card shell shareable for Dynamic Page Cache.
    $isSaved = FALSE;

    return [
      'event_id' => (int) $node->id(),
      'title' => $node->label(),
      'url' => $url,
      'image_url' => $image['url'],
      'image_render' => $image['render'],
      'image_alt' => $node->label(),
      'date_label' => $whenText ?? '',
      'time_label' => $timeDisplay,
      'datetime_iso' => $datetimeIso,
      'venue_label' => $whereText ?? '',
      'price_label' => $badgeText ?? '',
      'availability_label' => trim((string) ($eventUi['status_label'] ?? '')),
      'availability_state' => (string) ($eventUi['status_type'] ?? ''),
      'cta_label' => $ctaLabel,
      'cta_url' => $url,
      'is_sold_out' => !empty($eventUi['is_sold_out']),
      'is_free' => $pricingType === BookingFlowResolver::MODE_RSVP,
      'is_saved' => $isSaved,
      'variant' => $presentation['variant'],
      'layout' => $presentation['layout'],
      'card_type' => $cardType,
      'status_primary' => $statusPrimary,
      'status_secondary' => $statusSecondary ?: NULL,
      'status_modifier' => $statusModifier,
      'category' => $category,
      'date_day' => $cardDay,
      'date_month' => $cardMonth,
      'is_low_stock' => $lowStock,
      'attendance' => $attendance,
      'is_promoted' => $isPromoted,
      'merchandising' => $merchandising,
      'uses_placeholder_image' => $image['url'] === NULL || $image['url'] === '',
      '#cache' => [
        'tags' => $cacheability->getCacheTags(),
        'contexts' => $cacheability->getCacheContexts(),
        'max-age' => $cacheability->getCacheMaxAge(),
      ],
    ];
  }

  /**
   * Applies card variables to a node preprocess array for Twig.
   */
  public function applyToNodeVariables(array &$variables, string $viewMode): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    $context = [
      'event_ui' => $variables['event_ui'] ?? [],
      'mel_display_pricing' => $variables['mel_display_pricing'] ?? NULL,
      'mel_event_state' => $variables['mel_event_state'] ?? '',
      'cta_type' => $variables['cta_type'] ?? 'none',
      'event_cta' => $variables['event_cta'] ?? [],
      'event_domain_state' => $variables['event_domain_state'] ?? [],
      'mel_category_label' => $variables['mel_category_label'] ?? NULL,
      'mel_category_url' => $variables['mel_category_url'] ?? NULL,
      'mel_category_slug' => $variables['mel_category_slug'] ?? 'default',
      'mel_social_proof_mode' => $variables['mel_social_proof_mode'] ?? 'auto',
      'mel_source' => $variables['mel_source'] ?? NULL,
      'mel_tonight_urgency_label' => $variables['mel_tonight_urgency_label'] ?? NULL,
    ];

    $model = $this->build($node, $viewMode, $context);
    if ($model === []) {
      return;
    }

    $variables['event_card'] = $model;
    $variables['mel_event_card_variant'] = $model['variant'];
    $variables['mel_event_card_layout'] = $model['layout'];

    $variables['mel_card_image_url'] = $model['image_url'];
    $variables['mel_card_is_placeholder'] = $model['image_url'] === NULL || $model['image_url'] === '';
    $variables['mel_card_url'] = $model['url'];
    $variables['mel_card_when'] = $model['time_label'] ?: NULL;
    $variables['mel_card_where'] = $model['venue_label'] ?: NULL;
    $variables['mel_card_badge_text'] = $model['price_label'] !== '' ? $model['price_label'] : NULL;

    $slug = (string) ($context['mel_category_slug'] ?? 'default');
    $variables['mel_category_pill_classes'] = 'mel-category-pill mel-category-pill--' . $slug . ' mel-category-pill--sm';
    $variables['mel_category_name'] = $context['mel_category_label'] ?? NULL;
    $variables['mel_category_slug'] = $slug;

    $variables['card_url'] = $model['url'];
    $variables['card_title'] = $model['title'];
    $variables['card_image_url'] = $model['image_url'];
    $variables['card_image_render'] = $model['image_render'];
    $variables['card_image_alt'] = $model['image_alt'];
    $variables['card_time_range'] = $model['time_label'] ?: NULL;
    $variables['card_datetime_iso'] = $model['datetime_iso'] ?? NULL;
    $variables['card_location'] = $model['venue_label'] ?: NULL;
    $variables['card_price_label'] = $model['price_label'];
    $variables['card_category'] = $model['category'];
    $variables['card_day'] = $model['date_day'];
    $variables['card_month'] = $model['date_month'];
    $variables['card_type'] = $model['card_type'];
    $variables['card_status_primary'] = $model['status_primary'];
    $variables['card_status_secondary'] = $model['status_secondary'];
    $variables['card_status_modifier'] = $model['status_modifier'];
    $variables['card_cta_label'] = $model['cta_label'];
    $variables['card_is_low_stock'] = $model['is_low_stock'];
    $variables['card_attendance'] = $model['attendance'];
    $variables['card_merchandising'] = $model['merchandising'] ?? [];
    $variables['card_uses_placeholder_image'] = !empty($model['uses_placeholder_image']);
    $variables['mel_social_proof_mode'] = $context['mel_social_proof_mode'] ?? 'auto';
    $variables['is_promoted'] = $model['is_promoted'];
    $variables['event_id'] = $model['event_id'];

    // Saved-state UI is rendered via flag lazy builder only. Do not attach the
    // high-cardinality 'user' context to the shared card shell.
    $variables['event_flag_event_save'] = $this->buildSaveFlagLink($node);

    $this->mergeCacheFromModel($variables, $model);
    if (!isset($variables['#cache']['contexts'])) {
      $variables['#cache']['contexts'] = [];
    }
    $variables['#cache']['contexts'][] = 'route';
  }

  /**
   * @return array{variant: string, layout: string}
   */
  private function resolvePresentation(string $viewMode): array {
    return match ($viewMode) {
      self::VIEW_MODE_EDITORIAL => ['variant' => 'editorial', 'layout' => 'hero'],
      self::VIEW_MODE_COMPACT, 'event_home_card', 'event_card_compact' => ['variant' => 'compact', 'layout' => 'poster'],
      self::VIEW_MODE_LIST, 'event_card', 'teaser', 'card', 'teaser_tonight' => ['variant' => 'list', 'layout' => ''],
      'teaser_featured', 'event_card_poster' => ['variant' => 'featured', 'layout' => 'spotlight'],
      default => ['variant' => 'standard', 'layout' => ''],
    };
  }

  /**
   * @return array{url: ?string, render: mixed}
   */
  private function resolveCardImage(NodeInterface $node, string $viewMode, CacheableMetadata $cacheability): array {
    $url = NULL;
    $render = NULL;
    if (!$node->hasField('field_event_image') || $node->get('field_event_image')->isEmpty()) {
      return ['url' => NULL, 'render' => NULL];
    }

    $image_entity = $node->get('field_event_image')->entity;
    if (!$image_entity) {
      return ['url' => NULL, 'render' => NULL];
    }

    $uri = $image_entity->getFileUri();
    $real = $uri !== '' ? $this->fileSystem->realpath($uri) : FALSE;
    if ($real === FALSE || !is_file($real)) {
      return ['url' => NULL, 'render' => NULL];
    }

    $styleId = match ($viewMode) {
      self::VIEW_MODE_EDITORIAL, 'teaser_featured', 'event_card_poster' => 'mel_event_card_featured',
      default => 'mel_event_card_standard',
    };

    $style = $this->entityTypeManager->getStorage('image_style')->load($styleId);
    if ($style) {
      $url = $style->buildUrl($uri);
      $cacheability->addCacheableDependency($style);
    }
    else {
      $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
    }

    return ['url' => $url, 'render' => $render];
  }

  /**
   * Tonight-rail urgency label for an event start time (homepage Tonight only).
   */
  public function resolveTonightUrgencyLabel(NodeInterface $node): ?string {
    $startTimestamp = $this->startTimestamp($node);
    if ($startTimestamp === NULL) {
      return NULL;
    }

    $now = (int) \Drupal::time()->getRequestTime();
    $endTimestamp = NULL;
    if ($node->hasField('field_event_end') && !$node->get('field_event_end')->isEmpty()) {
      $endValue = (string) $node->get('field_event_end')->value;
      if ($endValue !== '' && ($endTs = strtotime($endValue)) !== FALSE) {
        $endTimestamp = (int) $endTs;
      }
    }

    if ($startTimestamp <= $now && ($endTimestamp === NULL || $endTimestamp > $now)) {
      return (string) t('Happening today');
    }
    if ($startTimestamp > $now) {
      return (string) t('Starts soon');
    }

    return NULL;
  }

  private function startTimestamp(NodeInterface $node): ?int {
    if (!$node->hasField('field_event_start') || $node->get('field_event_start')->isEmpty()) {
      return NULL;
    }
    $startValue = (string) $node->get('field_event_start')->value;
    if ($startValue === '') {
      return NULL;
    }
    $startTimestamp = strtotime($startValue);
    return $startTimestamp !== FALSE ? (int) $startTimestamp : NULL;
  }

  private function formatTimeLabel(int $startTimestamp): string {
    return date('g:ia', $startTimestamp);
  }

  private function formatStartLabel(NodeInterface $node): ?string {
    $startTimestamp = $this->startTimestamp($node);
    return $startTimestamp !== NULL ? date('D j M, g:ia', $startTimestamp) : NULL;
  }

  /**
   * @return array{0: ?string, 1: ?string}
   */
  private function startDayMonth(NodeInterface $node): array {
    $startTimestamp = $this->startTimestamp($node);
    if ($startTimestamp === NULL) {
      return [NULL, NULL];
    }
    return [(string) (int) date('j', $startTimestamp), date('M', $startTimestamp)];
  }

  /**
   * Single-line primary location label (venue entity, venue name, or locality).
   *
   * Shared by public event cards, heroes, and Event Studio summaries.
   */
  public function getLocationLabel(NodeInterface $node): ?string {
    return $this->locationLabel($node);
  }

  private function locationLabel(NodeInterface $node): ?string {
    if ($node->hasField('field_venue') && !$node->get('field_venue')->isEmpty()) {
      $venue = $node->get('field_venue')->entity;
      if ($venue) {
        $name = trim((string) $venue->label());
        if ($name !== '') {
          return $name;
        }
      }
    }
    if ($node->hasField('field_venue_name') && !$node->get('field_venue_name')->isEmpty()) {
      $name = trim((string) $node->get('field_venue_name')->value);
      if ($name !== '') {
        return $name;
      }
    }
    if ($node->hasField('field_location') && !$node->get('field_location')->isEmpty()) {
      $locationItem = $node->get('field_location')->first();
      if ($locationItem) {
        $locality = $locationItem->get('locality')->getValue();
        $administrativeArea = $locationItem->get('administrative_area')->getValue();
        if ($locality) {
          return $administrativeArea ? $locality . ', ' . $administrativeArea : $locality;
        }
        $line1 = trim((string) $locationItem->get('address_line1')->getValue());
        if ($line1 !== '') {
          return $line1;
        }
      }
    }
    return NULL;
  }

  private function eventTypeLabel(NodeInterface $node): string {
    if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
      $et = (string) $node->get('field_event_type')->value;
      if (in_array($et, ['rsvp', 'paid', 'both', 'external'], TRUE)) {
        return $et;
      }
    }
    return 'none';
  }

  private function statusPrimary(NodeInterface $node, ?string $badgeText, string $state, string $ctaType): ?string {
    if (in_array($state, ['cancelled'], TRUE)) {
      return (string) t('Cancelled');
    }
    if ($state === 'ended') {
      return (string) t('Ended');
    }
    if ($state === 'sold_out') {
      return (string) t('Sold out');
    }
    if ($state === 'scheduled' && $ctaType === EventCtaResolver::CTA_PAID) {
      return (string) t('On sale soon');
    }
    $eventType = NULL;
    if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
      $eventType = $node->get('field_event_type')->value;
    }
    if ($eventType === 'external') {
      return (string) t('External ticketing');
    }
    if ($badgeText === 'Free RSVP' || $badgeText === 'Free') {
      return $badgeText;
    }
    if ($badgeText !== NULL && $badgeText !== '' && $badgeText !== 'External') {
      return $badgeText;
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $eventUi
   * @param array<string, mixed> $eventCta
   */
  private function ctaLabel(array $eventUi, array $eventCta, string $state, string $ctaType): string {
    if ($eventUi !== [] && empty($eventUi['show_cta'])) {
      return '';
    }
    if ($eventUi !== [] && trim((string) ($eventUi['cta_label'] ?? '')) !== '') {
      return trim((string) $eventUi['cta_label']);
    }
    return '';
  }

  private function statusModifier(string $state, string $ctaType, string $cardType, string $pricingType): string {
    return match (TRUE) {
      in_array($state, ['cancelled'], TRUE) => 'cancelled',
      $state === 'ended' => 'ended',
      $state === 'sold_out' => 'sold-out',
      $state === 'scheduled' && $ctaType === EventCtaResolver::CTA_PAID => 'scheduled',
      $cardType === 'external' => 'external',
      $pricingType === BookingFlowResolver::MODE_RSVP => 'free',
      $pricingType === BookingFlowResolver::MODE_PAID => 'paid',
      default => 'default',
    };
  }

  /**
   * @return array{0: bool, 1: int|null}
   */
  private function capacitySignals(NodeInterface $node, CacheableMetadata $cacheability, string $socialProofMode = 'auto'): array {
    if ($socialProofMode === 'hide') {
      return [FALSE, NULL];
    }

    $lowStock = FALSE;
    $attendance = NULL;
    if (\Drupal::hasService('myeventlane_capacity.service')) {
      $capacity = \Drupal::service('myeventlane_capacity.service');
      $totalCap = $capacity->getCapacityTotal($node);
      $remaining = $capacity->getRemaining($node);
      if ($totalCap !== NULL && $remaining !== NULL && $remaining > 0) {
        $threshold = max(3, (int) floor($totalCap * 0.15));
        if ($remaining <= $threshold) {
          $lowStock = TRUE;
        }
      }
    }
    if (\Drupal::hasService('myeventlane_domain_events.read_model.event_metrics')) {
      $readModel = \Drupal::service('myeventlane_domain_events.read_model.event_metrics');
      $metrics = $readModel->getEventMetrics((int) $node->id());
      $going = (int) ($metrics['tickets_sold'] ?? 0) + (int) ($metrics['rsvp_count'] ?? 0);
      $attendanceThreshold = $socialProofMode === 'show' ? 1 : 8;
      if ($going >= $attendanceThreshold) {
        $attendance = $going;
      }
      $cacheability->addCacheTags([sprintf('event_metrics:%d', (int) $node->id())]);
    }
    return [$lowStock, $attendance];
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildSaveFlagLink(NodeInterface $node): ?array {
    if (!\Drupal::moduleHandler()->moduleExists('flag') || !\Drupal::hasService('flag.link_builder')) {
      return NULL;
    }
    if (!$this->flagService || !$this->flagService->getFlagById('event_save')) {
      return NULL;
    }
    return [
      '#lazy_builder' => [
        'flag.link_builder:build',
        [
          'node',
          (string) (int) $node->id(),
          'event_save',
          'default',
        ],
      ],
      '#create_placeholder' => TRUE,
    ];
  }

  /**
   * Ensures category pill data invalidates when the referenced term changes.
   */
  private function addCategoryCacheability(NodeInterface $node, CacheableMetadata $cacheability): void {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return;
    }
    foreach ($node->get('field_category')->referencedEntities() as $term) {
      if ($term !== NULL) {
        $cacheability->addCacheableDependency($term);
        return;
      }
    }
  }

  /**
   * @param array<string, mixed> $variables
   * @param array<string, mixed> $model
   */
  private function mergeCacheFromModel(array &$variables, array $model): void {
    $cache = $model['#cache'] ?? [];
    if (!is_array($cache)) {
      return;
    }
    if (!isset($variables['#cache'])) {
      $variables['#cache'] = [];
    }
    if (!empty($cache['tags'])) {
      $variables['#cache']['tags'] = Cache::mergeTags(
        (array) ($variables['#cache']['tags'] ?? []),
        $cache['tags']
      );
    }
    if (!empty($cache['contexts'])) {
      $existing = (array) ($variables['#cache']['contexts'] ?? []);
      $variables['#cache']['contexts'] = array_values(array_unique(array_merge($existing, $cache['contexts'])));
    }
    if (isset($cache['max-age'])) {
      $variables['#cache']['max-age'] = min(
        $variables['#cache']['max-age'] ?? Cache::PERMANENT,
        $cache['max-age']
      );
    }
  }

}
