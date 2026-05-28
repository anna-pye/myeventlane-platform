<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\node\NodeInterface;

/**
 * Builds the card-based Commerce product editor for Event Studio extras.
 */
final class EventStudioExtrasProductEditorBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventStudioEventExtrasBuilder $extrasBuilder,
    private readonly VendorOperationalProductCreationManager $productCreationManager,
    private readonly OperationalProductStudioFieldRegistry $fieldRegistry,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildEditor(
    array &$form,
    FormStateInterface $form_state,
    NodeInterface $event,
    AccountInterface $account,
    string $mode,
    int $edit_id,
  ): array {
    $product = NULL;
    $extra_type = (string) ($form_state->get('mel_extras_extra_type') ?? '');
    if ($mode === 'edit' && $edit_id > 0) {
      $product = $this->productCreationManager->assertVendorCanManageProduct(
        $account,
        $event,
        $edit_id,
      );
    }
    elseif ($extra_type === '') {
      $extra_type = 'merchandise';
    }

    $defaults = $product instanceof ProductInterface
      ? $this->productCreationManager->extractEditorDefaultsFromProduct($product)
      : $this->productCreationManager->emptyEditorDefaults($extra_type);

    $bundle = $product instanceof ProductInterface
      ? $product->bundle()
      : $this->productCreationManager->resolveCommerceProductBundleForExtraType($extra_type);
    $variation_bundle = $this->fieldRegistry->variationBundleForProductBundle($bundle);
    $product_fields = $this->fieldRegistry->productFieldSupport($bundle);
    $variation_fields = $this->fieldRegistry->variationFieldSupport($variation_bundle);
    $is_merch = $product instanceof ProductInterface
      ? $this->extrasBuilder->classificationForProduct($product) === 'merchandise'
      : $this->productCreationManager->isMerchandiseExtraType($extra_type);

    $editor = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#parents' => ['editor'],
      '#attributes' => ['class' => ['mel-event-product-editor']],
      'product_id' => [
        '#type' => 'hidden',
        '#value' => $product instanceof ProductInterface ? (string) $product->id() : '',
      ],
      'extra_type' => [
        '#type' => 'hidden',
        '#value' => $extra_type !== '' ? $extra_type : 'merchandise',
      ],
    ];

    $editor['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-product-editor__nav']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← Back to all extras'),
        '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()]),
        '#attributes' => ['class' => ['mel-event-product-editor__back']],
      ],
    ];

    $editor += $this->buildBasicsSection($defaults, $product, $extra_type, $is_merch);
    $editor += $this->buildMediaSection($form, $form_state, $event, $product, $extra_type, $product_fields);
    $editor += $this->buildPricingSection($defaults, $product);
    $editor += $this->buildQuantitySection($defaults, $variation_fields);
    if ($is_merch && $variation_fields['size']) {
      $editor += $this->buildVariantsSection($defaults, $event, $product);
    }
    $editor += $this->buildCollectionSection($defaults);
    $editor += $this->buildVisibilitySection($defaults);
    $editor += $this->buildActionsSection();

    if ($product instanceof ProductInterface) {
      $editor['customer_preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__preview-card']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Booking page preview'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'preview' => [
          '#theme' => 'mel_event_studio_extra_preview',
          '#preview' => $this->extrasBuilder->buildPreviewRow($product, $event),
        ],
      ];
    }

    return $editor;
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildBasicsSection(array $defaults, ?ProductInterface $product, string $extra_type, bool $is_merch): array {
    $section = [
      'basics' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Product basics'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
      ],
    ];

    if ($product === NULL && !$is_merch) {
      $section['basics']['extra_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Add-on type'),
        '#options' => $this->extrasBuilder->addonTypeChoices(),
        '#default_value' => $extra_type !== '' && $extra_type !== 'merchandise' ? $extra_type : 'parking',
        '#required' => TRUE,
        '#attributes' => ['class' => ['mel-event-product-editor__addon-type']],
      ];
    }
    else {
      $type_label = $is_merch
        ? (string) $this->t('Merchandise')
        : (string) ($this->extrasBuilder->addonTypeChoices()[$extra_type] ?? $this->t('Add-on'));
      $section['basics']['type_display'] = [
        '#type' => 'item',
        '#title' => $this->t('Product type'),
        '#markup' => '<p class="mel-event-product-editor__type-pill">' . $type_label . '</p>',
      ];
    }

    $section['basics']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product name'),
      '#default_value' => (string) ($defaults['title'] ?? ''),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $section['basics']['customer_summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Short customer description'),
      '#default_value' => (string) ($defaults['customer_summary'] ?? ''),
      '#required' => TRUE,
      '#rows' => 3,
    ];
    $section['basics']['product_status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Product status'),
      '#options' => $this->productCreationManager->productStatusOptions(),
      '#default_value' => (string) ($defaults['product_status'] ?? 'draft'),
      '#required' => TRUE,
      '#description' => $this->t('Active products can appear on the booking page when visibility is enabled below.'),
    ];

    return $section;
  }

  /**
   * @param array<string, bool> $product_fields
   *
   * @return array<string, mixed>
   */
  private function buildMediaSection(
    array &$form,
    FormStateInterface $form_state,
    NodeInterface $event,
    ?ProductInterface $product,
    string $extra_type,
    array $product_fields,
  ): array {
    $section = [
      'media' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Product images'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('The first image is used as the main image on the booking page. Additional images form a gallery.'),
          '#attributes' => ['class' => ['mel-text--muted']],
        ],
      ],
    ];

    if (!$product_fields['images']) {
      $section['media']['disabled'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__media-disabled']],
        'message' => [
          '#markup' => '<p>' . $this->t('Product image support is not configured for this product type yet.') . '</p>',
        ],
      ];
      return $section;
    }

    $media_product = $product ?? $this->productCreationManager->createStubProductForStudioForm($event, $extra_type);
    $this->attachImagesWidget($media_product, $form, $section['media'], $form_state);

    if ($product instanceof ProductInterface) {
      $card = $this->extrasBuilder->buildExtraCard($product, $event);
      if (!empty($card['primary_image']['url'])) {
        $section['media']['current'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-product-editor__current-image']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Current main image'),
            '#attributes' => ['class' => ['mel-text--muted']],
          ],
          'img' => [
            '#theme' => 'image',
            '#uri' => $card['primary_image']['url'],
            '#alt' => $card['primary_image']['alt'] ?? $product->label(),
            '#attributes' => ['class' => ['mel-event-product-editor__thumb']],
          ],
        ];
      }
    }

    return $section;
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildPricingSection(array $defaults, ?ProductInterface $product): array {
    $sku_description = $product === NULL
      ? $this->t('Leave blank to auto-generate a SKU. Size variants use SKU suffixes (e.g. @example).', ['@example' => 'mel-op-123-merchandise-abc123-m'])
      : $this->t('Base SKU for this product. Size variants keep their existing suffixes when you save.');

    return [
      'pricing' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Pricing and SKU'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'price_amount' => [
          '#type' => 'number',
          '#title' => $this->t('Price'),
          '#default_value' => $defaults['price_amount'] ?? '',
          '#required' => TRUE,
          '#min' => 0,
          '#step' => 0.01,
          '#attributes' => ['class' => ['mel-event-product-editor__price']],
        ],
        'sku' => [
          '#type' => 'textfield',
          '#title' => $this->t('SKU'),
          '#default_value' => (string) ($defaults['sku'] ?? ''),
          '#maxlength' => 128,
          '#description' => $sku_description,
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   * @param array<string, bool> $variation_fields
   *
   * @return array<string, mixed>
   */
  private function buildQuantitySection(array $defaults, array $variation_fields): array {
    $section = [
      'quantity' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Quantity'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
      ],
    ];

    if ($variation_fields['stock_quantity']) {
      $section['quantity']['stock'] = [
        '#type' => 'number',
        '#title' => $this->t('Available quantity'),
        '#min' => 0,
        '#step' => 1,
        '#default_value' => $defaults['stock_quantity'] ?? NULL,
      ];
    }
    else {
      $section['quantity']['capacity_note'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Quantity note'),
        '#default_value' => (string) ($defaults['capacity_note'] ?? ''),
        '#maxlength' => 200,
        '#description' => $this->t('Shown to you only. This does not enforce stock yet.'),
        '#attributes' => ['class' => ['mel-event-product-editor__capacity-note']],
      ];
      $section['quantity']['warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__stock-warning']],
        'text' => [
          '#markup' => '<p><strong>' . $this->t('Stock enforcement is coming soon.') . '</strong> '
            . $this->t('Do not oversell limited merch until stock controls are enabled.') . '</p>',
        ],
      ];
    }

    return $section;
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildVariantsSection(array $defaults, NodeInterface $event, ?ProductInterface $product): array {
    $preview_rows = $product instanceof ProductInterface
      ? $this->productCreationManager->buildVariantPreviewRowsFromProduct($product)
      : $this->productCreationManager->buildVariantPreviewRows($event, $defaults, NULL);

    return [
      'variants' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Options and variants'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Select sizes to sell. Each size becomes a purchasable Commerce variation with its own SKU.'),
          '#attributes' => ['class' => ['mel-text--muted']],
        ],
        'sizes' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Sizes'),
          '#options' => OperationalExtraVisualPresenter::SIZE_LABELS,
          '#default_value' => $defaults['sizes'] ?? [],
          '#attributes' => ['class' => ['mel-event-product-editor__sizes']],
        ],
        'variant_preview' => [
          '#theme' => 'mel_event_studio_extra_variant_preview',
          '#rows' => $preview_rows,
          '#attached' => [
            'library' => ['myeventlane_event_studio/mel_event_extras_product_editor'],
          ],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildCollectionSection(array $defaults): array {
    return [
      'collection' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Collection and fulfilment'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'pickup_note' => [
          '#type' => 'textarea',
          '#title' => $this->t('Pickup / venue collection note'),
          '#default_value' => (string) ($defaults['pickup_note'] ?? ''),
          '#rows' => 2,
          '#description' => $this->t('Use this to tell customers where to collect this item at the event.'),
        ],
        'fulfilment_notice' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-product-editor__fulfilment-notice', 'mel-text--muted']],
          'text' => [
            '#markup' => '<p>' . $this->t('Shipping and fulfilment tracking are not enabled yet.') . '</p>',
          ],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildVisibilitySection(array $defaults): array {
    return [
      'visibility' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Booking visibility'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'show_on_booking' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Show on booking page'),
          '#default_value' => (bool) ($defaults['show_on_booking'] ?? FALSE),
          '#description' => $this->t('Visible extras appear on the booking page after ticket selection. Requires Active status.'),
          '#states' => [
            'visible' => [
              ':input[name="editor[basics][product_status]"]' => ['value' => 'active'],
            ],
          ],
        ],
        'visibility_hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Set product status to Active to enable booking page visibility.'),
          '#attributes' => ['class' => ['mel-text--muted']],
          '#states' => [
            'visible' => [
              ':input[name="editor[basics][product_status]"]' => ['!value' => 'active'],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildActionsSection(): array {
    return [
      'actions' => [
        '#type' => 'actions',
        '#attributes' => ['class' => ['mel-event-product-editor__actions']],
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Save product'),
          '#button_type' => 'primary',
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ],
        'add_another' => [
          '#type' => 'submit',
          '#value' => $this->t('Save and add another'),
          '#submit' => ['::submitAddAnother'],
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
        ],
      ],
    ];
  }

  private function attachImagesWidget(
    ProductInterface $product,
    array &$form,
    array &$section,
    FormStateInterface $form_state,
  ): void {
    $display_id = 'commerce_product.' . $product->bundle() . '.default';
    $form_display = EntityFormDisplay::load($display_id);
    if ($form_display === NULL || !$product->hasField('field_mel_extra_images')) {
      return;
    }
    $widget = $form_display->getRenderer('field_mel_extra_images');
    if ($widget === NULL) {
      return;
    }
    if (!isset($form['#parents'])) {
      $form['#parents'] = [];
    }
    if (!isset($section['#parents'])) {
      $section['#parents'] = ['editor', 'media'];
    }
    if (!isset($section['#tree'])) {
      $section['#tree'] = TRUE;
    }
    $section['field_mel_extra_images'] = $widget->form(
      $product->get('field_mel_extra_images'),
      $section,
      $form_state,
    );
    $section['field_mel_extra_images']['#title'] = $this->t('Images');
    $section['field_mel_extra_images']['#attributes']['class'][] = 'mel-event-extra-gallery';
  }

}
