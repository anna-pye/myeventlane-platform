<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\OrderItemStorageInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Alternative boost purchase form with plan-style layout.
 */
final class BoostPurchaseForm extends FormBase {

  /**
   * The event node.
   */
  protected ?NodeInterface $event = NULL;

  /**
   * Whether the event is currently boosted.
   */
  protected bool $isBoosted = FALSE;

  /**
   * Available plans.
   *
   * @var array<int, array{id: int, label: string, days: int, price: string}>
   */
  protected array $plans = [];

  /**
   * Constructs a BoostPurchaseForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_price\CurrencyFormatter $currencyFormatter
   *   The currency formatter.
   * @param \Drupal\commerce_cart\CartManagerInterface $cartManager
   *   The cart manager.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly CartManagerInterface $cartManager,
    private readonly CartProviderInterface $cartProvider,
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
      $container->get('logger.channel.myeventlane_boost'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_boost_purchase_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node.
   * @param array $variations
   *   Pre-loaded variations array.
   * @param bool $is_boosted
   *   Whether the event is already boosted.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?NodeInterface $event = NULL,
    array $variations = [],
    bool $is_boosted = FALSE,
  ): array {
    $this->event = $event;
    $this->isBoosted = $is_boosted;

    $this->plans = $this->normalizePlans($variations);
    if (empty($this->plans)) {
      $this->messenger()->addError($this->t('No Boost plans available. Please contact an administrator.'));
      return $form;
    }

    $defaultVid = (string) array_key_first($this->plans);
    $chosen = (string) ($form_state->getValue('variation_id') ?? $defaultVid);

    $form['event_id'] = ['#type' => 'hidden', '#value' => (int) ($this->event?->id() ?? 0)];
    $form['is_boosted'] = ['#type' => 'hidden', '#value' => (int) $this->isBoosted];

    $form['card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['boost-card', 'mel-card']],
    ];
    $form['card']['list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['boost-list']],
    ];

    foreach ($this->plans as $vid => $info) {
      $rowKey = 'row_' . $vid;

      $form['card']['list'][$rowKey] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['boost-row']],
      ];

      $form['card']['list'][$rowKey]['icon'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->iconEmoji((int) $info['days']),
        '#attributes' => ['class' => ['boost-row__icon']],
      ];

      $form['card']['list'][$rowKey]['text'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['boost-row__text']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $info['label'],
          '#attributes' => ['class' => ['boost-row__title']],
        ],
        'desc' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->isBoosted ? $this->t('Extends your current boost.') : $this->t('Featured placement + badge.'),
          '#attributes' => ['class' => ['boost-row__desc']],
        ],
      ];

      $form['card']['list'][$rowKey]['price'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $info['price'],
        '#attributes' => ['class' => ['boost-row__price']],
      ];

      $form['card']['list'][$rowKey]['radio'] = [
        '#type' => 'radio',
        '#title' => $info['label'],
        '#title_display' => 'invisible',
        '#return_value' => (string) $vid,
        '#default_value' => ($chosen === (string) $vid) ? (string) $vid : NULL,
        '#parents' => ['variation_id'],
        '#attributes' => ['class' => ['boost-row__radio']],
      ];
    }

    $form['card']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['boost-footer']],
    ];
    if ($this->event) {
      $form['card']['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('entity.node.canonical', ['node' => $this->event->id()]),
        '#attributes' => ['class' => ['button', 'button--ghost']],
      ];
    }
    $form['card']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->isBoosted ? $this->t('Extend Boost') : $this->t('Boost Event'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('variation_id')) {
      $form_state->setErrorByName('variation_id', $this->t('Please choose a plan.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vid = (int) $form_state->getValue('variation_id');
    $nid = (int) $form_state->getValue('event_id');
    $uid = (int) $this->currentUser()->id();

    $this->logger->notice('Selected variation @vid for event @nid by user @uid', [
      '@vid' => $vid,
      '@nid' => $nid,
      '@uid' => $uid,
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if ($variation === NULL || $node === NULL) {
      $this->logger->error('Invalid submit: variation or node missing. vid=@vid nid=@nid', [
        '@vid' => $vid,
        '@nid' => $nid,
      ]);
      $this->messenger()->addError($this->t('Invalid selection.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // Resolve store.
    $product = $variation->getProduct();
    $stores = $product instanceof ProductInterface ? $product->getStores() : [];
    if (empty($stores)) {
      $this->messenger()->addError($this->t('This Boost product is not assigned to any store.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $nid]);
      return;
    }
    $store = reset($stores);

    // Get or create cart.
    $account = $this->currentUser();
    $cart = $this->cartProvider->getCart('default', $store, $account)
      ?: $this->cartProvider->createCart('default', $store, $account);

    // Create order item.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    if (!$orderItemStorage instanceof OrderItemStorageInterface) {
      $this->messenger()->addError($this->t('Commerce order item storage not available.'));
      return;
    }

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $orderItem */
    $orderItem = $orderItemStorage->createFromPurchasableEntity($variation);

