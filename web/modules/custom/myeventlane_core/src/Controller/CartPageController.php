<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\commerce_cart\Controller\CartController as CommerceCartController;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cart page controller: shows only the first non-empty cart.
 *
 * Commerce can return multiple carts (e.g. per order type or legacy orders).
 * This limits the page to one cart to avoid duplicate "Review your tickets"
 * blocks and confusing counts.
 */
final class CartPageController extends CommerceCartController {

  /**
   * The current store.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  protected CurrentStoreInterface $currentStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->currentStore = $container->get('commerce_store.current_store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function cartPage() {
    $build = [];
    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheContexts(['user', 'session']);

    $store = $this->currentStore->getStore();
    $store_id = $store ? (string) $store->id() : 'NULL';

    $carts = $this->cartProvider->getCarts();
    $count_before = count($carts);
    $carts = array_filter($carts, function ($cart) {
      return $cart->hasItems();
    });
    $count_after = count($carts);
    // Show only the first cart to avoid duplicate blocks on the page.
    $carts = array_slice($carts, 0, 1, TRUE);

    if (!empty($carts)) {
      foreach ($carts as $cart_id => $cart) {
        $this->getLogger('myeventlane_core')->info(
          'Cart page: cart loaded. store_id=@store, order_id=@id, item_count=@count (getCarts: @before before hasItems, @after after)',
          [
            '@store' => $store_id,
            '@id' => (string) $cart_id,
            '@count' => count($cart->getItems()),
            '@before' => (string) $count_before,
            '@after' => (string) $count_after,
          ]
        );
      }
      $cart_views = $this->getCartViews($carts);
      foreach ($carts as $cart_id => $cart) {
        $build[$cart_id] = [
          '#prefix' => '<div class="cart cart-form">',
          '#suffix' => '</div>',
          '#type' => 'view',
          '#name' => $cart_views[$cart_id],
          '#arguments' => [$cart_id],
          '#embed' => TRUE,
        ];
        $cacheable_metadata->addCacheableDependency($cart);
      }
    }
    else {
      $this->getLogger('myeventlane_core')->warning(
        'Cart page: showing empty. store_id=@store, getCarts_count=@before, after_hasItems=@after',
        [
          '@store' => $store_id,
          '@before' => (string) $count_before,
          '@after' => (string) $count_after,
        ]
      );
      $build['empty'] = [
        '#theme' => 'commerce_cart_empty_page',
      ];
    }
    $build['#cache'] = [
      'contexts' => $cacheable_metadata->getCacheContexts(),
      'tags' => $cacheable_metadata->getCacheTags(),
      'max-age' => $cacheable_metadata->getCacheMaxAge(),
    ];

    return $build;
  }

}
