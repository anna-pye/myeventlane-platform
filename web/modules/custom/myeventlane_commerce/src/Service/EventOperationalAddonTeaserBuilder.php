<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Compact customer read model for operational add-on discovery (sidebars).
 *
 * Uses {@see EventOperationalAddonBuilder} as the single catalog source.
 */
final class EventOperationalAddonTeaserBuilder {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_operational_addon_teaser';

  private const MAX_ITEMS = 3;

  public function __construct(
    private readonly EventOperationalAddonBuilder $addonBuilder,
    private readonly CurrencyFormatter $currencyFormatter,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds a render-safe teaser document for Twig.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $surface
   *   Event surface: link to book page, or book (in-page scroll only).
   *
   * @return array<string, mixed>
   *   Teaser document for Twig, or empty when no add-ons exist.
   */
  public function buildForEvent(NodeInterface $event, string $surface = 'event'): array {
    $event_id = (int) $event->id();
    if ($event->bundle() !== 'event' || $event_id < 1) {
      return $this->emptyDocument();
    }

    $built = $this->addonBuilder->buildForEvent($event);
    $addons = is_array($built['addons'] ?? NULL) ? $built['addons'] : [];
    if ($addons === []) {
      return $this->emptyDocument();
    }

    $items = [];
    $cache_tags = $event->getCacheTags();
    foreach ($built['product_ids'] ?? [] as $pid) {
      $cache_tags[] = 'commerce_product:' . (int) $pid;
    }

    $display_addons = array_slice($addons, 0, self::MAX_ITEMS);
    foreach ($display_addons as $addon) {
      if (!is_array($addon)) {
        continue;
      }
      $line = $this->buildTeaserItem($addon);
      if ($line !== NULL) {
        $items[] = $line;
      }
    }

    if ($items === []) {
      return $this->emptyDocument();
    }

    $total = count($addons);
    $more = max(0, $total - count($items));
    $book_url = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event_id], [
      'fragment' => 'mel-operational-addons',
    ])->toString();
    $on_book_page = $surface === 'book';

    return [
      self::CONTRACT_FLAG => TRUE,
      'schema_version' => 1,
      'empty' => FALSE,
      'event_id' => $event_id,
      'item_count' => $total,
      'more_count' => $more,
      'title' => $on_book_page
        ? (string) $this->t('Available extras')
        : (string) $this->t('Extras at this event'),
      'intro' => $on_book_page
        ? (string) $this->t('Add these on this page — collect at the event after purchase.')
        : (string) $this->t('Merch and add-ons you can include when you book.'),
      'show_book_cta' => !$on_book_page,
      'show_scroll_cta' => $on_book_page,
      'book_url' => $on_book_page ? '' : $book_url,
      'book_anchor' => '#mel-operational-addons',
      'choose_label' => (string) $this->t('Choose extras ↓'),
      'book_cta_label' => (string) $this->t('View & add when booking'),
      'more_label' => $more > 0
        ? (string) $this->t('+ @count more', ['@count' => (string) $more])
        : '',
      'items' => $items,
      'cache_tags' => array_values(array_unique($cache_tags)),
    ];
  }

  /**
   * Empty teaser document when no add-ons are available.
   *
   * @return array<string, mixed>
   *   Empty teaser document.
   */
  private function emptyDocument(): array {
    return [
      self::CONTRACT_FLAG => TRUE,
      'schema_version' => 1,
      'empty' => TRUE,
      'items' => [],
      'cache_tags' => [],
    ];
  }

  /**
   * Builds one teaser list item from an add-on catalog row.
   *
   * @param array<string, mixed> $addon
   *   Add-on row from {@see EventOperationalAddonBuilder}.
   *
   * @return array<string, mixed>|null
   *   Teaser item, or NULL when the row cannot be displayed.
   */
  private function buildTeaserItem(array $addon): ?array {
    $title = trim((string) ($addon['title'] ?? ''));
    if ($title === '') {
      return NULL;
    }

    $primary = is_array($addon['primary_image'] ?? NULL) ? $addon['primary_image'] : [];
    $thumbnail_url = trim((string) ($primary['url'] ?? ''));
    $thumbnail_alt = trim((string) ($addon['image_alt'] ?? $title));

    return [
      'product_id' => (int) ($addon['product_id'] ?? 0),
      'title' => $title,
      'thumbnail_url' => $thumbnail_url,
      'thumbnail_alt' => $thumbnail_alt !== '' ? $thumbnail_alt : $title,
      'price_label' => $this->buildPriceLabel($addon),
      'size_hint' => $this->buildSizeHint($addon),
    ];
  }

  /**
   * Formats a price label for a teaser item.
   *
   * @param array<string, mixed> $addon
   *   Add-on row from {@see EventOperationalAddonBuilder}.
   *
   * @return string
   *   Formatted price label, or empty when unknown.
   */
  private function buildPriceLabel(array $addon): string {
    $amounts = [];
    $currency = '';

    $size_options = is_array($addon['size_options'] ?? NULL) ? $addon['size_options'] : [];
    if ($size_options !== []) {
      foreach ($size_options as $opt) {
        if (!is_array($opt)) {
          continue;
        }
        $number = (string) ($opt['price_number'] ?? '');
        if ($number === '') {
          continue;
        }
        $amounts[] = (float) $number;
        if ($currency === '') {
          $currency = (string) ($opt['price_currency_code'] ?? '');
        }
      }
    }
    else {
      $variations = is_array($addon['variations'] ?? NULL) ? $addon['variations'] : [];
      foreach ($variations as $variation) {
        if (!is_array($variation)) {
          continue;
        }
        $number = (string) ($variation['price_number'] ?? '');
        if ($number === '') {
          continue;
        }
        $amounts[] = (float) $number;
        if ($currency === '') {
          $currency = (string) ($variation['price_currency_code'] ?? '');
        }
      }
    }

    if ($amounts === []) {
      return '';
    }

    $min = min($amounts);
    $max = max($amounts);
    $code = $currency !== '' ? $currency : 'AUD';
    $min_fmt = $this->currencyFormatter->format((string) $min, $code);
    if ($max > $min + 0.001) {
      return (string) $this->t('From @price', ['@price' => $min_fmt]);
    }
    return $min_fmt;
  }

  /**
   * Builds a short size hint for sized merchandise.
   *
   * @param array<string, mixed> $addon
   *   Add-on row from {@see EventOperationalAddonBuilder}.
   *
   * @return string
   *   Size hint label, or empty when not applicable.
   */
  private function buildSizeHint(array $addon): string {
    if (empty($addon['requires_size_selection'])) {
      return '';
    }
    $labels = [];
    $size_options = is_array($addon['size_options'] ?? NULL) ? $addon['size_options'] : [];
    foreach ($size_options as $opt) {
      if (!is_array($opt)) {
        continue;
      }
      $label = trim((string) ($opt['size_label'] ?? ''));
      if ($label === '') {
        $key = trim((string) ($opt['size_key'] ?? ''));
        $label = $key !== '' ? strtoupper($key) : '';
      }
      if ($label !== '') {
        $labels[] = $label;
      }
    }
    if ($labels === []) {
      return (string) $this->t('Multiple options');
    }
    if (count($labels) <= 4) {
      return (string) $this->t('Sizes @sizes', ['@sizes' => implode(', ', $labels)]);
    }
    return (string) $this->t('Multiple sizes available');
  }

}
