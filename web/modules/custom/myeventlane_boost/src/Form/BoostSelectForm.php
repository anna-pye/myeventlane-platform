<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Service\StripeChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Boost selection form allowing users to choose a boost duration.
 */
final class BoostSelectForm extends FormBase {

  /**
   * Ideal default duration (days) when we suggest a first-time boost.
   */
  private const IDEAL_DAYS_DEFAULT = 7;

  /**
   * Ideal duration (days) when performance suggests "boost again".
   *
   * Resolved against actual catalog via resolveRecommendedDaysForCatalog().
   */
  private const IDEAL_DAYS_BOOST_AGAIN = 10;

  /**
   * Constructs a BoostSelectForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_price\CurrencyFormatter $currencyFormatter
   *   The currency formatter.
   * @param \Drupal\commerce_cart\CartManagerInterface $cartManager
   *   The cart manager.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Drupal\Core\Access\AccessManagerInterface $accessManager
   *   The access manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly CartManagerInterface $cartManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly StripeChecker $stripeChecker,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('commerce_price.currency_formatter'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('access_manager'),
      $container->get('myeventlane_boost.stripe_checker'),
      $container->get('logger.channel.myeventlane_boost'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_boost_select_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?array $boost_performance = NULL): array {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      $form['#markup'] = $this->t('This page requires an event.');
      return $form;
    }

    // Load published products of type "boost_upgrade".
    // Prefer "Event Boost" product, otherwise use the most recent one.
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $pids = $productStorage->getQuery()
      ->condition('type', 'boost_upgrade')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->execute();

    // If multiple products exist, prefer "Event Boost" or the most recent.
    if (count($pids) > 1) {
      $products = $productStorage->loadMultiple($pids);
      $preferred = NULL;
      foreach ($products as $pid => $product) {
        if ($product->label() === 'Event Boost') {
          $preferred = $pid;
          break;
        }
      }
      if ($preferred) {
        $pids = [$preferred];
      }
      else {
        // Use the most recent (first in sorted list).
        $pids = [reset($pids)];
      }
    }

    if (empty($pids)) {
      $form['empty'] = ['#markup' => $this->t('No boost options are available right now.')];
      return $form;
    }

    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $productStorage->loadMultiple($pids);

    /** @var list<array{variation: \Drupal\commerce_product\Entity\ProductVariationInterface, days: int, priceStr: string}> $candidates */
    $candidates = [];

    foreach ($products as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }

      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations */
      $variations = $product->getVariations();
      foreach ($variations as $variation) {
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }

        // Only include published variations with valid data.
        if (!$variation->isPublished()) {
          continue;
        }

        $days = (int) ($variation->get('field_boost_days')->value ?? 0);
        $price = $variation->getPrice();

        // Skip variations without valid days or price.
        if ($days <= 0 || !$price) {
          continue;
        }

