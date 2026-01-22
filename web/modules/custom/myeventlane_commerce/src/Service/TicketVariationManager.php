<?php

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 *
 */
final class TicketVariationManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
  ) {}

  /**
   * Idempotently load/create a variation by SKU for a given Product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   * @param string $sku
   * @param array $values
   *   ['title' => string, 'status' => int, 'price' => \Drupal\commerce_price\Price].
   */
  public function getOrCreate(ProductInterface $product, string $sku, array $values = []) : ProductVariation {
    $storage = $this->etm->getStorage('commerce_product_variation');

    if ($found = $storage->loadByProperties(['sku' => $sku])) {
      /** @var \Drupal\commerce_product\Entity\ProductVariation $v */
      $v = reset($found);
      if ((int) $v->getProductId() !== (int) $product->id()) {
        // Update product_id by updating the variation and adding to product.
        $v->set('product_id', $product->id());
        $v->save();

        // Ensure variation is in product's variations collection.
        $variations = $product->getVariations();
        if (!in_array($v->id(), array_map(function ($var) {
          return $var->id();
        }, $variations->toArray()))) {
          $variations[] = $v;
          $product->set('variations', $variations);
          $product->save();
        }
      }
      return $v;
    }

    $data = [
      'type' => 'ticket',
      'sku' => $sku,
      'title' => $values['title'] ?? 'Ticket',
      'status' => $values['status'] ?? 1,
      'product_id' => $product->id(),
    ];
    if (!empty($values['price']) && $values['price'] instanceof Price) {
      $data['price'] = $values['price'];
    }

    /** @var \Drupal\commerce_product\Entity\ProductVariation $v */
    $v = ProductVariation::create($data);
    $v->save();

    // Add variation to product's variations collection.
    $variations = $product->getVariations();
    $variations[] = $v;
    $product->set('variations', $variations);
    $product->save();

    return $v;
  }

}
