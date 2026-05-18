<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioBuilder;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Vendor productisation authoring for operational Commerce (link + optional wizard create on explicit save).
 */
final class EventStudioProductisationForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalCapabilityStudioManager $capabilityStudioManager,
    private readonly VendorProductisationStudioManager $vendorProductisationManager,
    private readonly VendorProductisationStudioBuilder $vendorProductisationBuilder,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly VendorOperationalProductCreationManager $vendorOperationalProductCreationManager,
    private readonly OperationalCapabilityCommerceLinkManager $operationalCapabilityCommerceLinkManager,
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_event_studio.operational_capability_studio_manager'),
      $container->get('myeventlane_event_studio.vendor_productisation_studio_manager'),
      $container->get('myeventlane_event_studio.vendor_productisation_studio_builder'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('current_user'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
      $container->get('myeventlane_event_studio.vendor_operational_product_creation_manager'),
      $container->get('myeventlane_event_studio.operational_capability_commerce_link_manager'),
      $container->get('database'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_productisation';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $event = $this->getRouteEvent($node);
    $this->assertCanManageEvent($event);

    $document = $this->capabilityStudioManager->extractFromEvent($event);
    $merch = is_array($document['operational_merchandise'] ?? NULL)
      ? $document['operational_merchandise']
      : $this->capabilityStudioManager->emptyDocument()['operational_merchandise'];

    $draft = $this->autosaveService->getDraft($event, 'merchandise');
    if ($draft !== NULL && is_array($draft['mel']['operational_merchandise'] ?? NULL)) {
      $merch = array_replace_recursive($merch, $draft['mel']['operational_merchandise']);
    }

    $document['operational_merchandise'] = $merch;
    $authoring = $this->vendorProductisationManager->buildAuthoringStateFromMerchandise($merch, $event);

    $form['#attributes']['class'][] = 'mel-event-studio-productisation';
    $form['#attributes']['data-mel-event-studio-form'] = '1';
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_vendor_productisation_studio';

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];
    $form['mel_studio_section'] = [
      '#type' => 'hidden',
      '#value' => 'merchandise',
    ];
    $form['mel_studio_changed'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->getChangedTime(),
    ];
    $form['mel_studio_revision'] = [
      '#type' => 'hidden',
      '#value' => (string) (int) $event->getRevisionId(),
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-productisation-intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Productisation studio'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Link existing operational Commerce products or create new ones using the wizard (created only when you press Save). Commerce owns catalog, carts, and checkout — this screen stores event-scoped authoring metadata; stock, shipping, and fulfilment execution are configured later.'),
        '#attributes' => ['class' => ['mel-es-card__hint']],
      ],
    ];

    $form['workspace'] = $this->vendorProductisationBuilder->buildProductisationWorkspace($authoring, $document, $event);

    $form['mel'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    $form['mel']['productisation'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-productisation-editors']],
    ];

    $byType = [];
    foreach ($authoring['items'] as $item) {
      if (is_array($item) && isset($item['productisation_type'])) {
        $byType[(string) $item['productisation_type']] = $item;
      }
    }

    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $type) {
      $row = $byType[$type] ?? $this->vendorProductisationManager->emptyItem($type);
      $form['mel']['productisation']['types'][$type] = $this->buildEditorForType($type, $row, $event);
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save productisation'),
        '#button_type' => 'primary',
        '#attributes' => ['class' => ['mel-productisation-save']],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }
    $baseChanged = (int) ($form_state->getValue('mel_studio_changed') ?? 0);
    $baseRevisionId = (int) ($form_state->getValue('mel_studio_revision') ?? 0);
    if ($this->autosaveService->isStaleSubmission($event, $baseChanged, $baseRevisionId)) {
      $form_state->setErrorByName('', $this->t('This section was updated elsewhere. Refresh to continue editing safely.'));
      return;
    }

    $items = $this->collectItemsFromFormState($form_state);
    $errors = $this->vendorProductisationManager->validateMerchandiseFragment($event, [
      'productisation_items' => $items,
      'linked_products' => [],
    ]);
    foreach ($errors as $error) {
      $form_state->setErrorByName('', $error);
    }

    $rawTypes = (array) ($form_state->getValue(['mel', 'productisation', 'types']) ?? []);
    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $type) {
      $row = is_array($rawTypes[$type] ?? NULL) ? $rawTypes[$type] : [];
      $row = $this->vendorOperationalProductCreationManager->flattenProductisationWizardFormRow($row);
      if ((string) ($row['commerce_mode'] ?? 'link') !== 'create') {
        continue;
      }
      if ($this->extractCommerceProductIdFromFormRow($row) > 0) {
        continue;
      }
      $payload = $this->vendorOperationalProductCreationManager->buildWizardCreationPayload($type, $row, $event);
      foreach ($this->vendorOperationalProductCreationManager->validateCreationPayload($this->currentUser(), $event, $payload) as $error) {
        $form_state->setErrorByName('', (string) $error);
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }
    $transaction = $this->database->startTransaction();
    try {
      $base = $this->capabilityStudioManager->extractFromEvent($event);
      $rawTypes = (array) ($form_state->getValue(['mel', 'productisation', 'types']) ?? []);
      $items = $this->collectItemsFromFormState($form_state);
      $flatTypes = [];
      foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $ptype) {
        $r = is_array($rawTypes[$ptype] ?? NULL) ? $rawTypes[$ptype] : [];
        $flatTypes[$ptype] = $this->vendorOperationalProductCreationManager->flattenProductisationWizardFormRow($r);
      }
      $items = $this->vendorOperationalProductCreationManager->applyProductisationWizardCreates(
        $this->currentUser(),
        $event,
        $items,
        $flatTypes,
      );
      $base['operational_merchandise'] = is_array($base['operational_merchandise'] ?? NULL)
        ? $base['operational_merchandise']
        : $this->capabilityStudioManager->emptyDocument()['operational_merchandise'];
      $base['operational_merchandise']['productisation_items'] = $items;
      $this->capabilityStudioManager->persistToEvent($event, $base);
      EventNodeRevisionSave::prepare($event, 'Event Studio vendor productisation authoring save.');
      $event->save();
      $this->autosaveService->clearDraft($event, 'merchandise');
      $this->messenger()->addStatus($this->t('Productisation saved.'));
    }
    catch (\InvalidArgumentException $e) {
      $transaction->rollBack();
      $this->messenger()->addError($e->getMessage());
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('Vendor productisation save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not save productisation.'));
    }
    $form_state->setRebuild(TRUE);
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function buildEditorForType(string $type, array $row, NodeInterface $event): array {
    $commerce = is_array($row['commerce'] ?? NULL) ? $row['commerce'] : [];
    $product_id = (int) ($commerce['product_id'] ?? 0);
    $link_mode = (string) ($commerce['linkage_mode'] ?? OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT);
    $var_defaults = [];
    foreach ($this->normalizeVariationIds($commerce['variation_ids'] ?? []) as $vid) {
      $var_defaults[(string) $vid] = (string) $vid;
    }

    $default_currency = $this->defaultCommerceCurrencyForEvent($event);
    $mode_input = 'mel[productisation][types][' . $type . '][commerce_mode]';

    $fieldset = [
      '#type' => 'fieldset',
      '#title' => $this->typeEditorTitle($type),
      '#attributes' => [
        'class' => ['mel-productisation-editor', 'mel-productisation-editor--' . $type],
        'data-productisation-type' => $type,
      ],
    ];

    $fieldset += match ($type) {
      VendorProductisationStudioManager::TYPE_MERCHANDISE => [
        'title' => [
          '#type' => 'textfield',
          '#title' => $this->t('Extra title'),
          '#default_value' => (string) ($row['title'] ?? ''),
          '#maxlength' => 180,
        ],
        'customer_summary' => [
          '#type' => 'textarea',
          '#title' => $this->t('Short customer description'),
          '#default_value' => (string) ($row['customer_summary'] ?? ''),
          '#rows' => 3,
        ],
        'pickup_note' => [
          '#type' => 'textarea',
          '#title' => $this->t('Pickup note'),
          '#default_value' => (string) ($row['pickup_note'] ?? ''),
          '#rows' => 2,
          '#description' => $this->t('Shown to customers, e.g. “Collect at the merch table after check-in.”'),
        ],
        'customer_visibility' => [
          '#type' => 'select',
          '#title' => $this->t('Customer visibility'),
          '#options' => [
            'visible' => $this->t('Visible'),
            'hidden' => $this->t('Hidden'),
            'after_purchase' => $this->t('After purchase'),
          ],
          '#default_value' => (string) ($row['customer_visibility'] ?? 'visible'),
        ],
        'pickup_mode' => [
          '#type' => 'hidden',
          '#value' => 'venue_pickup',
        ],
      ],
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => [
        'package_name' => [
          '#type' => 'textfield',
          '#title' => $this->t('Package name'),
          '#default_value' => (string) ($row['package_name'] ?? ''),
          '#maxlength' => 180,
        ],
        'benefits_summary' => [
          '#type' => 'textarea',
          '#title' => $this->t('Included benefits summary'),
          '#default_value' => (string) ($row['benefits_summary'] ?? ''),
          '#rows' => 3,
        ],
        'customer_visibility' => [
          '#type' => 'select',
          '#title' => $this->t('Customer visibility'),
          '#options' => [
            'visible' => $this->t('Visible'),
            'hidden' => $this->t('Hidden'),
            'after_purchase' => $this->t('After purchase'),
          ],
          '#default_value' => (string) ($row['customer_visibility'] ?? 'visible'),
        ],
        'fulfillment_mode' => [
          '#type' => 'textfield',
          '#title' => $this->t('Fulfilment mode token'),
          '#default_value' => (string) ($row['fulfillment_mode'] ?? 'redeem'),
          '#maxlength' => 64,
        ],
      ],
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => [
        'collection_label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Collection label'),
          '#default_value' => (string) ($row['collection_label'] ?? ''),
          '#maxlength' => 180,
        ],
        'collection_guidance' => [
          '#type' => 'textarea',
          '#title' => $this->t('Collection guidance'),
          '#default_value' => (string) ($row['collection_guidance'] ?? ''),
          '#rows' => 3,
        ],
        'pickup_window_copy' => [
          '#type' => 'textarea',
          '#title' => $this->t('Pickup window copy'),
          '#default_value' => (string) ($row['pickup_window_copy'] ?? ''),
          '#rows' => 2,
        ],
        'customer_visibility' => [
          '#type' => 'select',
          '#title' => $this->t('Customer visibility'),
          '#options' => [
            'visible' => $this->t('Visible'),
            'hidden' => $this->t('Hidden'),
            'after_purchase' => $this->t('After purchase'),
          ],
          '#default_value' => (string) ($row['customer_visibility'] ?? 'visible'),
        ],
      ],
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => [
        'parking_guidance' => [
          '#type' => 'textarea',
          '#title' => $this->t('Parking guidance'),
          '#default_value' => (string) ($row['parking_guidance'] ?? ''),
          '#rows' => 3,
        ],
        'access_timing_copy' => [
          '#type' => 'textarea',
          '#title' => $this->t('Access timing copy'),
          '#default_value' => (string) ($row['access_timing_copy'] ?? ''),
          '#rows' => 2,
        ],
        'customer_visibility' => [
          '#type' => 'select',
          '#title' => $this->t('Customer visibility'),
          '#options' => [
            'visible' => $this->t('Visible'),
            'hidden' => $this->t('Hidden'),
            'after_purchase' => $this->t('After purchase'),
          ],
          '#default_value' => (string) ($row['customer_visibility'] ?? 'visible'),
        ],
      ],
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => [
        'bundle_label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Bundle label'),
          '#default_value' => (string) ($row['bundle_label'] ?? ''),
          '#maxlength' => 180,
        ],
        'grouped_capability_types' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Grouped capability types'),
          '#options' => $this->bundleCapabilityOptions(),
          '#default_value' => $this->defaultCheckboxesForKeys($row['grouped_capability_types'] ?? []),
        ],
        'customer_summary' => [
          '#type' => 'textarea',
          '#title' => $this->t('Customer summary'),
          '#default_value' => (string) ($row['customer_summary'] ?? ''),
          '#rows' => 3,
        ],
        'visibility' => [
          '#type' => 'select',
          '#title' => $this->t('Visibility'),
          '#options' => [
            'visible' => $this->t('Visible'),
            'hidden' => $this->t('Hidden'),
          ],
          '#default_value' => (string) ($row['visibility'] ?? 'visible'),
        ],
      ],
      default => [],
    };

    $fieldset['commerce_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Catalog path'),
      '#options' => [
        'link' => $this->t('Link existing product'),
        'create' => $this->t('Create new operational product'),
      ],
      '#default_value' => $product_id > 0 ? 'link' : 'create',
      '#attributes' => ['class' => ['mel-productisation-wizard-mode']],
    ];

    $fieldset['wizard_create'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-productisation-wizard-create']],
      '#states' => [
        'visible' => [
          ':input[name="' . $mode_input . '"]' => ['value' => 'create'],
        ],
      ],
      'notice' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Extras are saved when you press Save. Add product photos from the linked catalog product after creation if needed.'),
        '#attributes' => ['class' => ['mel-productisation-wizard-warning']],
      ],
      'customer_preview_label' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Customers see a card with image, title, price, description, and pickup note.'),
        '#attributes' => ['class' => ['mel-productisation-wizard-preview-hint']],
      ],
      'commerce_create_title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Extra title'),
        '#default_value' => '',
        '#maxlength' => 255,
        '#description' => $this->t('Plain text only. Defaults from the fields above when left blank.'),
      ],
      'commerce_create_customer_summary' => [
        '#type' => 'textarea',
        '#title' => $this->t('Short customer description'),
        '#rows' => 3,
        '#default_value' => '',
      ],
      'commerce_create_pickup_note' => [
        '#type' => 'textarea',
        '#title' => $this->t('Pickup note'),
        '#rows' => 2,
        '#default_value' => '',
        '#access' => $type === VendorProductisationStudioManager::TYPE_MERCHANDISE,
      ],
      'commerce_create_sizes' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Sizes offered'),
        '#options' => OperationalExtraVisualPresenter::SIZE_LABELS,
        '#default_value' => [],
        '#access' => $type === VendorProductisationStudioManager::TYPE_MERCHANDISE,
        '#description' => $this->t('Creates one purchasable option per size. Leave empty for a single one-size option.'),
      ],
      'commerce_create_price' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => NULL,
      ],
      'commerce_create_currency' => [
        '#type' => 'hidden',
        '#default_value' => $default_currency,
      ],
      'commerce_create_currency_display' => [
        '#type' => 'item',
        '#title' => $this->t('Currency'),
        '#markup' => '<p class="mel-text--muted">' . $this->t('Charges use your event store currency: @code', ['@code' => $default_currency]) . '</p>',
      ],
      'commerce_create_sku' => [
        '#type' => 'textfield',
        '#title' => $this->t('SKU (optional)'),
        '#maxlength' => 128,
        '#default_value' => '',
      ],
      'commerce_create_fulfillment_mode' => [
        '#type' => 'textfield',
        '#title' => $this->t('Fulfilment mode token (optional)'),
        '#default_value' => '',
        '#maxlength' => 64,
        '#access' => $type !== VendorProductisationStudioManager::TYPE_MERCHANDISE
          && $type !== VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE,
      ],
      'commerce_create_reservation_mode' => [
        '#type' => 'value',
        '#value' => 'operational_projection',
      ],
    ];

    $fieldset['wizard_link'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-productisation-wizard-link']],
      '#states' => [
        'visible' => [
          ':input[name="' . $mode_input . '"]' => ['value' => 'link'],
        ],
      ],
      'commerce_heading' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Commerce link (existing product)'),
        '#attributes' => ['class' => ['mel-productisation-editor__commerce-title']],
      ],
      'commerce_product' => [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Commerce product'),
        '#target_type' => 'commerce_product',
        '#max_length' => 512,
        '#default_value' => $product_id > 0 ? $this->entityTypeManager->getStorage('commerce_product')->load($product_id) : NULL,
      ],
      'commerce_product_id' => [
        '#type' => 'number',
        '#title' => $this->t('Commerce product ID'),
        '#min' => 0,
        '#default_value' => $product_id > 0 ? $product_id : NULL,
        '#description' => $this->t('Autocomplete fills this ID for autosave compatibility.'),
      ],
      'commerce_linkage_mode' => [
        '#type' => 'select',
        '#title' => $this->t('Variation linkage'),
        '#options' => [
          OperationalCapabilityCommerceLinkManager::LINKAGE_NONE => $this->t('None'),
          OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT => $this->t('Whole product'),
          OperationalCapabilityCommerceLinkManager::LINKAGE_VARIATIONS => $this->t('Specific variations'),
        ],
        '#default_value' => in_array($link_mode, [
          OperationalCapabilityCommerceLinkManager::LINKAGE_NONE,
          OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
          OperationalCapabilityCommerceLinkManager::LINKAGE_VARIATIONS,
        ], TRUE) ? $link_mode : OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
      ],
      'commerce_variations' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed variations'),
        '#options' => $this->buildVariationOptionsForProduct($product_id),
        '#default_value' => $var_defaults,
      ],
    ];

    return $fieldset;
  }

  private function defaultCommerceCurrencyForEvent(NodeInterface $event): string {
    $sid = $this->operationalCapabilityCommerceLinkManager->resolveStoreIdForOperationalProductCreation($event);
    if ($sid === NULL || $sid < 1) {
      return 'AUD';
    }
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($sid);
    if (!is_object($store) || !method_exists($store, 'getDefaultCurrencyCode')) {
      return 'AUD';
    }
    return (string) $store->getDefaultCurrencyCode();
  }

  /**
   * @param list<string>|mixed $keys
   *
   * @return array<string, string>
   */
  private function defaultCheckboxesForKeys(mixed $keys): array {
    if (!is_array($keys)) {
      return [];
    }
    $out = [];
    foreach ($keys as $k) {
      $k = (string) $k;
      if ($k !== '') {
        $out[$k] = $k;
      }
    }
    return $out;
  }

  /**
   * @return array<string, string>
   */
  private function bundleCapabilityOptions(): array {
    return [
      OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => $this->t('Merch pickup'),
      OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS => $this->t('Hospitality'),
      OperationalEntitlementCapabilityManager::TYPE_TIMED_COLLECTION => $this->t('Timed collection'),
      OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS => $this->t('Parking'),
      OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION => $this->t('Food & drink'),
    ];
  }

  private function typeEditorTitle(string $type): string {
    return match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => (string) $this->t('Hospitality package'),
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => (string) $this->t('Timed collection'),
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => (string) $this->t('Parking add-on'),
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => (string) $this->t('Operational bundle'),
      default => (string) $this->t('Merchandise'),
    };
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function collectItemsFromFormState(FormStateInterface $form_state): array {
    $mel = $form_state->getValue('mel') ?? [];
    $types = is_array($mel['productisation']['types'] ?? NULL) ? $mel['productisation']['types'] : [];
    $items = [];
    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $type) {
      $row = is_array($types[$type] ?? NULL) ? $types[$type] : [];
      $items[] = $this->mapRowToItem($type, $row);
    }
    return $items;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function mapRowToItem(string $type, array $row): array {
    $row = $this->vendorOperationalProductCreationManager->flattenProductisationWizardFormRow($row);
    $base = $this->vendorProductisationManager->emptyItem($type);
    $product_id = $this->extractCommerceProductIdFromFormRow($row);
    $mode = (string) ($row['commerce_linkage_mode'] ?? OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT);
    $variation_ids = [];
    if (is_array($row['commerce_variations'] ?? NULL)) {
      foreach ($row['commerce_variations'] as $vid => $on) {
        if (!empty($on) && (int) $vid > 0) {
          $variation_ids[] = (int) $vid;
        }
      }
    }
    $variation_ids = array_values(array_unique($variation_ids));
    $base['commerce'] = [
      'product_id' => $product_id,
      'variation_ids' => $variation_ids,
      'linkage_mode' => in_array($mode, [
        OperationalCapabilityCommerceLinkManager::LINKAGE_NONE,
        OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
        OperationalCapabilityCommerceLinkManager::LINKAGE_VARIATIONS,
      ], TRUE) ? $mode : OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
    ];
    $g = is_array($row['grouped_capability_types'] ?? NULL) ? $row['grouped_capability_types'] : [];
    $selected_caps = [];
    foreach ($g as $cap => $on) {
      if (!empty($on)) {
        $selected_caps[] = (string) $cap;
      }
    }
    return match ($type) {
      VendorProductisationStudioManager::TYPE_MERCHANDISE => $base + [
        'title' => (string) ($row['title'] ?? ''),
        'customer_summary' => (string) ($row['customer_summary'] ?? ''),
        'customer_visibility' => (string) ($row['customer_visibility'] ?? 'visible'),
        'pickup_mode' => (string) ($row['pickup_mode'] ?? 'counter'),
      ],
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => $base + [
        'package_name' => (string) ($row['package_name'] ?? ''),
        'benefits_summary' => (string) ($row['benefits_summary'] ?? ''),
        'customer_visibility' => (string) ($row['customer_visibility'] ?? 'visible'),
        'fulfillment_mode' => (string) ($row['fulfillment_mode'] ?? 'redeem'),
      ],
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => $base + [
        'collection_label' => (string) ($row['collection_label'] ?? ''),
        'collection_guidance' => (string) ($row['collection_guidance'] ?? ''),
        'pickup_window_copy' => (string) ($row['pickup_window_copy'] ?? ''),
        'customer_visibility' => (string) ($row['customer_visibility'] ?? 'visible'),
      ],
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => $base + [
        'parking_guidance' => (string) ($row['parking_guidance'] ?? ''),
        'access_timing_copy' => (string) ($row['access_timing_copy'] ?? ''),
        'customer_visibility' => (string) ($row['customer_visibility'] ?? 'visible'),
      ],
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => $base + [
        'bundle_label' => (string) ($row['bundle_label'] ?? ''),
        'grouped_capability_types' => $selected_caps,
        'customer_summary' => (string) ($row['customer_summary'] ?? ''),
        'visibility' => (string) ($row['visibility'] ?? 'visible'),
      ],
      default => $base,
    };
  }

  /**
   * @param array<string, mixed> $row
   */
  private function extractCommerceProductIdFromFormRow(array $row): int {
    $row = $this->vendorOperationalProductCreationManager->flattenProductisationWizardFormRow($row);
    $n = (int) ($row['commerce_product_id'] ?? 0);
    if ($n > 0) {
      return $n;
    }
    $ac = $row['commerce_product'] ?? '';
    if (is_array($ac) && isset($ac['target_id'])) {
      return (int) $ac['target_id'];
    }
    if (is_string($ac) && $ac !== '') {
      $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($ac);
      return $eid !== NULL ? (int) $eid : 0;
    }
    return 0;
  }

  /**
   * @return list<int>
   */
  private function normalizeVariationIds(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $vid) {
      $id = (int) $vid;
      if ($id > 0) {
        $out[] = $id;
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * @return array<string, string>
   */
  private function buildVariationOptionsForProduct(int $product_id): array {
    if ($product_id < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('product_id', $product_id)
      ->sort('variation_id')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $options = [];
    foreach ($storage->loadMultiple($ids) as $variation) {
      $options[(string) $variation->id()] = $variation->label();
    }
    return $options;
  }

  private function getRouteEvent(?NodeInterface $node = NULL): NodeInterface {
    if ($node instanceof NodeInterface) {
      return $node;
    }
    $route_node = $this->getRouteMatch()->getParameter('node');
    if ($route_node instanceof NodeInterface) {
      return $route_node;
    }
    throw new NotFoundHttpException();
  }

  private function assertCanManageEvent(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->currentUser->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }
  }

  private function loadSubmittedEvent(FormStateInterface $form_state): ?NodeInterface {
    $event_id = (int) ($form_state->getValue('event_id') ?? 0);
    if ($event_id < 1) {
      return NULL;
    }
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

}
