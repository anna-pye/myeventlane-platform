<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Boost selection form allowing users to choose a boost duration.
 */
final class BoostSelectForm extends FormBase {

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
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly CartManagerInterface $cartManager,
    private readonly CartProviderInterface $cartProvider,
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
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
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

    $form['#prefix'] = '<div class="boost-card">';
    $form['#suffix'] = '</div>';

    if (empty($pids)) {
      $form['empty'] = ['#markup' => $this->t('No boost options are available right now.')];
      return $form;
    }

    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $productStorage->loadMultiple($pids);

    $options = [];
    $rows = [];

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

        $options[$variation->id()] = $this->t('@days days — @price', [
          '@days' => $days,
          '@price' => $priceStr,
        ]);

        $rows[$variation->id()] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['boost-row'],
            'data-variation-id' => $variation->id(),
            'role' => 'option',
            'tabindex' => '0',
          ],
          'icon' => [
            '#markup' => '<div class="boost-row__icon">⚡️</div>',
          ],
          'text' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['boost-row__text']],
            'title' => [
              '#markup' => '<div class="boost-row__title">' . $this->t('@d days', ['@d' => $days]) . '</div>',
            ],
            'desc' => [
              '#markup' => '<div class="boost-row__desc">' . $this->t('Keep your event featured for @d days.', ['@d' => $days]) . '</div>',
            ],
          ],
          'price' => [
            '#markup' => '<div class="boost-row__price">' . $priceStr . '</div>',
          ],
          'radio_indicator' => [
            '#markup' => '<div class="boost-row__radio-indicator" aria-hidden="true" role="presentation"><span class="boost-row__radio-checkmark">✓</span></div>',
          ],
        ];
      }
    }

    if (empty($options)) {
      $form['empty'] = ['#markup' => $this->t('No valid boost variations found.')];
      return $form;
    }

    // Hidden radios for accessibility and form submission.
    $form['choices'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose a boost duration'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => !empty($options) ? array_key_first($options) : NULL,
      '#attributes' => [
        'class' => ['mel-boost-radios', 'visually-hidden'],
        'required' => 'required',
      ],
    ];
    
    // Add form class for JavaScript targeting.
    $form['#attributes']['class'][] = 'myeventlane-boost-select-form';

    // Styled list for visual display.
    $form['styled_list'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['boost-list'],
        'role' => 'listbox',
        'aria-label' => $this->t('Boost duration options'),
      ],
    ];
    foreach ($rows as $vid => $row) {
      $form['styled_list'][$vid] = $row;
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['boost-footer']],
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Continue to checkout'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    $form['event_nid'] = ['#type' => 'value', '#value' => $node->id()];

    return $form;
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
      \Drupal::logger('myeventlane_boost')->notice('Boost form submission started');
      
      $nid = (int) $form_state->getValue('event_nid');
      $variationId = (int) $form_state->getValue('choices');

      \Drupal::logger('myeventlane_boost')->notice('Form values: nid=@nid, variationId=@vid', [
        '@nid' => $nid,
        '@vid' => $variationId,
      ]);

      if ($nid <= 0 || $variationId <= 0) {
        \Drupal::logger('myeventlane_boost')->warning('Form submission failed: invalid values');
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

      // Get/create cart for that store.
      $cart = $this->cartProvider->getCart('default', $store)
        ?: $this->cartProvider->createCart('default', $store);

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
        $orderItem->save();
      }

      // Redirect to cart page.
      \Drupal::logger('myeventlane_boost')->notice('Form submission successful, redirecting to cart. Cart ID: @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
      $form_state->setRedirect('commerce_cart.page');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred: @message', ['@message' => $e->getMessage()]));
      \Drupal::logger('myeventlane_boost')->error('Boost form submission error: @message', [
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