        $priceStr = $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());

        $candidates[] = [
          'variation' => $variation,
          'days' => $days,
          'priceStr' => $priceStr,
        ];
      }
    }

    if ($candidates === []) {
      $form['empty'] = ['#markup' => $this->t('No valid boost variations found.')];
      return $form;
    }

    $uniqueDays = array_values(array_unique(array_column($candidates, 'days')));
    sort($uniqueDays, SORT_NUMERIC);

    $recommendedDays = $this->resolveRecommendedDaysForCatalog($boost_performance, $uniqueDays);

    $options = [];
    $rows = [];

    foreach ($candidates as $c) {
      $variation = $c['variation'];
      $days = $c['days'];
      $priceStr = $c['priceStr'];

      $options[$variation->id()] = $this->t('@days days — @price', [
        '@days' => $days,
        '@price' => $priceStr,
      ]);

      $subtitle = match ($days) {
        7 => $this->t('Immediate visibility boost'),
        10 => $this->t('Sustain momentum'),
        30 => $this->t('Maximise long-term reach'),
        default => $this->t('Featured visibility for @d days', ['@d' => $days]),
      };

      $desc = match ($days) {
        7 => $this->t('Get featured across discovery and search'),
        10 => $this->t('Keep momentum going through the week'),
        30 => $this->t('Maximum exposure for longer events'),
        default => $this->t('Keep your event featured for @d days.', ['@d' => $days]),
      };

      $isRecommended = $recommendedDays !== NULL && $days === $recommendedDays;
      $cardClasses = ['boost-plan-card'];
      if ($isRecommended) {
        $cardClasses[] = 'boost-plan-card--recommended';
      }

      $rows[$variation->id()] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $cardClasses,
          'data-variation-id' => $variation->id(),
          'data-option-days' => (string) $days,
          'role' => 'option',
          'tabindex' => '0',
        ],
        'badge' => $isRecommended ? [
          '#markup' => '<span class="boost-plan-card__badge">' . $this->t('Recommended') . '</span>',
        ] : [],
        'check' => [
          '#markup' => '<span class="boost-plan-card__check" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="10" fill="currentColor"/><path d="m6 10 2.5 2.5 5.5-5.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>',
        ],
        'icon' => [
          '#markup' => '<div class="boost-plan-card__icon" aria-hidden="true"><svg class="icon-bolt" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M13 2 5 14h6l-1 8 8-12h-6l1-8Z" fill="currentColor"/></svg></div>',
        ],
        'days' => [
          '#markup' => '<h3 class="boost-plan-card__days">' . $this->t('@d days', ['@d' => $days]) . '</h3>',
        ],
        'subtitle' => [
          '#markup' => '<p class="boost-plan-card__subtitle">' . $subtitle . '</p>',
        ],
        'desc' => [
          '#markup' => '<p class="boost-plan-card__desc">' . $desc . '</p>',
        ],
        'price' => [
          '#markup' => '<p class="boost-plan-card__price">' . $priceStr . '</p>',
        ],
      ];
    }

    // Hidden radios for accessibility and form submission.
    $defaultVariationId = (int) array_key_first($options);
    if ($recommendedDays !== NULL) {
      foreach ($candidates as $c) {
        if ($c['days'] === $recommendedDays) {
          $defaultVariationId = (int) $c['variation']->id();
          break;
        }
      }
    }

    $form['choices'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose a boost duration'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $defaultVariationId,
      '#attributes' => [
        'class' => ['mel-boost-radios', 'visually-hidden'],
        'required' => 'required',
      ],
    ];

    // Expose resolved recommendation for scripts (e.g. future defaults); matches catalog.
    if ($recommendedDays !== NULL) {
      $form['#attached']['drupalSettings']['melBoost']['recommendedDays'] = $recommendedDays;
    }

    // Add form class for JavaScript targeting.
    $form['#attributes']['class'][] = 'myeventlane-boost-select-form';

    // Styled grid for visual display.
    $form['styled_list'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['boost-plan-grid'],
        'role' => 'listbox',
        'aria-label' => $this->t('Boost duration options'),
      ],
    ];
    foreach ($rows as $vid => $row) {
      $form['styled_list'][$vid] = $row;
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['boost-plan-actions']],
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Boost my event now'),
        '#attributes' => ['class' => ['button', 'button--primary', 'boost-plan-actions__submit']],
      ],
      'trust' => [
        '#type' => 'markup',
        '#markup' => '<ul class="boost-plan-trust" aria-label="' . $this->t('Boost purchase assurances') . '">'
          . '<li><svg class="icon-bolt" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 5 14h6l-1 8 8-12h-6l1-8Z" fill="currentColor"/></svg>' . $this->t('Instant activation') . '</li>'
          . '<li><svg class="icon-shield" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5 2.5 3.5v4.5c0 3.5 2.3 6.2 5.5 7 3.2-.8 5.5-3.5 5.5-7V3.5L8 1.5Z" stroke="currentColor" stroke-width="1.2" fill="none"/></svg>' . $this->t('Secure payment') . '</li>'
          . '<li><svg class="icon-chart" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1" y="9" width="3" height="6" rx=".5" fill="currentColor"/><rect x="6" y="5" width="3" height="10" rx=".5" fill="currentColor"/><rect x="11" y="2" width="3" height="13" rx=".5" fill="currentColor"/></svg>' . $this->t('Track performance') . '</li>'
          . '</ul>',
        '#weight' => 10,
      ],
    ];

    $form['event_nid'] = ['#type' => 'value', '#value' => $node->id()];

    return $form;
  }

  /**
   * Picks which duration (days) gets the "Recommended" badge for this catalog.
   *
   * Uses ideal targets (7 / 10) when those SKUs exist; otherwise the closest
   * available duration so the badge always reflects a real purchasable option.
   *
   * @param array<string, mixed>|null $boost_performance
   *   Payload from BoostPerformanceService (may include action_state).
   * @param array<int> $available_days
   *   Unique positive day counts from published variations.
   *
   * @return int|null
   *   Days value to mark as recommended, or NULL if the catalog is empty.
   */
  private function resolveRecommendedDaysForCatalog(?array $boost_performance, array $available_days): ?int {
    $available_days = array_values(array_unique(array_filter(
      array_map(static fn ($d): int => (int) $d, $available_days),
      static fn (int $d): bool => $d > 0,
    )));
    sort($available_days, SORT_NUMERIC);
    if ($available_days === []) {
      return NULL;
    }

    $is_boost_again = is_array($boost_performance)
      && ($boost_performance['action_state'] ?? '') === 'boost_again';
    $ideal = $is_boost_again ? self::IDEAL_DAYS_BOOST_AGAIN : self::IDEAL_DAYS_DEFAULT;

    if (in_array($ideal, $available_days, TRUE)) {
      return $ideal;
    }

    $best = $available_days[0];
    $best_dist = PHP_INT_MAX;
    foreach ($available_days as $d) {
      $dist = abs($d - $ideal);
      if ($dist < $best_dist) {
        $best_dist = $dist;
        $best = $d;
      }
      elseif ($dist === $best_dist && $d < $best) {
        // Tie: prefer shorter duration (avoid nudging toward longest SKU).
        $best = $d;
      }
    }

    return $best;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $choices = $form_state->getValue('choices');
    if (empty($choices)) {
      $form_state->setErrorByName('choices', $this->t('Please select a boost option.'));
      return;
    }

    // Verify the selected variation exists and is valid.
    $variationId = (int) $choices;
    if ($variationId <= 0) {
      $form_state->setErrorByName('choices', $this->t('Invalid boost option selected.'));
      return;
    }

    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variationId);
    if (!$variation || !$variation->isPublished()) {
      $form_state->setErrorByName('choices', $this->t('The selected boost option is no longer available.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->logger->notice('Boost form submission started');

      $account = $this->currentUser();
      if (!$account->hasPermission('administer myeventlane')
        && !$this->stripeChecker->isConnected($account)) {
        $this->messenger()->addWarning($this->t('Connect Stripe to continue boosting this event.'));
        $nid = (int) $form_state->getValue('event_nid');
        if ($nid > 0) {
          $form_state->setRedirect('myeventlane_boost.boost_page', ['node' => $nid]);
        }
        else {
          $form_state->setRebuild();
        }
        return;
      }

      $nid = (int) $form_state->getValue('event_nid');
      $variationId = (int) $form_state->getValue('choices');

      $this->logger->notice('Form values: nid=@nid, variationId=@vid', [
        '@nid' => $nid,
        '@vid' => $variationId,
      ]);

      if ($nid <= 0 || $variationId <= 0) {
        $this->logger->warning('Form submission failed: invalid values');
        $this->messenger()->addError($this->t('Please select a boost option.'));
        $form_state->setRebuild();
        return;
      }

      /** @var \Drupal\node\NodeInterface|null $node */
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variationId);

      if (!$node instanceof NodeInterface || !$variation instanceof ProductVariationInterface) {
        $this->messenger()->addError($this->t('Invalid selection.'));
        $form_state->setRedirect('<front>');
        return;
      }

      // Resolve store from the parent product.
      $store = $this->resolveStoreFromVariation($variation);
      if ($store === NULL) {
        $this->messenger()->addError($this->t('No store available for this product.'));
        $form_state->setRedirect('<front>');
        return;
      }

      // Get/create cart for the current user in that store.
      $account = $this->currentUser();
      $cart = $this->cartProvider->getCart('default', $store, $account)
        ?: $this->cartProvider->createCart('default', $store, $account);

      if (!$cart) {
        $this->messenger()->addError($this->t('Unable to create cart. Please try again.'));
        $form_state->setRebuild();
        return;
      }

      // Let Commerce create the correct order-item bundle and fields.
      $orderItem = $this->cartManager->addEntity($cart, $variation, '1', TRUE);

      if (!$orderItem) {
        $this->messenger()->addError($this->t('Unable to add item to cart. Please try again.'));
        $form_state->setRebuild();
        return;
      }

      // Set the target event on the order item if the field exists.
      if ($orderItem->hasField('field_target_event')) {
        $orderItem->set('field_target_event', ['target_id' => $node->id()]);
      }
      if ($orderItem->hasField('field_boost_days_purchased')) {
        $days = (int) ($variation->get('field_boost_days')->value ?? 0);
        if ($days > 0) {
          $orderItem->set('field_boost_days_purchased', $days);
        }
      }
      if ($orderItem->hasField('field_target_event') || $orderItem->hasField('field_boost_days_purchased')) {
        $orderItem->save();
      }

      // Prefer checkout only when access is granted for this account/order.
      $checkoutAccess = $this->accessManager->checkNamedRoute('commerce_checkout.form', [
        'commerce_order' => $cart->id(),
      ], $this->currentUser(), TRUE);

      $this->logger->notice('Form submission successful, processing redirect. Cart ID: @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
      if ($checkoutAccess->isAllowed()) {
        $form_state->setRedirectUrl(Url::fromRoute('commerce_checkout.form', [
          'commerce_order' => $cart->id(),
        ]));
        return;
      }

      // Avoid hard 403 checkout failures when account/order context mismatches.
      $this->logger->warning('Checkout access denied for cart @cart_id and user @uid; redirecting back to boost page.', [
        '@cart_id' => $cart->id(),
        '@uid' => $this->currentUser()->id(),
      ]);
      $this->messenger()->addWarning($this->t('Complete Stripe connection to continue boosting this event.'));
      $form_state->setRedirect('myeventlane_boost.boost_page', ['node' => $node->id()]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred: @message', ['@message' => $e->getMessage()]));
      $this->logger->error('Boost form submission error: @message', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $form_state->setRebuild();
    }
  }

  /**
   * Resolve a store for a variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store, or NULL if none found.
   */
  private function resolveStoreFromVariation(ProductVariationInterface $variation): ?StoreInterface {
    $product = $variation->getProduct();
    if ($product instanceof ProductInterface) {
      $stores = $product->getStores();
      if (!empty($stores)) {
        return reset($stores);
      }
    }

    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    if (method_exists($storeStorage, 'loadDefault')) {
      return $storeStorage->loadDefault();
    }

    return NULL;
  }

}
