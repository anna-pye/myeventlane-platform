<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Customer form: add event-linked Event Extras to the Commerce cart.
 */
final class EventOperationalAddonCartForm extends FormBase {

  private const UI_QTY_MAX = 10;

  /**
   * Promoted protected properties (not private readonly).
   *
   * FormBase serializes forms via DependencySerializationTrait; private readonly
   * properties are not reliably rehydrated on cache rebuild.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CartProviderInterface $cartProvider,
    protected CartManagerInterface $cartManager,
    protected EventOperationalAddonBuilder $addonBuilder,
    protected CurrencyFormatter $currencyFormatter,
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
    $form['#attributes']['class'][] = 'mel-event-extras-form';
    $form['#attached']['library'][] = 'myeventlane_commerce/event_extras_booking';

    $cache_tags = $event->getCacheTags();
    foreach ($built['product_ids'] ?? [] as $pid) {
      $cache_tags[] = 'commerce_product:' . $pid;
    }
    $form['#cache']['tags'] = array_values(array_unique($cache_tags));
    $form['#cache']['contexts'] = ['user', 'session', 'languages:language_interface'];

    $form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Add something extra'),
      '#attributes' => ['class' => ['mel-event-extras-form__heading']],
    ];

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Optional Event extras for this booking — collect on site after checkout.'),
      '#attributes' => ['class' => ['mel-event-extras-form__intro']],
    ];

    $form['lines'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-form__cards']],
    ];

    foreach ($addons as $index => $addon) {
      if (!is_array($addon)) {
        continue;
      }
      $form['lines'][$index] = $this->buildAddonCard($addon, (int) $index);
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-event-extras-form__actions']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add extras to cart'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--xl', 'mel-event-extras-form__submit']],
    ];

    return $form;
  }

  /**
   * @param array<string, mixed> $addon
   *
   * @return array<string, mixed>
   */
  private function buildAddonCard(array $addon, int $index): array {
    $pid = (int) ($addon['product_id'] ?? 0);
    $title = (string) ($addon['title'] ?? '');
    $short = trim((string) ($addon['short_description'] ?? ''));
    $pickup = trim((string) ($addon['pickup_note'] ?? ''));
    $gallery = is_array($addon['gallery_images'] ?? NULL) ? $addon['gallery_images'] : [];
    $primary = is_array($addon['primary_image'] ?? NULL) ? $addon['primary_image'] : [];
    $size_options = is_array($addon['size_options'] ?? NULL) ? $addon['size_options'] : [];
    $requires_size = !empty($addon['requires_size_selection']);
    $default_vid = (int) ($addon['default_variation_id'] ?? 0);

    $price_display = $this->resolveDisplayPrice($addon, $size_options);

    $card = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-extra-card'],
        'data-product-id' => (string) $pid,
        'data-requires-size' => $requires_size ? '1' : '0',
        'data-default-variation' => $default_vid > 0 ? (string) $default_vid : '',
      ],
    ];

    $card['product_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $pid,
    ];

    $card['media'] = $this->buildGalleryRenderArray($gallery, $primary, (string) ($addon['image_alt'] ?? $title));

    $card['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extra-card__body']],
    ];
    $card['body']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $title !== '' ? $title : $this->t('Event extra'),
      '#attributes' => ['class' => ['mel-event-extra-card__title']],
    ];
    if ($short !== '') {
      $card['body']['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $short,
        '#attributes' => ['class' => ['mel-event-extra-card__description']],
      ];
    }
    if ($pickup !== '') {
      $card['body']['pickup'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $pickup,
        '#attributes' => ['class' => ['mel-event-extra-card__pickup']],
      ];
    }
    if ($price_display !== '') {
      $card['body']['price'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $price_display,
        '#attributes' => ['class' => ['mel-event-extra-card__price']],
      ];
    }

    $chips = is_array($addon['chips'] ?? NULL) ? $addon['chips'] : [];
    if ($chips !== []) {
      $card['body']['chips'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extra-card__chips']],
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
        $card['body']['chips'][] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => [
            'class' => [
              'mel-pill',
              'mel-pill--extra',
              'mel-pill--tone-' . $tone,
            ],
          ],
        ];
      }
    }

    if ($requires_size && $size_options !== []) {
      $card['size_fieldset'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extra-card__sizes']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Size'),
          '#attributes' => ['class' => ['mel-event-extra-card__sizes-label']],
        ],
      ];
      $card['selected_size'] = [
        '#type' => 'radios',
        '#title' => $this->t('Size'),
        '#title_display' => 'invisible',
        '#options' => $this->sizeRadioOptions($size_options),
        '#default_value' => '',
        '#attributes' => ['class' => ['mel-event-extra-card__size-radios']],
      ];
    }
    else {
      $card['selected_size'] = [
        '#type' => 'hidden',
        '#value' => '',
      ];
    }

    $card['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#title_display' => 'invisible',
      '#min' => 0,
      '#max' => self::UI_QTY_MAX,
      '#step' => 1,
      '#default_value' => 0,
      '#attributes' => [
        'class' => ['mel-event-extra-card__qty-input'],
        'inputmode' => 'numeric',
        'aria-label' => (string) $this->t('Quantity for @title', ['@title' => $title !== '' ? $title : $this->t('this extra')]),
      ],
    ];

    $card['qty_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extra-card__qty']],
      'decrease' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => Markup::create('&minus;'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['mel-event-extra-card__qty-btn', 'mel-event-extra-card__qty-btn--dec'],
          'aria-label' => (string) $this->t('Decrease quantity'),
        ],
      ],
      'quantity_display' => [
        '#markup' => '<span class="mel-event-extra-card__qty-value" aria-hidden="true">0</span>',
      ],
      'increase' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => Markup::create('+'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['mel-event-extra-card__qty-btn', 'mel-event-extra-card__qty-btn--inc'],
          'aria-label' => (string) $this->t('Increase quantity'),
        ],
      ],
    ];

    return $card;
  }

  /**
   * @param list<array<string, mixed>> $gallery
   * @param array<string, mixed> $primary
   */
  private function buildGalleryRenderArray(array $gallery, array $primary, string $alt): array {
    $images = $gallery !== [] ? $gallery : [$primary];
    $main_url = (string) ($images[0]['url'] ?? '');
    $main_alt = (string) ($images[0]['alt'] ?? $alt);

    $safe_url = htmlspecialchars($main_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_alt = htmlspecialchars($main_alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $media = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extra-card__media']],
      'primary' => [
        '#markup' => '<img class="mel-event-extra-card__image" src="' . $safe_url . '" alt="' . $safe_alt . '" loading="lazy" width="400" height="400" decoding="async" />',
      ],
    ];

    if (count($images) > 1) {
      $media['thumbs'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extra-card__thumbs']],
      ];
      foreach ($images as $idx => $image) {
        if (!is_array($image)) {
          continue;
        }
        $url = (string) ($image['url'] ?? '');
        if ($url === '') {
          continue;
        }
        $thumb_alt = (string) ($image['alt'] ?? $alt);
        $media['thumbs'][] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => Markup::create('<img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="" loading="lazy" width="72" height="72" />'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['mel-event-extra-card__thumb', $idx === 0 ? 'is-active' : ''],
            'data-image-url' => $url,
            'data-image-alt' => $thumb_alt,
            'aria-label' => (string) $this->t('Show image @num', ['@num' => (string) ($idx + 1)]),
          ],
        ];
      }
    }

    return $media;
  }

  /**
   * @param list<array<string, mixed>> $size_options
   *
   * @return array<string, string>
   */
  private function sizeRadioOptions(array $size_options): array {
    $options = [];
    foreach ($size_options as $opt) {
      if (!is_array($opt)) {
        continue;
      }
      $key = (string) ($opt['size_key'] ?? '');
      if ($key === '') {
        continue;
      }
      $label = (string) ($opt['size_label'] ?? $key);
      $options[$key] = $label;
    }
    return $options;
  }

  /**
   * @param array<string, mixed> $addon
   * @param list<array<string, mixed>> $size_options
   */
  private function resolveDisplayPrice(array $addon, array $size_options): string {
    if ($size_options !== []) {
      $first = $size_options[0];
      $number = (string) ($first['price_number'] ?? '');
      $currency = (string) ($first['price_currency_code'] ?? '');
      if ($number !== '' && $currency !== '') {
        return $this->currencyFormatter->format($number, $currency);
      }
    }
    $variations = is_array($addon['variations'] ?? NULL) ? $addon['variations'] : [];
    $first_var = $variations[0] ?? NULL;
    if (is_array($first_var)) {
      $number = (string) ($first_var['price_number'] ?? '');
      $currency = (string) ($first_var['price_currency_code'] ?? '');
      if ($number !== '' && $currency !== '') {
        return $this->currencyFormatter->format($number, $currency);
      }
    }
    return '';
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
  private function buildProductCatalogFromAddons(array $addons): array {
    $catalog = [];
    foreach ($addons as $addon) {
      if (!is_array($addon)) {
        continue;
      }
      $pid = (int) ($addon['product_id'] ?? 0);
      if ($pid < 1) {
        continue;
      }
      $variations_by_size = [];
      $variation_ids = [];
      $size_options = is_array($addon['size_options'] ?? NULL) ? $addon['size_options'] : [];
      foreach ($size_options as $opt) {
        if (!is_array($opt)) {
          continue;
        }
        $vid = (int) ($opt['variation_id'] ?? 0);
        $size_key = (string) ($opt['size_key'] ?? '');
        if ($vid < 1 || $size_key === '') {
          continue;
        }
        $variations_by_size[$size_key] = $vid;
        $variation_ids[$vid] = TRUE;
      }
      if ($variations_by_size === []) {
        foreach (is_array($addon['variations'] ?? NULL) ? $addon['variations'] : [] as $v) {
          if (!is_array($v)) {
            continue;
          }
          $vid = (int) ($v['variation_id'] ?? 0);
          if ($vid > 0) {
            $variation_ids[$vid] = TRUE;
          }
        }
      }
      $catalog[$pid] = [
        'product_id' => $pid,
        'bundle' => (string) ($addon['bundle'] ?? ''),
        'requires_size_selection' => !empty($addon['requires_size_selection']),
        'variations_by_size' => $variations_by_size,
        'variation_ids' => array_map('intval', array_keys($variation_ids)),
        'default_variation_id' => (int) ($addon['default_variation_id'] ?? 0),
      ];
    }
    return $catalog;
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
    $catalog = $this->buildProductCatalogFromAddons($fresh['addons'] ?? []);

    $lines = $form_state->getValue('lines');
    if (!is_array($lines)) {
      return;
    }

    foreach ($lines as $line) {
      if (!is_array($line)) {
        continue;
      }
      $pid = (int) ($line['product_id'] ?? 0);
      $qty = (int) ($line['quantity'] ?? 0);
      if ($qty < 1) {
        continue;
      }
      if ($pid < 1 || !isset($catalog[$pid])) {
        $form_state->setErrorByName('lines', $this->t('One or more selected extras are not available for this event.'));
        return;
      }
      if ($qty > self::UI_QTY_MAX || $qty < 0) {
        $form_state->setErrorByName('lines', $this->t('Please choose a smaller quantity for extras.'));
        return;
      }

      $entry = $catalog[$pid];
      $vid = $this->resolveVariationIdForLine($line, $entry);
      if ($vid < 1) {
        if (!empty($entry['requires_size_selection'])) {
          $form_state->setErrorByName('lines', $this->t('Please choose a size for each extra you add.'));
        }
        else {
          $form_state->setErrorByName('lines', $this->t('Something went wrong with your selection. Please refresh and try again.'));
        }
        return;
      }
      if (!in_array($vid, $entry['variation_ids'], TRUE)) {
        $form_state->setErrorByName('lines', $this->t('One or more selected extras are not available for this event.'));
        return;
      }
    }
  }

  /**
   * @param array<string, mixed> $line
   * @param array<string, mixed> $entry
   */
  private function resolveVariationIdForLine(array $line, array $entry): int {
    $requires_size = !empty($entry['requires_size_selection']);
    $by_size = is_array($entry['variations_by_size'] ?? NULL) ? $entry['variations_by_size'] : [];
    if ($requires_size && $by_size !== []) {
      $size_key = (string) ($line['selected_size'] ?? '');
      if ($size_key === '' || !isset($by_size[$size_key])) {
        return 0;
      }
      return (int) $by_size[$size_key];
    }
    $default = (int) ($entry['default_variation_id'] ?? 0);
    if ($default > 0) {
      return $default;
    }
    $ids = is_array($entry['variation_ids'] ?? NULL) ? $entry['variation_ids'] : [];
    return $ids !== [] ? (int) $ids[0] : 0;
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
    $catalog = $this->buildProductCatalogFromAddons($fresh['addons'] ?? []);

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
      $pid = (int) ($line['product_id'] ?? 0);
      $qty = (int) ($line['quantity'] ?? 0);
      if ($qty < 1 || $pid < 1 || !isset($catalog[$pid])) {
        continue;
      }
      $vid = $this->resolveVariationIdForLine($line, $catalog[$pid]);
      if ($vid < 1) {
        continue;
      }
      $to_add[$vid] = ($to_add[$vid] ?? 0) + $qty;
    }

    if ($to_add === []) {
      $this->messenger()->addStatus($this->t('Choose a quantity greater than zero to add extras.'));
      return;
    }

    foreach ($to_add as $variation_id => $quantity) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $variation_storage->load($variation_id);
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        $this->messenger()->addError($this->t('An extra is no longer available.'));
        $this->getLogger('myeventlane_commerce')->notice('Event extra submit rejected missing or unpublished variation @vid.', ['@vid' => (string) $variation_id]);
        return;
      }

      $product = $variation->getProduct();
      if (!$product instanceof ProductInterface) {
        $this->messenger()->addError($this->t('An extra is no longer available.'));
        return;
      }

      if (!$product->isPublished()) {
        $this->messenger()->addError($this->t('An extra is no longer available.'));
        return;
      }

      if (!in_array($product->bundle(), OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
        $this->messenger()->addError($this->t('An extra is not valid for this event.'));
        return;
      }

      if (!$product->hasField('field_event') || $product->get('field_event')->isEmpty()
        || (int) $product->get('field_event')->target_id !== (int) $event->id()) {
        $this->messenger()->addError($this->t('An extra is not linked to this event.'));
        return;
      }

      if ((int) $product->id() !== (int) ($catalog[(int) $product->id()]['product_id'] ?? 0)) {
        $this->messenger()->addError($this->t('An extra is not valid for this event.'));
        return;
      }

      $stores = $product->getStores();
      if ($stores === []) {
        $this->messenger()->addError($this->t('No store is configured for an extra. Please contact the organiser.'));
        return;
      }
      $store = reset($stores);
      if (!$store) {
        $this->messenger()->addError($this->t('No store is configured for an extra. Please contact the organiser.'));
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

    $this->messenger()->addStatus($this->t('Event extras added to your booking.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()]));
  }

}
