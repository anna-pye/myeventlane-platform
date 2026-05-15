<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Event Studio operational capability authoring (metadata-only).
 */
final class EventStudioOperationalCapabilityForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalCapabilityStudioManager $capabilityStudioManager,
    private readonly OperationalCapabilityStudioBuilder $capabilityStudioBuilder,
    private readonly OperationalCapabilityPreviewBuilder $capabilityPreviewBuilder,
    private readonly OperationalCapabilityCommerceLinkManager $operationalCapabilityCommerceLinkManager,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_event_studio.operational_capability_studio_manager'),
      $container->get('myeventlane_event_studio.operational_capability_studio_builder'),
      $container->get('myeventlane_event_studio.operational_capability_preview_builder'),
      $container->get('myeventlane_event_studio.operational_capability_commerce_link_manager'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('current_user'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_operational_capability';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $event = $this->getRouteEvent($node);
    $this->assertCanManageEvent($event);

    $document = $this->capabilityStudioManager->extractFromEvent($event);
    $draft = $this->autosaveService->getDraft($event, 'fulfilment');
    if ($draft !== NULL && isset($draft['mel']['operational_capabilities']['items_state'])) {
      $document = $this->capabilityStudioManager->normalizeMelFragment($draft['mel'], $event);
    }

    $cards = $this->capabilityStudioBuilder->buildWorkspaceCards($document);
    $customer_preview = $this->capabilityPreviewBuilder->buildCustomerPreview($document);
    $readiness_preview = $this->capabilityPreviewBuilder->buildOperationalReadinessPreview($document);

    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'mel-event-studio-operational-capabilities';
    $form['#attributes']['data-mel-operational-capability-studio'] = '1';
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_operational_capability_studio';

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];
    $form['mel_studio_section'] = [
      '#type' => 'hidden',
      '#value' => 'fulfilment',
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
      '#attributes' => ['class' => ['mel-es-card', 'mel-operational-capability-intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Operational capabilities'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Configure what guests can redeem or collect on site. This is authoring metadata only — scanners and fulfilment systems run your live operational rules at the venue.'),
        '#attributes' => ['class' => ['mel-es-card__hint']],
      ],
    ];

    $form['mel'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    $form['mel']['operational_capabilities'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        '#attributes' => ['class' => ['js-mel-operational-capabilities-state']],
      ],
    ];

    $form['capability_workspace'] = [
      '#theme' => 'mel_event_studio_operational_capabilities',
      '#cards' => $cards,
      '#customer_preview' => $customer_preview,
      '#readiness_preview' => $readiness_preview,
      '#ui_metadata' => $this->capabilityStudioManager->getCapabilityUiMetadata(),
      '#weight' => 10,
    ];

    $form['capability_editors'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-operational-capability-editors']],
      '#weight' => 15,
    ];
    $capabilities = is_array($document['capabilities'] ?? NULL) ? $document['capabilities'] : [];
    $ui = $this->capabilityStudioManager->getCapabilityUiMetadata();
    foreach ($this->capabilityStudioManager->allAuthoringCapabilityTypes() as $type) {
      $row = is_array($capabilities[$type] ?? NULL) ? $capabilities[$type] : [];
      $label = (string) ($ui[$type]['label'] ?? $type);
      $form['capability_editors'][$type] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-operational-capability-editor'],
          'data-capability-type' => $type,
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $label,
          '#attributes' => ['class' => ['mel-operational-capability-editor__title']],
        ],
        'enabled' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable @label', ['@label' => $label]),
          '#default_value' => !empty($row['enabled']),
          '#attributes' => ['data-cap-field' => 'enabled'],
        ],
        'fulfillment_mode' => [
          '#type' => 'select',
          '#title' => $this->t('Fulfillment style'),
          '#options' => $this->fulfillmentModeOptions(),
          '#default_value' => (string) ($row['fulfillment_mode'] ?? 'none'),
          '#attributes' => ['data-cap-field' => 'fulfillment_mode'],
        ],
        'reservation_mode' => [
          '#type' => 'select',
          '#title' => $this->t('Reservation style'),
          '#options' => $this->reservationModeOptions(),
          '#default_value' => (string) ($row['reservation_mode'] ?? 'digital_redemption'),
          '#attributes' => ['data-cap-field' => 'reservation_mode'],
        ],
        'timed_entry' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Timed entry required'),
          '#default_value' => !empty($row['timed_entry']),
          '#attributes' => ['data-cap-field' => 'timed_entry'],
        ],
        'customer_visibility' => [
          '#type' => 'select',
          '#title' => $this->t('Guest visibility'),
          '#options' => [
            'after_purchase' => $this->t('After purchase'),
            'visible' => $this->t('Visible on event page'),
            'hidden' => $this->t('Hidden from guests'),
          ],
          '#default_value' => (string) ($row['customer_visibility'] ?? 'after_purchase'),
          '#attributes' => ['data-cap-field' => 'customer_visibility'],
        ],
        'pickup_mode' => [
          '#type' => 'select',
          '#title' => $this->t('Pickup mode'),
          '#options' => $this->pickupModeOptions(),
          '#default_value' => (string) ($row['pickup_mode'] ?? 'none'),
          '#attributes' => ['data-cap-field' => 'pickup_mode'],
        ],
        'continuity_mode' => [
          '#type' => 'select',
          '#title' => $this->t('Continuity mode'),
          '#options' => $this->continuityModeOptions(),
          '#default_value' => (string) ($row['continuity_mode'] ?? 'online'),
          '#attributes' => ['data-cap-field' => 'continuity_mode'],
        ],
      ];

      if ($this->operationalCapabilityCommerceLinkManager->supportsCommerceLinkage($type)) {
        $link = is_array($row['commerce_linkage'] ?? NULL) ? $row['commerce_linkage'] : [];
        $product_id = (int) ($link['product_id'] ?? 0);
        $variation_ids = [];
        foreach ((array) ($link['variation_ids'] ?? []) as $vid) {
          $vid = (int) $vid;
          if ($vid > 0) {
            $variation_ids[] = $vid;
          }
        }
        $variation_ids = array_values(array_unique($variation_ids));
        $link_mode = (string) ($link['linkage_mode'] ?? OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT);
        $var_defaults = [];
        foreach ($variation_ids as $vid) {
          $var_defaults[(string) $vid] = (string) $vid;
        }
        $form['capability_editors'][$type]['commerce_heading'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Commerce linkage'),
          '#attributes' => ['class' => ['mel-operational-capability-editor__commerce-title']],
        ];
        $form['capability_editors'][$type]['commerce_product'] = [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Find Commerce product'),
          '#target_type' => 'commerce_product',
          '#max_length' => 512,
          '#default_value' => $product_id > 0 ? $this->entityTypeManager->getStorage('commerce_product')->load($product_id) : NULL,
          '#attributes' => ['class' => ['mel-cap-commerce-autocomplete']],
        ];
        $form['capability_editors'][$type]['commerce_product_id'] = [
          '#type' => 'number',
          '#title' => $this->t('Commerce product ID'),
          '#min' => 0,
          '#default_value' => $product_id > 0 ? $product_id : NULL,
          '#description' => $this->t('Required for autosave. The autocomplete fills this when you select a product.'),
          '#attributes' => ['data-cap-field' => 'commerce_linkage.product_id'],
        ];
        $form['capability_editors'][$type]['commerce_linkage_mode'] = [
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
          '#attributes' => ['data-cap-field' => 'commerce_linkage.linkage_mode'],
        ];
        $form['capability_editors'][$type]['commerce_variations'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Allowed variations'),
          '#options' => $this->buildVariationOptionsForProduct($product_id),
          '#default_value' => $var_defaults,
          '#attributes' => ['class' => ['mel-cap-commerce-variations']],
        ];
        $form['capability_editors'][$type]['commerce_link_visibility'] = [
          '#type' => 'select',
          '#title' => $this->t('Linkage visibility'),
          '#options' => [
            'inherit' => $this->t('Inherit from capability'),
            'hidden' => $this->t('Hidden'),
            'visible' => $this->t('Visible'),
            'after_purchase' => $this->t('After purchase'),
          ],
          '#default_value' => (string) ($link['customer_visibility'] ?? 'inherit'),
          '#attributes' => ['data-cap-field' => 'commerce_linkage.customer_visibility'],
        ];
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save capabilities'),
        '#button_type' => 'primary',
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

    $document = $this->decodeSubmittedDocument($form_state, $event);
    $errors = $this->capabilityStudioManager->validateDocument($event, $document);
    foreach ($errors as $error) {
      $form_state->setErrorByName('', $error);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }

    try {
      $document = $this->decodeSubmittedDocument($form_state, $event);
      $this->capabilityStudioManager->persistToEvent($event, $document);
      EventNodeRevisionSave::prepare($event, 'Event Studio operational capability authoring save.');
      $event->save();
      $this->autosaveService->clearDraft($event, 'fulfilment');
      $this->messenger()->addStatus($this->t('Operational capabilities saved.'));
    }
    catch (\Throwable $e) {
      $this->logger->error('Operational capability save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not save operational capabilities.'));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * @return array<string, mixed>
   */
  private function decodeSubmittedDocument(FormStateInterface $form_state, NodeInterface $event): array {
    $mel = $form_state->getValue('mel') ?? [];
    if (!is_array($mel)) {
      $mel = [];
    }
    $editors = $form_state->getValue('capability_editors') ?? [];
    if (is_array($editors) && $editors !== []) {
      $capabilities = [];
      foreach ($this->capabilityStudioManager->allAuthoringCapabilityTypes() as $type) {
        $row = is_array($editors[$type] ?? NULL) ? $editors[$type] : [];
        $capabilities[$type] = [
          'capability_type' => $type,
          'enabled' => !empty($row['enabled']),
          'fulfillment_mode' => (string) ($row['fulfillment_mode'] ?? ''),
          'reservation_mode' => (string) ($row['reservation_mode'] ?? ''),
          'timed_entry' => !empty($row['timed_entry']),
          'customer_visibility' => (string) ($row['customer_visibility'] ?? ''),
          'pickup_mode' => (string) ($row['pickup_mode'] ?? ''),
          'continuity_mode' => (string) ($row['continuity_mode'] ?? ''),
        ];
        $draft_linkage = $this->extractCommerceLinkageDraftFromEditorRow($type, $row);
        if ($draft_linkage !== []) {
          $capabilities[$type]['commerce_linkage'] = $draft_linkage;
        }
      }
      $mel['mel_operational_capabilities'] = [
        'schema_version' => OperationalCapabilityStudioManager::SCHEMA_VERSION,
        'capabilities' => $capabilities,
      ];
      $base = $this->capabilityStudioManager->extractFromEvent($event);
      if (isset($base['operational_merchandise'])) {
        $mel['mel_operational_capabilities']['operational_merchandise'] = $base['operational_merchandise'];
      }
    }
    return $this->capabilityStudioManager->normalizeMelFragment($mel, $event);
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function extractCommerceLinkageDraftFromEditorRow(string $type, array $row): array {
    if (!$this->operationalCapabilityCommerceLinkManager->supportsCommerceLinkage($type)) {
      return [];
    }
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
    return [
      'product_id' => $product_id,
      'linkage_mode' => in_array($mode, [
        OperationalCapabilityCommerceLinkManager::LINKAGE_NONE,
        OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
        OperationalCapabilityCommerceLinkManager::LINKAGE_VARIATIONS,
      ], TRUE) ? $mode : OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
      'variation_ids' => $variation_ids,
      'customer_visibility' => (string) ($row['commerce_link_visibility'] ?? 'inherit'),
    ];
  }

  /**
   * @param array<string, mixed> $row
   */
  private function extractCommerceProductIdFromFormRow(array $row): int {
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
      ->sort('id')
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

  private function loadSubmittedEvent(FormStateInterface $form_state): ?NodeInterface {
    $event_id = (int) ($form_state->getValue('event_id') ?? 0);
    if ($event_id < 1) {
      return NULL;
    }
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

  /**
   * @return array<string, string>
   */
  private function fulfillmentModeOptions(): array {
    return [
      'none' => (string) $this->t('None'),
      'collect' => (string) $this->t('Collect on site'),
      'redeem' => (string) $this->t('Redeem at venue'),
      'optional' => (string) $this->t('Optional fulfilment'),
    ];
  }

  /**
   * @return array<string, string>
   */
  private function reservationModeOptions(): array {
    return $this->capabilityStudioManager->allowedReservationModeOptions();
  }

  /**
   * @return array<string, string>
   */
  private function pickupModeOptions(): array {
    return [
      'none' => (string) $this->t('None'),
      'counter' => (string) $this->t('Counter'),
      'locker' => (string) $this->t('Locker'),
      'staff_escort' => (string) $this->t('Staff escort'),
    ];
  }

  /**
   * @return array<string, string>
   */
  private function continuityModeOptions(): array {
    return [
      'online' => (string) $this->t('Online-first'),
      'offline_eligible' => (string) $this->t('Offline eligible'),
      'degraded' => (string) $this->t('Degraded continuity'),
    ];
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

}
