<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Read-only configured merch/add-ons summary for Manage event dashboard.
 */
final class EventStudioExtrasConfiguredSummaryBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventStudioEventExtrasBuilder $extrasBuilder,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildPanel(NodeInterface $event): array {
    $event_id = (int) $event->id();
    if ($event_id < 1 || $event->bundle() !== 'event') {
      return $this->emptyPanel($event);
    }

    $cards = $this->extrasBuilder->loadExtrasForEvent($event);
    if ($cards === []) {
      return $this->emptyPanel($event);
    }

    $active = 0;
    $low_stock = 0;
    $sold_out = 0;
    $top = [];

    foreach ($cards as $card) {
      if (!empty($card['show_on_booking'])) {
        $active++;
      }
      if (!empty($card['stock_low'])) {
        $low_stock++;
      }
      if (!empty($card['stock_sold_out'])) {
        $sold_out++;
      }
    }
    $hidden_draft = count($cards) - $active;

    usort($cards, static function (array $a, array $b): int {
      return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
    foreach (array_slice($cards, 0, 3) as $card) {
      $top[] = [
        'name' => (string) ($card['title'] ?? ''),
        'type' => (string) ($card['classification_label'] ?? ''),
        'stock_state' => (string) ($card['stock_label'] ?? ''),
        'visibility' => (string) ($card['booking_visibility_label'] ?? ''),
      ];
    }

    return [
      'has_products' => TRUE,
      'stats' => [
        'total' => count($cards),
        'active_on_booking' => $active,
        'hidden_draft' => $hidden_draft,
        'low_stock' => $low_stock,
        'sold_out' => $sold_out,
      ],
      'top_products' => $top,
      'manage_url' => $this->manageExtrasUrl($event_id),
      'manage_label' => (string) $this->t('Manage merch & add-ons'),
      'empty_state' => NULL,
      'cache_tags' => $event->getCacheTags(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function buildPanelRenderArray(NodeInterface $event, int $weight = -5): array {
    $panel = $this->buildPanel($event);
    return $this->buildConfiguredSummaryRenderArray($panel, $event, $weight);
  }

  /**
   * Right-rail summary for the Merch & add-ons Studio section (list mode).
   *
   * @return array<string, mixed>
   */
  public function buildStudioAsideRenderArray(NodeInterface $event): array {
    $panel = $this->buildPanel($event);
    $panel['hide_manage_cta'] = TRUE;
    $panel['studio_context'] = TRUE;

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__aside']],
      'summary' => $this->buildConfiguredSummaryRenderArray($panel, $event, 0),
      'booking_preview' => $this->buildListBookingPreviewRenderArray($event),
      'next_steps' => $this->buildExtrasNextStepsCard($event, $panel),
      '#cache' => [
        'tags' => $panel['cache_tags'] ?? $event->getCacheTags(),
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $panel
   *
   * @return array<string, mixed>
   */
  private function buildConfiguredSummaryRenderArray(array $panel, NodeInterface $event, int $weight): array {
    return [
      '#theme' => 'mel_event_studio_extras_configured_summary',
      '#panel' => $panel,
      '#weight' => $weight,
      '#cache' => [
        'tags' => $panel['cache_tags'] ?? $event->getCacheTags(),
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function buildListBookingPreviewRenderArray(NodeInterface $event): array {
    $cards = $this->extrasBuilder->loadExtrasForEvent($event);
    $featured = $this->selectListPreviewCard($cards);

    if ($featured === NULL) {
      return [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'mel-es-card',
            'mel-event-extras-studio__booking-preview',
            'mel-event-extras-studio__booking-preview--empty',
          ],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Booking preview'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Add a merch item or add-on to see how guests may see it on your booking page.'),
          '#attributes' => ['class' => ['mel-text--muted']],
        ],
      ];
    }

    $preview = is_array($featured['preview'] ?? NULL) ? $featured['preview'] : [];
    $preview_meta_parts = array_filter([
      (string) ($featured['variant_count_label'] ?? ''),
      (string) ($featured['price_label'] ?? ''),
      (string) ($featured['stock_label'] ?? ''),
    ]);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-extras-studio__booking-preview']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Booking preview'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Sample of how a configured extra may appear to guests.'),
        '#attributes' => ['class' => ['mel-text--muted', 'mel-event-extras-studio__booking-preview-hint']],
      ],
      'preview' => [
        '#theme' => 'mel_event_studio_extra_preview',
        '#preview' => $preview,
        '#preview_meta_line' => implode(' · ', $preview_meta_parts),
        '#stock_label' => (string) ($featured['stock_label'] ?? ''),
        '#visibility_label' => (string) ($featured['booking_visibility_label'] ?? ''),
        '#hidden_notice' => empty($featured['show_on_booking'])
          ? (string) $this->t('This product is not visible on the booking page yet.')
          : '',
      ],
    ];
  }

  /**
   * @param array<string, mixed> $panel
   *
   * @return array<string, mixed>
   */
  private function buildExtrasNextStepsCard(NodeInterface $event, array $panel): array {
    $event_id = (int) $event->id();
    $items = [];

    if (empty($panel['has_products'])) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('Add your first merch or add-on'),
        '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event_id], [
          'query' => ['add' => 'merchandise'],
        ]),
      ];
      $items[] = (string) $this->t('Choose Active status and show on booking when you are ready to sell.');
    }
    else {
      $items[] = (string) $this->t('Review stock and booking visibility for each product.');
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('Preview booking page'),
        '#url' => Url::fromRoute(
          $event->isPublished() ? 'myeventlane_commerce.event_book' : 'entity.node.canonical',
          ['node' => $event_id],
        ),
      ];
    }

    $items[] = [
      '#type' => 'link',
      '#title' => $this->t('Continue to publish'),
      '#url' => Url::fromRoute('myeventlane_event_studio.edit_publish', ['node' => $event_id]),
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-next-steps', 'mel-event-extras-studio__next-steps']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Suggested next steps'),
        '#attributes' => ['class' => ['mel-event-studio-next-steps__title']],
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['mel-event-studio-next-steps__list']],
      ],
    ];
  }

  /**
   * @param list<array<string, mixed>> $cards
   *
   * @return array<string, mixed>|null
   */
  private function selectListPreviewCard(array $cards): ?array {
    if ($cards === []) {
      return NULL;
    }
    foreach ($cards as $card) {
      if (!empty($card['show_on_booking'])) {
        return $card;
      }
    }
    return $cards[0];
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyPanel(NodeInterface $event): array {
    $event_id = (int) $event->id();

    return [
      'has_products' => FALSE,
      'stats' => [],
      'top_products' => [],
      'manage_url' => $this->manageExtrasUrl($event_id),
      'manage_label' => (string) $this->t('Add merch & add-ons'),
      'empty_state' => [
        'title' => (string) $this->t('Add merch or extras to this event'),
        'copy' => (string) $this->t('Sell T-shirts, parking, meal packages, drink tokens, or VIP extras alongside your tickets.'),
        'note' => (string) $this->t('Extras use the same checkout, but they do not count as admission tickets.'),
      ],
      'cache_tags' => $event->getCacheTags(),
    ];
  }

  private function manageExtrasUrl(int $event_id): string {
    try {
      return Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event_id])->toString();
    }
    catch (\Throwable) {
      return '/vendor/events/' . $event_id . '/studio/extras';
    }
  }

}
