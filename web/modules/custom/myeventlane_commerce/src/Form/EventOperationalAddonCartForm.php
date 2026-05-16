<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Customer form: add event-linked operational add-ons to the Commerce cart.
 */
final class EventOperationalAddonCartForm extends FormBase {

  private const UI_QTY_MAX = 10;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly EventOperationalAddonBuilder $addonBuilder,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('myeventlane_commerce.event_operational_addon_builder'),
      $container->get('commerce_price.currency_formatter'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_operational_addon_cart_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return $form;
    }

    $built = $this->addonBuilder->buildForEvent($event);
    $addons = $built['addons'] ?? [];
    if ($addons === []) {
      return $form;
    }

    $form['#node'] = $event;
    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'mel-operational-addons-form';
    $form['#attached']['library'][] = 'myeventlane_commerce/operational_addons';

    $cache_tags = $event->getCacheTags();
    foreach ($built['product_ids'] ?? [] as $pid) {
      $cache_tags[] = 'commerce_product:' . $pid;
    }
    $form['#cache']['tags'] = array_values(array_unique($cache_tags));
    $form['#cache']['contexts'] = ['user', 'session', 'languages:language_interface'];

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Optional extras for this event — add to your booking, then collect on site after checkout. Quantities are not stock guarantees.'),
      '#attributes' => ['class' => ['mel-operational-addons-form__intro']],
    ];

    $form['lines'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-operational-addons-form__lines']],
    ];

    foreach ($addons as $index => $addon) {
      if (!is_array($addon)) {
        continue;
      }
      $pid = (int) ($addon['product_id'] ?? 0);
      $title = (string) ($addon['title'] ?? '');
      $operational = is_array($addon['operational'] ?? NULL) ? $addon['operational'] : [];
      $variations = is_array($addon['variations'] ?? NULL) ? $addon['variations'] : [];

      $card = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-operational-addon-card'],
          'data-product-id' => (string) $pid,
        ],
      ];

      $card['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $title !== '' ? $title : $this->t('Add-on'),
        '#attributes' => ['class' => ['mel-operational-addon-card__title']],
      ];

      if ($operational !== []) {
        $card['summary'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-operational-addon-card__summary']],
        ];
        $summary_text = trim((string) ($operational['operational_summary'] ?? ''));
        if ($summary_text !== '') {
          $card['summary']['text'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $summary_text,
            '#attributes' => ['class' => ['mel-operational-addon-card__summary-text']],
          ];
        }
        $chips = is_array($operational['operational_chips'] ?? NULL) ? $operational['operational_chips'] : [];
        if ($chips !== []) {
          $card['summary']['chips'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-operational-addon-card__chips']],
          ];
          foreach ($chips as $chip) {
            if (!is_array($chip)) {
              continue;
            }
            $label = trim((string) ($chip['label'] ?? ''));
            if ($label === '') {
              continue;
            }
            $tone = $this->chipToneClass((string) ($chip['tone'] ?? 'muted'));
            $card['summary']['chips'][] = [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $label,
              '#attributes' => [
                'class' => [
                  'mel-pill',
                  'mel-pill--operational',
                  'mel-pill--tone-' . $tone,
                ],
              ],
            ];
          }
        }
      }

      $card['variations'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-operational-addon-card__variations']],
      ];

      foreach ($variations as $v) {
        if (!is_array($v)) {
          continue;
        }
        $vid = (int) ($v['variation_id'] ?? 0);
        if ($vid < 1) {
          continue;
        }
        $v_label = (string) ($v['label'] ?? '');
        $price_number = (string) ($v['price_number'] ?? '');
        $currency = (string) ($v['price_currency_code'] ?? '');
        $price_display = '';
        if ($price_number !== '' && $currency !== '') {
          $price_display = $this->currencyFormatter->format($price_number, $currency);
        }

        $row = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-operational-addon-card__row']],
        ];
        $row['meta'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-operational-addon-card__row-meta']],
        ];
        $row['meta']['variation_title'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $v_label !== '' ? $v_label : $this->t('Option'),
          '#attributes' => ['class' => ['mel-operational-addon-card__variation-title']],
        ];
        if ($price_display !== '') {
          $row['meta']['price'] = [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $price_display,
            '#attributes' => ['class' => ['mel-operational-addon-card__price']],
          ];
        }

        $row['qty'] = [
          '#type' => 'number',
          '#title' => $this->t('Quantity for @label', ['@label' => $v_label !== '' ? $v_label : $this->t('this option')]),
          '#title_display' => 'invisible',
          '#min' => 0,
          '#max' => self::UI_QTY_MAX,
          '#step' => 1,
          '#default_value' => 0,
          '#attributes' => [
            'class' => ['mel-operational-addon-card__qty'],
            'inputmode' => 'numeric',
            'aria-label' => (string) $this->t('Quantity for @label', ['@label' => $v_label !== '' ? $v_label : $title]),
          ],
        ];

        $card['variations'][$vid] = $row;
      }

      $form['lines'][$index] = $card;
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-operational-addons-form__actions']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add extras to cart'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--xl']],
    ];

    return $form;
  }

  private function chipToneClass(string $tone): string {
    $tone = strtolower(trim($tone));
    return preg_match('/^[a-z0-9_-]{1,32}$/', $tone) ? $tone : 'muted';
  }

  /**
   * @param list<array<string, mixed>> $addons
   *
   * @return array<int, array<string, mixed>>
   */
  private function buildAllowlistFromAddons(array $addons): array {
    $map = [];
    foreach ($addons as $addon) {
      if (!is_array($addon)) {
        continue;
      }
      $product_id = (int) ($addon['product_id'] ?? 0);
      $bundle = (string) ($addon['bundle'] ?? '');
      $variations = is_array($addon['variations'] ?? NULL) ? $addon['variations'] : [];
      foreach ($variations as $v) {
        if (!is_array($v)) {
          continue;
        }
        $vid = (int) ($v['variation_id'] ?? 0);
        if ($vid < 1) {
          continue;
        }
        $map[$vid] = [
          'product_id' => $product_id,
          'bundle' => $bundle,
        ];
      }
    }
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $form['#node'] ?? NULL;
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('lines', $this->t('This form is no longer valid. Please refresh the page.'));
      return;
    }

    $fresh = $this->addonBuilder->buildForEvent($event);
    $allowlist = $this->buildAllowlistFromAddons($fresh['addons'] ?? []);

    $lines = $form_state->getValue('lines');
    if (!is_array($lines)) {
      return;
    }

    foreach ($lines as $line) {
      if (!is_array($line)) {
        continue;
      }
      $variations = $line['variations'] ?? NULL;
      if (!is_array($variations)) {
        continue;
      }
      foreach ($variations as $vid_key => $row) {
        $vid = (int) $vid_key;
        if ($vid < 1) {
          $this->loggerFactory->get('myeventlane_commerce')->warning('Operational add-on submit ignored malformed variation key @key.', ['@key' => (string) $vid_key]);
          $form_state->setErrorByName('lines', $this->t('Something went wrong with your selection. Please refresh and try again.'));
          return;
        }
        if (!isset($allowlist[$vid])) {
          $this->loggerFactory->get('myeventlane_commerce')->notice('Operational add-on validation rejected variation @vid for event @eid (not allowlisted).', [
            '@vid' => (string) $vid,
            '@eid' => (string) $event->id(),
          ]);
          $form_state->setErrorByName('lines', $this->t('One or more selected add-ons are not available for this event.'));
          return;
        }
        if (!is_array($row)) {
          continue;
        }
        $qty = (int) ($row['qty'] ?? 0);
        if ($qty < 0) {
          $form_state->setErrorByName('lines', $this->t('Quantities cannot be negative.'));
          return;
        }
        if ($qty > self::UI_QTY_MAX) {
          $form_state->setErrorByName('lines', $this->t('Please choose a smaller quantity for add-ons.'));
          return;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form['#node'] ?? NULL;
    if (!$event instanceof NodeInterface) {
      return;
    }

    $fresh = $this->addonBuilder->buildForEvent($event);
    $allowlist = $this->buildAllowlistFromAddons($fresh['addons'] ?? []);

    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $lines = $form_state->getValue('lines');
    if (!is_array($lines)) {
      return;
    }

    $to_add = [];
    foreach ($lines as $line) {
      if (!is_array($line)) {
        continue;
      }
      $variations = $line['variations'] ?? NULL;
      if (!is_array($variations)) {
        continue;
      }
      foreach ($variations as $vid => $row) {
        $vid = (int) $vid;
        if ($vid < 1 || !is_array($row)) {
          continue;
        }
        $qty = (int) ($row['qty'] ?? 0);
        if ($qty < 1) {
          continue;
        }
        if (!isset($allowlist[$vid])) {
          $this->messenger()->addError($this->t('Add-ons could not be updated. Please refresh the page.'));
          $this->loggerFactory->get('myeventlane_commerce')->error('Operational add-on submit aborted: variation @vid not allowlisted post-validate.', ['@vid' => (string) $vid]);
          return;
        }
        $to_add[$vid] = $qty;
      }
    }

    if ($to_add === []) {
      $this->messenger()->addStatus($this->t('Choose a quantity greater than zero to add extras.'));
      return;
    }

    foreach ($to_add as $variation_id => $quantity) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $variation_storage->load($variation_id);
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        $this->messenger()->addError($this->t('An add-on is no longer available.'));
        $this->loggerFactory->get('myeventlane_commerce')->notice('Operational add-on submit rejected missing or unpublished variation @vid.', ['@vid' => (string) $variation_id]);
        return;
      }

      $product = $variation->getProduct();
      if (!$product instanceof ProductInterface) {
        $this->messenger()->addError($this->t('An add-on is no longer available.'));
        return;
      }

      if (!$product->isPublished()) {
        $this->messenger()->addError($this->t('An add-on is no longer available.'));
        return;
      }

      if (!in_array($product->bundle(), OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
        $this->messenger()->addError($this->t('An add-on is not valid for this event.'));
        $this->loggerFactory->get('myeventlane_commerce')->notice('Operational add-on submit rejected bundle @bundle for variation @vid.', [
          '@bundle' => $product->bundle(),
          '@vid' => (string) $variation_id,
        ]);
        return;
      }

      if (!$product->hasField('field_event') || $product->get('field_event')->isEmpty()
        || (int) $product->get('field_event')->target_id !== (int) $event->id()) {
        $this->messenger()->addError($this->t('An add-on is not linked to this event.'));
        $this->loggerFactory->get('myeventlane_commerce')->notice('Operational add-on submit rejected event mismatch for product @pid.', ['@pid' => (string) $product->id()]);
        return;
      }

      $expected_pid = (int) ($allowlist[$variation_id]['product_id'] ?? 0);
      if ($expected_pid > 0 && (int) $product->id() !== $expected_pid) {
        $this->messenger()->addError($this->t('An add-on is not valid for this event.'));
        return;
      }

      $stores = $product->getStores();
      if ($stores === []) {
        $this->messenger()->addError($this->t('No store is configured for an add-on. Please contact the organiser.'));
        $this->loggerFactory->get('myeventlane_commerce')->error('Operational add-on submit failed: product @pid has no stores.', ['@pid' => (string) $product->id()]);
        return;
      }
      $store = reset($stores);
      if (!$store) {
        $this->messenger()->addError($this->t('No store is configured for an add-on. Please contact the organiser.'));
        return;
      }

      $cart = $this->cartProvider->getCart('default', $store)
        ?: $this->cartProvider->createCart('default', $store);

      $order_item = $this->cartManager->addEntity($cart, $variation, $quantity, TRUE);
      if ($order_item && $order_item->hasField('field_target_event')) {
        $order_item->set('field_target_event', ['target_id' => $event->id()]);
        $order_item->save();
      }
    }

    $this->messenger()->addStatus($this->t('Add-ons added to your booking.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()]));
  }

}
