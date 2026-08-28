<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\commerce_cart\Controller\CartController as CommerceCartController;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_store\CurrentStoreInterface;
use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cart page controller: shows the cart from the latest add-to-cart journey.
 *
 * Commerce can return multiple carts (e.g. per order type or legacy orders).
 * This limits the page to one cart to avoid duplicate "Review your tickets"
 * blocks and confusing counts. The booking form records its cart in the
 * session so an event in another store does not redirect to an older cart.
 */
final class CartPageController extends CommerceCartController {

  /**
   * The current store.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  protected CurrentStoreInterface $currentStore;

  protected RequestStack $requestStack;

  protected CurrencyFormatterInterface $currencyFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->currentStore = $container->get('commerce_store.current_store');
    $instance->requestStack = $container->get('request_stack');
    $instance->currencyFormatter = $container->get('commerce_price.currency_formatter');
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
    $request = $this->requestStack->getCurrentRequest();
    $preferred_cart_id = $request?->hasSession()
      ? (int) $request->getSession()->get('myeventlane_preferred_cart_id', 0)
      : 0;
    if ($preferred_cart_id > 0 && isset($carts[$preferred_cart_id])) {
      $carts = [$preferred_cart_id => $carts[$preferred_cart_id]];
    }
    else {
      // Preserve the established fallback when no booking journey selected a cart.
      $carts = array_slice($carts, 0, 1, TRUE);
    }

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
          '#type' => 'container',
          '#attributes' => ['class' => ['cart', 'cart-form']],
          'view' => [
            '#type' => 'view',
            '#name' => $cart_views[$cart_id],
            '#arguments' => [$cart_id],
            '#embed' => TRUE,
          ],
          'bundle_controls' => $this->buildTicketBundleControls($cart),
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

  /**
   * Builds one atomic removal link for each ticket bundle in the cart.
   */
  private function buildTicketBundleControls($cart): array {
    $bundles = [];
    foreach ($cart->getItems() as $order_item) {
      $instance_id = trim((string) $order_item->getData('mel_ticket_bundle_instance', ''));
      if ($instance_id === '') {
        continue;
      }
      $bundles[$instance_id] = [
        'name' => trim((string) $order_item->getData('mel_ticket_bundle_name', 'Ticket bundle')),
        'quantity' => max(1, (int) $order_item->getData('mel_ticket_bundle_quantity', 1)),
        'price_number' => $bundles[$instance_id]['price_number'] ?? '0',
        'currency' => $bundles[$instance_id]['currency'] ?? '',
      ];
      $gross_unit = trim((string) $order_item->getData('mel_ticket_bundle_gross_unit_price', ''));
      $currency = strtoupper(trim((string) $order_item->getData('mel_ticket_bundle_currency', '')));
      if (is_numeric($gross_unit) && preg_match('/^[A-Z]{3}$/', $currency)) {
        $line_total = Calculator::multiply($gross_unit, (string) $order_item->getQuantity(), 6);
        $bundles[$instance_id]['price_number'] = Calculator::add($bundles[$instance_id]['price_number'], $line_total, 6);
        $bundles[$instance_id]['currency'] = $currency;
      }
    }
    if ($bundles === []) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-cart-ticket-bundles']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Ticket bundles in your cart'),
      ],
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The included ticket quantities stay together. Remove the bundle and choose it again if you need a different number.'),
      ],
    ];
    foreach ($bundles as $instance_id => $bundle) {
      $key = 'bundle_' . substr(hash('sha256', $instance_id), 0, 12);
      $summary = $this->t('@quantity × @bundle', [
        '@quantity' => $bundle['quantity'],
        '@bundle' => $bundle['name'],
      ]);
      if ($bundle['currency'] !== '' && Calculator::compare($bundle['price_number'], '0') > 0) {
        $summary = $this->t('@bundle_summary — @price', [
          '@bundle_summary' => $summary,
          '@price' => $this->currencyFormatter->format($bundle['price_number'], $bundle['currency']),
        ]);
      }
      $build[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-cart-ticket-bundle-control']],
        'summary' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $summary,
        ],
        'remove' => [
          '#type' => 'link',
          '#title' => $this->t('Remove bundle'),
          '#url' => Url::fromRoute('myeventlane_commerce.ticket_bundle_remove', [
            'commerce_order' => (int) $cart->id(),
            'bundle_instance' => $instance_id,
          ]),
          '#attributes' => ['class' => ['button', 'button--small', 'mel-btn', 'mel-btn--secondary']],
        ],
      ];
    }
    return $build;
  }

}