    // Check if boost order item type exists.
    $oitExists = (bool) $this->entityTypeManager->getStorage('commerce_order_item_type')->load('boost');
    if ($oitExists && $orderItem->bundle() !== 'boost') {
      $orderItem->set('type', 'boost');
    }

    $orderItem->setQuantity('1');
    if ($orderItem->hasField('field_target_event')) {
      $orderItem->set('field_target_event', ['target_id' => $nid]);
    }
    $orderItem->save();

    $this->cartManager->addOrderItem($cart, $orderItem);

    $checkoutUrl = Url::fromRoute('commerce_checkout.form', ['commerce_order' => $cart->id()]);
    $this->logger->notice('Redirecting to checkout order id: @oid, url: @url', [
      '@oid' => $cart->id(),
      '@url' => $checkoutUrl->toString(),
    ]);
    $form_state->setRedirectUrl($checkoutUrl);
  }

  /**
   * Build plan list from provided variations, discovery, or legacy SKUs.
   *
   * @param array $variations
   *   Pre-loaded variations.
   *
   * @return array<int, array{id: int, label: string, days: int, price: string}>
   *   The normalized plans.
   */
  private function normalizePlans(array $variations): array {
    $plans = [];

    // Case 1: use provided variations from the controller.
    if (!empty($variations)) {
      foreach ($variations as $vid => $info) {
        $plans[(int) $vid] = [
          'id' => (int) $vid,
          'label' => (string) ($info['label'] ?? 'Boost Plan'),
          'days' => (int) ($info['days'] ?? 7),
          'price' => (string) ($info['price'] ?? ''),
        ];
      }
      return $plans;
    }

    $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');

    // Case 2: discover published boost_duration variations.
    $vids = $variationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'boost_duration')
      ->condition('status', 1)
      ->execute();

    if (!empty($vids)) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface[] $vars */
      $vars = $variationStorage->loadMultiple($vids);
      foreach ($vars as $v) {
        $days = (int) ($v->get('field_boost_days')->value ?? 0) ?: 7;
        $priceText = '';
        if ($price = $v->getPrice()) {
          $priceText = $this->currencyFormatter->format(
            $price->getNumber(),
            $price->getCurrencyCode(),
            [
              'currency_display' => 'symbol',
              'minimum_fraction_digits' => 2,
              'maximum_fraction_digits' => 2,
              'locale' => 'en-AU',
            ]
          );
        }
        $label = $v->label();
        $plans[(int) $v->id()] = [
          'id' => (int) $v->id(),
          'label' => $label ?: (string) $this->t('Boost @d Days', ['@d' => $days]),
          'days' => $days,
          'price' => $priceText,
        ];
      }
      if (!empty($plans)) {
        return $plans;
      }
    }

    // Case 3: legacy SKU fallback (boost_7d etc.).
    $all = $variationStorage->loadMultiple();
    foreach ($all as $v) {
      if (!$v instanceof ProductVariationInterface) {
        continue;
      }
      $sku = (string) $v->getSku();
      if (preg_match('/^boost_(\d+)d$/i', $sku, $m)) {
        $days = (int) $m[1];
        $priceText = '';
        if ($price = $v->getPrice()) {
          $priceText = $this->currencyFormatter->format(
            $price->getNumber(),
            $price->getCurrencyCode(),
            [
              'currency_display' => 'symbol',
              'minimum_fraction_digits' => 2,
              'maximum_fraction_digits' => 2,
              'locale' => 'en-AU',
            ]
          );
        }
        $plans[(int) $v->id()] = [
          'id' => (int) $v->id(),
          'label' => (string) $this->t('Boost @d Days', ['@d' => $days]),
          'days' => $days,
          'price' => $priceText,
        ];
      }
    }

    return $plans;
  }

  /**
   * Get an emoji icon for the given number of days.
   *
   * @param int $days
   *   The number of days.
   *
   * @return string
   *   The emoji.
   */
  private function iconEmoji(int $days): string {
    if ($days >= 30) {
      return '❤️';
    }
    if ($days >= 14) {
      return '📣';
    }
    return '📊';
  }

}
