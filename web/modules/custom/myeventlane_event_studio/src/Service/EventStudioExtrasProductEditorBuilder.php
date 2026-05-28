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
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
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
    private readonly OperationalVariationStockResolver $stockResolver,
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
      '#attached' => [
        'library' => ['myeventlane_event_studio/mel_event_extras_product_editor'],
      ],
      'product_id' => [
        '#type' => 'hidden',
        '#value' => $product instanceof ProductInterface ? (string) $product->id() : '',
      ],
      'extra_type' => [
        '#type' => 'hidden',
        '#value' => $extra_type !== '' ? $extra_type : 'merchandise',
      ],
    ];

    $rows = $this->resolveProductOptionRows($form_state, $defaults);
    $open = $this->resolveAccordionOpenStates($form_state, $product, $defaults, $rows);

    $editor['nav'] = $this->buildTopNav($event);
    $editor['summary'] = $this->buildCompactSummary($defaults, $product, $event, $rows);

    $actions = $this->buildActionsSection();

    $editor['accordion'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-product-editor__accordion']],
      'basics' => $this->buildAccordionPanel(
        'basics',
        (string) $this->t('Basics'),
        (string) $this->t('Name, description, status, and default price for new options.'),
        $open['basics'],
        $this->buildBasicsPanelContent($defaults, $product, $extra_type, $is_merch),
      ),
      'photos' => $this->buildAccordionPanel(
        'photos',
        (string) $this->t('Photos'),
        (string) $this->t('Images customers see on the booking page.'),
        $open['photos'],
        $this->buildMediaPanelContent($form, $form_state, $event, $product, $extra_type, $product_fields),
      ),
      'product_options' => $this->buildAccordionPanel(
        'product_options',
        (string) $this->t('Product options'),
        (string) $this->t('Each option is something customers can buy.'),
        $open['product_options'],
        $this->buildProductOptionsPanelContent($defaults, $variation_fields, $form_state),
      ),
      'stock_limits' => $this->buildAccordionPanel(
        'stock_limits',
        (string) $this->t('Stock and limits'),
        (string) $this->t('Inventory and per-order limits for each option.'),
        $open['stock_limits'],
        $this->buildStockLimitsPanelContent($defaults, $variation_fields, $form_state, $rows),
      ),
      'booking_preview' => $this->buildAccordionPanel(
        'booking_preview',
        (string) $this->t('Booking preview'),
        (string) $this->t('How this item may appear to customers.'),
        $open['booking_preview'],
        $this->buildPreviewPanelContent($product, $event, $defaults, $form_state, $rows),
      ),
      'collection_documents' => $this->buildAccordionPanel(
        'collection_documents',
        (string) $this->t('Collection and documents'),
        (string) $this->t('Pickup notes, booking visibility, and operational print guidance.'),
        $open['collection_documents'],
        $this->buildCollectionDocumentsPanelContent($defaults, $event),
      ),
    ];

    $editor['footer'] = $this->buildFooterNav($event, $actions);

    return $editor;
  }

  /**
   * @param list<array<string, mixed>> $sections
   *
   * @return array<string, mixed>
   */
  private function flattenSections(array $sections): array {
    $out = [];
    foreach ($sections as $section) {
      if ($section === []) {
        continue;
      }
      $out += $section;
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $content
   *
   * @return array<string, mixed>
   */
  private function buildAccordionPanel(
    string $id,
    string $title,
    string $helper,
    bool $open,
    array $content,
  ): array {
    return [
      '#type' => 'details',
      '#title' => $title,
      '#open' => $open,
      '#attributes' => [
        'class' => ['mel-event-product-editor__accordion-panel'],
        'id' => 'mel-editor-panel-' . $id,
      ],
      'helper' => $this->sectionHelper($helper),
    ] + $content;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return array<string, bool>
   */
  private function resolveAccordionOpenStates(
    FormStateInterface $form_state,
    ?ProductInterface $product,
    array $defaults,
    array $rows,
  ): array {
    $closed = [
      'basics' => FALSE,
      'photos' => FALSE,
      'product_options' => FALSE,
      'stock_limits' => FALSE,
      'booking_preview' => FALSE,
      'collection_documents' => FALSE,
    ];

    if ($form_state->isSubmitted() && $form_state->getErrors() !== []) {
      $closed['product_options'] = TRUE;
      $closed['stock_limits'] = TRUE;
      $closed['basics'] = TRUE;
      return $closed;
    }

    if (!$product instanceof ProductInterface) {
      $closed['basics'] = TRUE;
      return $closed;
    }

    $closed['product_options'] = TRUE;
    $closed['booking_preview'] = TRUE;
    return $closed;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return array<string, mixed>
   */
  private function buildCompactSummary(
    array $defaults,
    ?ProductInterface $product,
    NodeInterface $event,
    array $rows,
  ): array {
    $summary = $this->productCreationManager->summarizeProductOptionsForPreview($rows);
    $stock = $this->productCreationManager->summarizeProductOptionsStock($rows);
    $status_key = (string) ($defaults['product_status'] ?? 'draft');
    $status_options = $this->productCreationManager->productStatusOptions();
    $status_label = (string) ($status_options[$status_key] ?? $status_key);
    $show_on_booking = (bool) ($defaults['show_on_booking'] ?? FALSE);
    $visibility_label = $status_key === 'active' && $show_on_booking
      ? (string) $this->t('Visible')
      : (string) $this->t('Hidden');
    $options_label = $summary['count'] > 0
      ? (string) $this->formatPlural($summary['count'], '1 option', '@count options')
      : (string) $this->t('No options yet');
    $price_part = $summary['price_label'] !== ''
      ? (string) $this->t('from @price', ['@price' => $summary['price_label']])
      : '';

    $parts = array_filter([
      $status_label,
      $options_label,
      (string) $stock['stock_mode_label'],
      $visibility_label,
      $price_part,
    ]);

    return [
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__compact-summary', 'mel-es-card']],
        'line' => [
          '#markup' => '<p class="mel-event-product-editor__compact-summary-line"><strong>'
            . htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8')
            . '</strong></p>',
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildBasicsPanelContent(
    array $defaults,
    ?ProductInterface $product,
    string $extra_type,
    bool $is_merch,
  ): array {
    $basics = $this->buildBasicsSection($defaults, $product, $extra_type, $is_merch);
    $basics['basics']['#parents'] = ['editor', 'basics'];
    $pricing = $this->buildPricingSection($defaults, $product);
    $pricing['pricing']['#parents'] = ['editor', 'pricing'];
    return [
      'basics' => $basics['basics'],
      'pricing' => $pricing['pricing'],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildMediaPanelContent(
    array &$form,
    FormStateInterface $form_state,
    NodeInterface $event,
    ?ProductInterface $product,
    string $extra_type,
    array $product_fields,
  ): array {
    $media = $this->buildMediaSection($form, $form_state, $event, $product, $extra_type, $product_fields);
    $media['media']['#parents'] = ['editor', 'media'];
    return ['media' => $media['media']];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildProductOptionsPanelContent(
    array $defaults,
    array $variation_fields,
    FormStateInterface $form_state,
  ): array {
    $section = $this->buildProductOptionsSection($defaults, $variation_fields, $form_state);
    $section['product_options']['#parents'] = ['editor', 'product_options'];
    return ['product_options' => $section['product_options']];
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return array<string, mixed>
   */
  private function buildStockLimitsPanelContent(
    array $defaults,
    array $variation_fields,
    FormStateInterface $form_state,
    array $rows,
  ): array {
    $stock_supported = !empty($variation_fields['stock_quantity']);
    $stock_summary = $this->productCreationManager->summarizeProductOptionsStock($rows);
    $content = [
      'stock_limits' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-event-product-editor__stock-limits']],
        'enforcement' => $this->sectionHelper((string) $this->t('Stock is enforced when customers add extras to cart.')),
        'summary' => $this->buildStockSummaryChips($stock_summary),
        'capacity_note' => [
          '#type' => 'textfield',
          '#title' => $this->t('Quantity note (optional)'),
          '#default_value' => (string) ($defaults['capacity_note'] ?? ''),
          '#maxlength' => 200,
          '#description' => $this->t('Internal note for your team only.'),
          '#parents' => ['editor', 'product_options', 'capacity_note'],
        ],
      ],
    ];

    if (!$stock_supported) {
      $content['stock_limits']['unsupported'] = [
        '#markup' => '<p class="mel-text--muted">' . $this->t('Stock fields are not configured for this product type.') . '</p>',
      ];
      return $content;
    }

    $open_details = $this->shouldOpenProductOptionStockDetails($form_state, $stock_summary);
    $content['stock_limits']['stock_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Edit stock per option'),
      '#open' => $open_details,
      '#attributes' => ['class' => ['mel-event-product-editor__option-stock-details']],
      'rules' => $this->sectionHelper((string) $this->t('Blank = unlimited. 0 = sold out. Positive numbers are enforced.')),
      'limit_hint' => $this->sectionHelper((string) $this->t('Limit per order is optional.')),
      'table' => $this->buildProductOptionStockTable($rows, $variation_fields),
    ];

    return $content;
  }

  /**
   * @param array<string, mixed> $stock_summary
   *
   * @return array<string, mixed>
   */
  private function buildStockSummaryChips(array $stock_summary): array {
    $chips = is_array($stock_summary['chips'] ?? NULL) ? $stock_summary['chips'] : [];
    if ($chips === []) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Add product options to configure stock.'),
        '#attributes' => ['class' => ['mel-event-product-editor__stock-summary-empty', 'mel-text--muted']],
      ];
    }

    $items = [];
    foreach ($chips as $index => $label) {
      $items[$index] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => ['class' => ['mel-event-product-editor__stock-summary-chip']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-product-editor__stock-summary']],
      'chips' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__stock-summary-chips']],
      ] + $items,
    ];
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @param array<string, bool> $variation_fields
   *
   * @return array<string, mixed>
   */
  private function buildProductOptionStockTable(array $rows, array $variation_fields): array {
    $table = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Option'),
        $this->t('Stock quantity'),
        $this->t('Limit per order'),
        $this->t('Show remaining'),
      ],
      '#attributes' => ['class' => ['mel-event-product-editor__option-stock-table']],
      '#caption' => $this->t('Stock per product option'),
    ];

    foreach ($rows as $delta => $row) {
      if (!is_array($row) || !empty($row['remove'])) {
        continue;
      }
      $label = trim((string) ($row['option_label'] ?? ''));
      if ($label === '' && (int) ($row['variation_id'] ?? 0) <= 0) {
        continue;
      }
      $display = $label !== '' ? $label : (string) $this->t('New option');
      $parents = ['editor', 'product_options', 'rows', $delta];
      $table[$delta] = [
        '#attributes' => ['class' => ['mel-event-product-editor__option-stock-row'], 'data-option-delta' => (string) $delta],
        'option_label' => [
          'variation_id' => [
            '#type' => 'hidden',
            '#value' => (string) (int) ($row['variation_id'] ?? 0),
            '#parents' => array_merge($parents, ['variation_id']),
          ],
          'label' => [
            '#markup' => '<span class="mel-event-product-editor__option-stock-label">' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '</span>',
          ],
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Option')],
        ],
        'stock_quantity' => [
          '#type' => 'number',
          '#title' => $this->t('Stock quantity'),
          '#title_display' => 'invisible',
          '#min' => 0,
          '#step' => 1,
          '#default_value' => $row['stock_quantity'] ?? NULL,
          '#parents' => array_merge($parents, ['stock_quantity']),
          '#attributes' => [
            'class' => ['mel-event-product-editor__option-stock-qty'],
            'placeholder' => (string) $this->t('Unlimited'),
            'aria-label' => (string) $this->t('Stock quantity for @option', ['@option' => $display]),
          ],
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Stock quantity')],
        ],
        'limit_per_order' => [
          '#type' => 'number',
          '#title' => $this->t('Limit per order'),
          '#title_display' => 'invisible',
          '#min' => 1,
          '#step' => 1,
          '#default_value' => $row['limit_per_order'] ?? NULL,
          '#parents' => array_merge($parents, ['limit_per_order']),
          '#access' => !empty($variation_fields['limit_per_order']),
          '#attributes' => [
            'class' => ['mel-event-product-editor__option-stock-limit'],
            'placeholder' => (string) $this->t('No limit'),
            'aria-label' => (string) $this->t('Limit per order for @option', ['@option' => $display]),
          ],
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Limit per order')],
        ],
        'show_remaining' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Show remaining'),
          '#title_display' => 'invisible',
          '#default_value' => !empty($row['show_remaining']),
          '#parents' => array_merge($parents, ['show_remaining']),
          '#access' => !empty($variation_fields['show_remaining']),
          '#attributes' => [
            'class' => ['mel-event-product-editor__option-stock-show'],
            'aria-label' => (string) $this->t('Show remaining for @option', ['@option' => $display]),
          ],
          '#wrapper_attributes' => ['data-label' => (string) $this->t('Show remaining')],
        ],
      ];
    }

    return $table;
  }

  /**
   * @param array<string, mixed> $stock_summary
   */
  private function shouldOpenProductOptionStockDetails(FormStateInterface $form_state, array $stock_summary): bool {
    if ($this->formHasStockFieldErrors($form_state)) {
      return TRUE;
    }
    return !empty($stock_summary['open_stock_details']);
  }

  private function formHasStockFieldErrors(FormStateInterface $form_state): bool {
    if (!$form_state->isSubmitted()) {
      return FALSE;
    }
    foreach (array_keys($form_state->getErrors()) as $name) {
      $name = (string) $name;
      if (str_contains($name, 'stock_quantity')
        || str_contains($name, 'limit_per_order')
        || str_contains($name, 'product_options')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return array<string, mixed>
   */
  private function buildPreviewPanelContent(
    ?ProductInterface $product,
    NodeInterface $event,
    array $defaults,
    FormStateInterface $form_state,
    array $rows,
  ): array {
    $preview = $this->buildPreviewSection($product, $event, $defaults, $form_state);
    return ['preview' => $preview['customer_preview']];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildCollectionDocumentsPanelContent(array $defaults, NodeInterface $event): array {
    $collection = $this->buildCollectionSection($defaults);
    $collection['collection']['#parents'] = ['editor', 'collection'];
    $visibility = $this->buildVisibilitySection($defaults);
    $visibility['visibility']['#parents'] = ['editor', 'visibility'];
    $docs = $this->buildOperationalDocumentsSection($event);
    return [
      'collection' => $collection['collection'],
      'visibility' => $visibility['visibility'],
      'operational_docs' => $docs['operational_docs'],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildTopNav(NodeInterface $event): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-product-editor__nav']],
      'back_extras' => [
        '#type' => 'link',
        '#title' => $this->t('← Back to all extras'),
        '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()]),
        '#attributes' => ['class' => ['mel-event-product-editor__back']],
      ],
      'manage_event' => [
        '#type' => 'link',
        '#title' => $this->t('Manage event'),
        '#url' => Url::fromRoute('myeventlane_vendor.console.event_workspace', ['event' => $event->id()]),
        '#attributes' => ['class' => ['mel-event-product-editor__manage-link']],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $actions
   *
   * @return array<string, mixed>
   */
  private function buildFooterNav(NodeInterface $event, array $actions): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-product-editor__footer']],
      'inner' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__footer-inner']],
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('← Back to all extras'),
          '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()]),
          '#attributes' => ['class' => ['mel-event-product-editor__back']],
        ],
        'actions' => $actions['actions'] ?? $actions,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function sectionHelper(string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $text,
      '#attributes' => ['class' => ['mel-event-product-editor__section-helper', 'mel-text--muted']],
    ];
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
        'helper' => $this->sectionHelper((string) $this->t('Name and describe what customers are buying.')),
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
        'helper' => $this->sectionHelper((string) $this->t('Use clear product photos. The first image is used as the main booking image.')),
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
    return [
      'pricing' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Default pricing and SKU'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'helper' => $this->sectionHelper((string) $this->t('Used for new options. You can adjust each option below.')),
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
  private function buildProductOptionsSection(
    array $defaults,
    array $variation_fields,
    FormStateInterface $form_state,
  ): array {
    $rows = $this->resolveProductOptionRows($form_state, $defaults);

    $section = [
      'product_options' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-event-product-editor__product-options']],
        'helper' => $this->sectionHelper((string) $this->t('Each option is something customers can buy. Add sizes, colours, parking passes, meal packages, or VIP upgrades as separate options.')),
        'colour_note' => $this->sectionHelper((string) $this->t('Colour can go in the option label for now (e.g. “Black / S”). A guided colour builder is coming later.')),
        'presets' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-product-editor__option-presets']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Quick presets (not saved until you click Save product):'),
            '#attributes' => ['class' => ['mel-text--muted', 'mel-event-product-editor__preset-label']],
          ],
          'tshirt' => [
            '#type' => 'submit',
            '#value' => $this->t('Use T-shirt sizes'),
            '#submit' => ['::submitPresetTshirtSizes'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__preset-btn']],
          ],
          'full_sizes' => [
            '#type' => 'submit',
            '#value' => $this->t('Use full size set'),
            '#submit' => ['::submitPresetFullSizes'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__preset-btn']],
          ],
          'one_option' => [
            '#type' => 'submit',
            '#value' => $this->t('Use one option'),
            '#submit' => ['::submitPresetOneOption'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__preset-btn']],
          ],
          'parking' => [
            '#type' => 'submit',
            '#value' => $this->t('Use parking pass'),
            '#submit' => ['::submitPresetParkingPass'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__preset-btn']],
          ],
          'meal' => [
            '#type' => 'submit',
            '#value' => $this->t('Use meal package'),
            '#submit' => ['::submitPresetMealPackage'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__preset-btn']],
          ],
        ],
        'rows' => [
          '#type' => 'container',
          '#tree' => TRUE,
          '#attributes' => ['class' => ['mel-event-product-editor__option-rows']],
        ],
        'add_option' => [
          '#type' => 'submit',
          '#value' => $this->t('Add option'),
          '#submit' => ['::submitAddProductOption'],
          '#limit_validation_errors' => [],
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__add-option']],
        ],
      ],
    ];

    foreach ($rows as $delta => $row) {
      if (!is_array($row)) {
        continue;
      }
      $section['product_options']['rows'][$delta] = $this->buildProductOptionCatalogRow(
        (int) $delta,
        $row,
        $defaults,
      );
    }

    return $section;
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildProductOptionCatalogRow(int $delta, array $row, array $defaults): array {
    $label = (string) ($row['option_label'] ?? '');
    return [
      '#type' => 'fieldset',
      '#title' => $label !== '' ? $label : (string) $this->t('New option'),
      '#attributes' => ['class' => ['mel-event-product-editor__option-card']],
      'variation_id' => [
        '#type' => 'hidden',
        '#value' => (string) (int) ($row['variation_id'] ?? 0),
      ],
      'option_label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Option label'),
        '#default_value' => $label,
        '#maxlength' => 128,
        '#description' => $this->t('Examples: S, M, L, Black / S, Parking pass, VIP upgrade'),
      ],
      'sku' => [
        '#type' => 'textfield',
        '#title' => $this->t('SKU'),
        '#default_value' => (string) ($row['sku'] ?? ''),
        '#maxlength' => 128,
      ],
      'price_amount' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#default_value' => $row['price_amount'] ?? $defaults['price_amount'] ?? '',
        '#min' => 0,
        '#step' => 0.01,
      ],
      'published' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Visible on booking page'),
        '#default_value' => !isset($row['published']) || !empty($row['published']),
      ],
      'remove' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove this option'),
        '#default_value' => !empty($row['remove']),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return list<array<string, mixed>>
   */
  private function resolveProductOptionRows(FormStateInterface $form_state, array $defaults): array {
    $stored = $form_state->get('mel_product_options');
    if (is_array($stored) && $stored !== []) {
      return $stored;
    }
    $options = $defaults['product_options'] ?? [];
    if (!is_array($options) || $options === []) {
      return [$this->productCreationManager->emptyProductOptionRow($defaults)];
    }
    return $options;
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildCollectionAndVisibilitySection(array $defaults): array {
    $collection = $this->buildCollectionSection($defaults);
    $visibility = $this->buildVisibilitySection($defaults);
    return [
      'collection_visibility' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-event-product-editor__collection-visibility']],
        'collection' => $collection['collection'],
        'visibility' => $visibility['visibility'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   * @param array<string, bool> $variation_fields
   *
   * @return array<string, mixed>
   */
  private function buildQuantitySection(array $defaults, array $variation_fields, bool $is_merch): array {
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
        'helper' => $this->sectionHelper((string) $this->t('Stock is enforced when customers add extras to cart.')),
        'stock_rules' => $this->sectionHelper((string) $this->t('Blank stock = unlimited. 0 = sold out. Positive numbers are enforced.')),
      ],
    ];

    $stock_supported = !empty($variation_fields['stock_quantity']);

    if ($stock_supported && !$is_merch) {
      $section['quantity']['stock_card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__stock-card']],
      ];
      $section['quantity']['stock_card']['stock_quantity'] = [
        '#type' => 'number',
        '#title' => $this->t('Stock quantity'),
        '#min' => 0,
        '#step' => 1,
        '#default_value' => $defaults['stock_quantity'] ?? NULL,
        '#description' => $this->t('Blank = unlimited. 0 = sold out.'),
        '#attributes' => ['placeholder' => (string) $this->t('Unlimited')],
      ];
      if (!empty($variation_fields['limit_per_order'])) {
        $section['quantity']['stock_card']['limit_per_order'] = [
          '#type' => 'number',
          '#title' => $this->t('Limit per order'),
          '#min' => 1,
          '#step' => 1,
          '#default_value' => $defaults['limit_per_order'] ?? NULL,
          '#description' => $this->t('Optional maximum per customer order.'),
        ];
      }
      if (!empty($variation_fields['show_remaining'])) {
        $section['quantity']['stock_card']['show_remaining'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Show remaining'),
          '#default_value' => !empty($defaults['show_remaining']),
          '#description' => $this->t('Show customers how many are left.'),
        ];
      }
    }
    elseif (!$stock_supported) {
      $section['quantity']['capacity_note'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Quantity note'),
        '#default_value' => (string) ($defaults['capacity_note'] ?? ''),
        '#maxlength' => 200,
        '#description' => $this->t('Optional internal note. This does not enforce stock.'),
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

    if ($stock_supported && !$is_merch) {
      $section['quantity']['capacity_note'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Quantity note (optional)'),
        '#default_value' => (string) ($defaults['capacity_note'] ?? ''),
        '#maxlength' => 200,
        '#description' => $this->t('Internal helper note for your team. Stock quantity above is enforced at checkout.'),
        '#attributes' => ['class' => ['mel-event-product-editor__capacity-note']],
      ];
    }

    return $section;
  }

  /**
   * @param array<string, mixed> $defaults
   * @param array<string, bool> $variation_fields
   *
   * @return array<string, mixed>
   */
  private function buildQuantityAsideSection(array $defaults, array $variation_fields): array {
    if (empty($variation_fields['stock_quantity'])) {
      return [];
    }

    return [
      'quantity_summary' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-es-card', 'mel-event-product-editor__section', 'mel-event-product-editor__section--aside']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Quantity'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'helper' => $this->sectionHelper((string) $this->t('Stock is enforced when customers add extras to cart.')),
        'stock_rules' => $this->sectionHelper((string) $this->t('Blank stock = unlimited. 0 = sold out. Positive numbers are enforced.')),
        'capacity_note' => [
          '#type' => 'textfield',
          '#title' => $this->t('Quantity note (optional)'),
          '#default_value' => (string) ($defaults['capacity_note'] ?? ''),
          '#maxlength' => 200,
          '#description' => $this->t('Internal helper note for your team. Set stock per size in Options and variants.'),
          '#attributes' => ['class' => ['mel-event-product-editor__capacity-note']],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildVariantsSection(
    array $defaults,
    NodeInterface $event,
    ?ProductInterface $product,
    array $variation_fields,
    FormStateInterface $form_state,
    array $preview_rows,
  ): array {
    $stock_supported = !empty($variation_fields['stock_quantity']);
    $variant_stock_defaults = is_array($defaults['variant_stock'] ?? NULL) ? $defaults['variant_stock'] : [];
    $stock_mode = $this->resolveVariantStockMode($preview_rows, $variant_stock_defaults);
    $open_stock_details = $this->shouldOpenVariantStockDetails($form_state, $stock_mode);
    $open_variation_summary = $this->shouldOpenVariationSummary($form_state, $open_stock_details);

    $section = [
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
        'helper' => $this->sectionHelper((string) $this->t('Each selected size becomes a purchasable option.')),
        'colour_note' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Colour options are coming soon.'),
          '#attributes' => ['class' => ['mel-event-product-editor__coming-soon', 'mel-text--muted']],
        ],
        'sizes' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Sizes'),
          '#options' => OperationalExtraVisualPresenter::SIZE_LABELS,
          '#default_value' => $defaults['sizes'] ?? [],
          '#attributes' => ['class' => ['mel-event-product-editor__sizes']],
        ],
      ],
    ];

    if ($stock_supported) {
      $section['variants']['stock_overview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-product-editor__stock-overview']],
        'summary' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->describeVariantStockState($preview_rows, $variant_stock_defaults),
          '#attributes' => ['class' => ['mel-event-product-editor__stock-overview-summary']],
        ],
        'rules' => $this->sectionHelper((string) $this->t('Blank stock = unlimited. 0 = sold out. Positive numbers are enforced.')),
        'limit_hint' => $this->sectionHelper((string) $this->t('Limit per order is optional.')),
      ];

      $stock_table = [
        '#type' => 'table',
        '#tree' => TRUE,
        '#header' => [
          $this->t('Size'),
          $this->t('Stock quantity'),
          $this->t('Limit per order'),
          $this->t('Show remaining'),
          $this->t('Status'),
        ],
        '#attributes' => ['class' => ['mel-event-product-editor__variant-stock-table']],
        '#caption' => $this->t('Stock settings per size'),
      ];
      foreach ($preview_rows as $row) {
        $key = (string) ($row['size_key'] ?? '_default');
        $stock_defaults = is_array($variant_stock_defaults[$key] ?? NULL) ? $variant_stock_defaults[$key] : $row;
        $size_label = (string) ($row['size_label'] ?? $key);
        $stock_table[$key] = [
          '#attributes' => ['class' => ['mel-event-product-editor__variant-stock-row'], 'data-size-key' => $key],
          'size_label' => [
            '#markup' => '<span class="mel-event-product-editor__variant-size">' . htmlspecialchars($size_label, ENT_QUOTES, 'UTF-8') . '</span>',
            '#wrapper_attributes' => ['data-label' => (string) $this->t('Size')],
          ],
          'stock_quantity' => [
            '#type' => 'number',
            '#title' => $this->t('Stock quantity'),
            '#title_display' => 'invisible',
            '#min' => 0,
            '#step' => 1,
            '#default_value' => $stock_defaults['stock_quantity'] ?? NULL,
            '#attributes' => [
              'class' => ['mel-event-product-editor__variant-stock-qty'],
              'placeholder' => (string) $this->t('Unlimited'),
              'aria-label' => (string) $this->t('Stock quantity for @size', ['@size' => $size_label]),
            ],
            '#wrapper_attributes' => ['data-label' => (string) $this->t('Stock quantity')],
          ],
          'limit_per_order' => [
            '#type' => 'number',
            '#title' => $this->t('Limit per order'),
            '#title_display' => 'invisible',
            '#min' => 1,
            '#step' => 1,
            '#default_value' => $stock_defaults['limit_per_order'] ?? NULL,
            '#attributes' => [
              'class' => ['mel-event-product-editor__variant-stock-limit'],
              'placeholder' => (string) $this->t('No limit'),
              'aria-label' => (string) $this->t('Limit per order for @size', ['@size' => $size_label]),
            ],
            '#wrapper_attributes' => ['data-label' => (string) $this->t('Limit per order')],
          ],
          'show_remaining' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Show remaining'),
            '#title_display' => 'invisible',
            '#default_value' => !empty($stock_defaults['show_remaining']),
            '#attributes' => [
              'class' => ['mel-event-product-editor__variant-stock-show'],
              'aria-label' => (string) $this->t('Show remaining for @size', ['@size' => $size_label]),
            ],
            '#wrapper_attributes' => ['data-label' => (string) $this->t('Show remaining')],
          ],
          'status' => [
            '#markup' => '<span class="mel-event-product-editor__variant-status">' . htmlspecialchars((string) ($row['status_label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>',
            '#wrapper_attributes' => ['data-label' => (string) $this->t('Status')],
          ],
        ];
      }

      $section['variants']['variant_stock_details'] = [
        '#type' => 'details',
        '#title' => $this->t('Set stock per option'),
        '#open' => $open_stock_details,
        '#attributes' => ['class' => ['mel-event-product-editor__variant-stock-details']],
        'variant_stock' => $stock_table,
      ];
    }

    $section['variants']['variant_preview'] = [
      '#theme' => 'mel_event_studio_extra_variant_preview',
      '#rows' => $preview_rows,
      '#stock_supported' => $stock_supported,
      '#stock_editor_enabled' => $stock_supported,
      '#open' => $open_variation_summary,
      '#summary_title' => (string) $this->t('Variation summary'),
    ];

    return $section;
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
        'helper' => $this->sectionHelper((string) $this->t('Tell customers where to collect this item at the event. Shipping and fulfilment tracking are not enabled yet.')),
        'pickup_note' => [
          '#type' => 'textarea',
          '#title' => $this->t('Pickup / venue collection note'),
          '#default_value' => (string) ($defaults['pickup_note'] ?? ''),
          '#rows' => 2,
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
        '#attributes' => ['class' => ['mel-event-product-editor__visibility']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Booking visibility'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'helper' => $this->sectionHelper((string) $this->t('Visible extras appear on the booking page after ticket selection. Product must be Active to show.')),
        'show_on_booking' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Show on booking page'),
          '#default_value' => (bool) ($defaults['show_on_booking'] ?? FALSE),
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
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--primary', 'mel-event-product-editor__save'],
            'id' => 'mel-product-editor-save',
          ],
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

  /**
   * @return array<string, mixed>
   */
  private function buildOperationalDocumentsSection(NodeInterface $event): array {
    return [
      'operational_docs' => [
        '#type' => 'container',
        '#attributes' => ['class' => [
          'mel-es-card',
          'mel-event-product-editor__section',
          'mel-event-product-editor__section--aside',
          'mel-event-product-editor__operational-docs',
        ]],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Packing slips, parking slips and labels'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'helper' => $this->sectionHelper((string) $this->t('Print tools live in Manage event once this item starts selling.')),
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Back to Manage event'),
          '#url' => Url::fromRoute('myeventlane_vendor.console.event_workspace', ['event' => $event->id()]),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-product-editor__manage-cta']],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   *
   * @return array<string, mixed>
   */
  private function buildPreviewSection(
    ?ProductInterface $product,
    NodeInterface $event,
    array $defaults,
    FormStateInterface $form_state,
  ): array {
    $options = $this->resolveProductOptionRows($form_state, $defaults);
    $summary = $this->productCreationManager->summarizeProductOptionsForPreview($options);
    $product_status = (string) ($defaults['product_status'] ?? 'draft');
    $show_on_booking = (bool) ($defaults['show_on_booking'] ?? FALSE);

    if ($product instanceof ProductInterface) {
      $preview = $this->extrasBuilder->buildPreviewRow($product, $event);
      $stock_label = (string) ($this->stockResolver->summarizeProductStock($product)['stock_label'] ?? '');
    }
    else {
      $preview = [
        'title' => (string) ($defaults['title'] ?? ''),
        'short_description' => (string) ($defaults['customer_summary'] ?? ''),
        'pickup_note' => (string) ($defaults['pickup_note'] ?? ''),
        'primary_image' => [],
      ];
      $stock_label = (string) $summary['stock_summary'];
    }

    $options_summary = $summary['count'] > 0
      ? (string) $this->formatPlural(
        $summary['count'],
        '1 option',
        '@count options',
      )
      : (string) $this->t('No options yet');

    $price_display = '';
    if ($summary['price_label'] !== '') {
      $price_display = (string) $this->t('from @price', ['@price' => $summary['price_label']]);
    }
    elseif (!empty($defaults['price_amount'])) {
      $price_display = (string) $this->t('from @price', [
        '@price' => number_format((float) $defaults['price_amount'], 2),
      ]);
    }

    $preview_meta_parts = array_filter([
      $options_summary,
      $price_display,
      (string) ($summary['stock_summary'] ?? ''),
    ]);
    $preview_meta_line = implode(' · ', $preview_meta_parts);

    $visibility_label = $product_status === 'active' && $show_on_booking
      ? (string) $this->t('Visible on booking page')
      : (string) $this->t('Not on booking page');

    $hidden_notice = '';
    if ($product_status !== 'active' || !$show_on_booking) {
      $hidden_notice = (string) $this->t('This product will not appear on the booking page until it is Active and visible.');
    }

    return [
      'customer_preview' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-product-editor__preview-wrap'],
          'id' => 'mel-product-booking-preview',
        ],
        'preview' => [
          '#theme' => 'mel_event_studio_extra_preview',
          '#preview' => $preview,
          '#price_display' => $price_display,
          '#options_summary' => $options_summary,
          '#preview_meta_line' => $preview_meta_line,
          '#stock_label' => $stock_label !== '' ? $stock_label : (string) $summary['stock_summary'],
          '#visibility_label' => $visibility_label,
          '#hidden_notice' => $hidden_notice,
        ],
        'preview_note' => $this->sectionHelper((string) $this->t('Preview only. Customers see this on the booking page.')),
      ],
    ];
  }

  /**
   * @param list<array<string, mixed>> $preview_rows
   * @param array<string, bool> $variation_fields
   *
   * @return array<string, mixed>
   */
  private function buildProductSetupSummarySection(
    array $defaults,
    ?ProductInterface $product,
    NodeInterface $event,
    bool $is_merch,
    array $preview_rows,
    array $variation_fields,
  ): array {
    $status_key = (string) ($defaults['product_status'] ?? 'draft');
    $status_options = $this->productCreationManager->productStatusOptions();
    $status_label = (string) ($status_options[$status_key] ?? $status_key);

    $options = is_array($defaults['product_options'] ?? NULL) ? $defaults['product_options'] : [];
    $preview_summary = $this->productCreationManager->summarizeProductOptionsForPreview($options);
    $stock_aggregate = $this->productCreationManager->summarizeProductOptionsStock($options);
    $price_label = $preview_summary['price_label'] !== ''
      ? (string) $this->t('from @price', ['@price' => $preview_summary['price_label']])
      : ((($defaults['price_amount'] ?? '') !== '' && ($defaults['price_amount'] ?? NULL) !== NULL)
        ? (string) $this->t('from @price', ['@price' => number_format((float) $defaults['price_amount'], 2)])
        : (string) $this->t('Not set'));

    $image_label = $this->resolveImageStatusLabel($product, $event);
    $options_label = $preview_summary['count'] > 0
      ? (string) $this->formatPlural($preview_summary['count'], '1 option', '@count options')
      : (string) $this->t('No options yet');

    if ($product instanceof ProductInterface) {
      $stock = $this->stockResolver->summarizeProductStock($product);
      $stock_label = (string) ($stock['stock_label'] ?? '');
      if ($stock_label === '' && !empty($stock['stock_state'])) {
        $stock_label = (string) ($stock_aggregate['stock_mode_label'] ?? '');
      }
    }
    else {
      $stock_label = (string) ($stock_aggregate['stock_mode_label'] ?? (string) $this->t('Not configured'));
    }

    $show_on_booking = (bool) ($defaults['show_on_booking'] ?? FALSE);
    $visibility_label = $status_key === 'active' && $show_on_booking
      ? (string) $this->t('Visible on booking page')
      : (string) $this->t('Not on booking page');

    return [
      'setup_summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => [
          'mel-event-product-editor__section--aside',
        ]],
        'summary' => [
          '#theme' => 'mel_event_studio_extra_product_setup_summary',
          '#summary' => [
            'status_label' => $status_label,
            'price_label' => $price_label,
            'image_label' => $image_label,
            'options_label' => $options_label,
            'stock_label' => $stock_label,
            'visibility_label' => $visibility_label,
          ],
          '#show_preview_anchor' => $product instanceof ProductInterface,
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $defaults
   */
  private function resolveImageStatusLabel(?ProductInterface $product, NodeInterface $event): string {
    if (!$product instanceof ProductInterface) {
      return (string) $this->t('No image yet');
    }
    $count = $this->extrasBuilder->buildExtraCard($product, $event)['image_count'] ?? 0;
    if (!is_int($count)) {
      $count = 0;
    }
    if ($count === 0) {
      return (string) $this->t('No image yet');
    }
    return (string) $this->formatPlural($count, '1 image', '@count images');
  }

  /**
   * @param array<string, mixed> $defaults
   */
  private function countSelectedSizes(array $defaults): int {
    $sizes = $defaults['sizes'] ?? [];
    if (!is_array($sizes)) {
      return 0;
    }
    $count = 0;
    foreach ($sizes as $value) {
      if ($value !== 0 && $value !== '0' && $value !== FALSE && $value !== NULL) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * @param list<array<string, mixed>> $preview_rows
   * @param array<string, array<string, mixed>> $variant_stock_defaults
   */
  private function describeVariantStockState(array $preview_rows, array $variant_stock_defaults): string {
    $mode = $this->resolveVariantStockMode($preview_rows, $variant_stock_defaults);
    $size_count = count($preview_rows);

    return match ($mode) {
      'empty' => (string) $this->t('Select sizes to configure stock.'),
      'unlimited' => (string) $this->formatPlural($size_count, 'Unlimited stock (1 option)', 'Unlimited stock (@count options)'),
      'sold_out' => (string) $this->formatPlural($size_count, 'Sold out (1 option)', 'Sold out (@count options)'),
      'uniform' => (string) $this->t('@count units for every option', [
        '@count' => $this->firstFiniteStockQuantity($preview_rows, $variant_stock_defaults),
      ]),
      default => (string) $this->formatPlural($size_count, 'Stock set per option (1 size)', 'Stock set per option (@count sizes)'),
    };
  }

  /**
   * @param list<array<string, mixed>> $preview_rows
   * @param array<string, array<string, mixed>> $variant_stock_defaults
   *
   * @return 'empty'|'unlimited'|'sold_out'|'uniform'|'per_option'
   */
  private function resolveVariantStockMode(array $preview_rows, array $variant_stock_defaults): string {
    if ($preview_rows === []) {
      return 'empty';
    }

    $quantities = [];
    foreach ($preview_rows as $row) {
      $key = (string) ($row['size_key'] ?? '_default');
      $stock_defaults = is_array($variant_stock_defaults[$key] ?? NULL) ? $variant_stock_defaults[$key] : $row;
      $quantities[] = $this->stockResolver->normalizeStockInput($stock_defaults['stock_quantity'] ?? NULL);
    }

    $all_unlimited = TRUE;
    $all_sold_out = TRUE;
    $first_finite = NULL;
    $mixed = FALSE;

    foreach ($quantities as $qty) {
      if ($qty !== NULL) {
        $all_unlimited = FALSE;
      }
      if ($qty === NULL || $qty > 0) {
        $all_sold_out = FALSE;
      }
      if ($qty !== NULL && $qty > 0) {
        if ($first_finite === NULL) {
          $first_finite = $qty;
        }
        elseif ($first_finite !== $qty) {
          $mixed = TRUE;
        }
      }
    }

    if ($all_unlimited) {
      return 'unlimited';
    }
    if ($all_sold_out) {
      return 'sold_out';
    }
    if ($mixed) {
      return 'per_option';
    }
    if ($first_finite !== NULL) {
      return 'uniform';
    }

    return 'per_option';
  }

  /**
   * @param list<array<string, mixed>> $preview_rows
   * @param array<string, array<string, mixed>> $variant_stock_defaults
   */
  private function firstFiniteStockQuantity(array $preview_rows, array $variant_stock_defaults): int {
    foreach ($preview_rows as $row) {
      $key = (string) ($row['size_key'] ?? '_default');
      $stock_defaults = is_array($variant_stock_defaults[$key] ?? NULL) ? $variant_stock_defaults[$key] : $row;
      $qty = $this->stockResolver->normalizeStockInput($stock_defaults['stock_quantity'] ?? NULL);
      if ($qty !== NULL && $qty > 0) {
        return $qty;
      }
    }
    return 0;
  }

  /**
   * @param 'empty'|'unlimited'|'sold_out'|'uniform'|'per_option' $stock_mode
   */
  private function shouldOpenVariantStockDetails(FormStateInterface $form_state, string $stock_mode): bool {
    if ($this->formHasRelevantErrors($form_state)) {
      return TRUE;
    }
    return $stock_mode === 'per_option';
  }

  private function shouldOpenVariationSummary(FormStateInterface $form_state, bool $open_stock_details): bool {
    return $this->formHasRelevantErrors($form_state) || $open_stock_details;
  }

  private function formHasRelevantErrors(FormStateInterface $form_state): bool {
    if (!$form_state->isSubmitted()) {
      return FALSE;
    }
    foreach (array_keys($form_state->getErrors()) as $name) {
      if (str_contains((string) $name, 'editor') || str_contains((string) $name, 'variant_stock')) {
        return TRUE;
      }
    }
    return FALSE;
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
