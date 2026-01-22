<?php

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Generates SKUs and Titles for ticket variations (no UUID touching).
 */
final class TicketCodeGenerator {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Human-readable, unique SKU: E{event}-{slug}-{ymd}-{n?}
   */
  public function buildSku(ProductInterface $product, ProductVariationInterface $variation, string $label, int $ordinal = 0) : string {
    $event = $this->getEventFromProduct($product);
    $nid = $event?->id() ?: 0;
    $slug = $this->slugify($label);
    $ymd = $this->dateFormatter->format(time(), 'custom', 'Ymd');

    $base = sprintf('E%d-%s-%s', $nid, $slug, $ymd);
    $candidate = $ordinal > 0 ? $base . '-' . $ordinal : $base;

    $storage = $this->etm->getStorage('commerce_product_variation');
    $i = max(1, $ordinal);
    while ($storage->loadByProperties(['sku' => $candidate])) {
      $candidate = $base . '-' . $i++;
    }
    return $candidate;
  }

  /**
   * Title: "{Event} — {Label} [xxxx]" where xxxx = short uuid.
   */
  public function buildTitle(ProductInterface $product, ProductVariationInterface $variation, string $label) : string {
    $event = $this->getEventFromProduct($product);
    $event_title = $event?->label() ?: 'Event';
    $uuid = $variation->uuid() ?: Unicode::strtolower(\Drupal::service('uuid')->generate());
    $short = substr(str_replace('-', '', $uuid), 0, 4);
    return sprintf('%s — %s [%s]', $event_title, trim($label), $short);
  }

  /**
   *
   */
  public function slugify(string $label) : string {
    $s = Unicode::strtolower($label);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?: '';
    return trim($s, '-');
  }

  /**
   *
   */
  private function getEventFromProduct(ProductInterface $product) : ?NodeInterface {
    if ($product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $target = $product->get('field_event')->entity;
      return $target instanceof NodeInterface ? $target : NULL;
    }
    return NULL;
  }

}
