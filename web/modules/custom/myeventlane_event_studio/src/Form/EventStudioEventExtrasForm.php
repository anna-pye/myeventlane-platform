<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\EventExtrasBookPlacementResolver;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\myeventlane_event_studio\Service\EventStudioEventExtrasBuilder;
use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unified vendor Event Extras editor (Commerce-backed, vendor-language UI).
 */
final class EventStudioEventExtrasForm extends FormBase {

  /**
   * Protected (not private readonly) so form cache unserialization can restore services.
   *
   * @see EventStudioOperationalTicketsForm::ensureInjectedServices()
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected EventVendorAccessChecker $eventVendorAccessChecker;

  protected VendorOperationalProductCreationManager $productCreationManager;

  protected EventStudioEventExtrasBuilder $extrasBuilder;

  protected EventExtrasBookPlacementResolver $extrasBookPlacementResolver;

  protected LoggerInterface $logger;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EventVendorAccessChecker $event_vendor_access_checker,
    VendorOperationalProductCreationManager $product_creation_manager,
    EventStudioEventExtrasBuilder $extras_builder,
    EventExtrasBookPlacementResolver $extras_book_placement_resolver,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->eventVendorAccessChecker = $event_vendor_access_checker;
    $this->productCreationManager = $product_creation_manager;
    $this->extrasBuilder = $extras_builder;
    $this->extrasBookPlacementResolver = $extras_book_placement_resolver;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.vendor_operational_product_creation_manager'),
      $container->get('myeventlane_event_studio.event_extras_builder'),
      $container->get('myeventlane_commerce.event_extras_book_placement_resolver'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    parent::__wakeup();
    $this->ensureInjectedServices();
  }

  /**
   * Ensures services are present after form cache unserialization.
   */
  private function ensureInjectedServices(): void {
    if (isset($this->entityTypeManager, $this->eventVendorAccessChecker, $this->productCreationManager, $this->extrasBuilder, $this->extrasBookPlacementResolver, $this->logger)) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $container->get('entity_type.manager');
    }
    if (!isset($this->eventVendorAccessChecker)) {
      $this->eventVendorAccessChecker = $container->get('myeventlane_vendor.event_access_checker');
    }
    if (!isset($this->productCreationManager)) {
      $this->productCreationManager = $container->get('myeventlane_event_studio.vendor_operational_product_creation_manager');
    }
    if (!isset($this->extrasBuilder)) {
      $this->extrasBuilder = $container->get('myeventlane_event_studio.event_extras_builder');
    }
    if (!isset($this->extrasBookPlacementResolver)) {
      $this->extrasBookPlacementResolver = $container->get('myeventlane_commerce.event_extras_book_placement_resolver');
    }
    if (!isset($this->logger)) {
      $this->logger = $container->get('logger.factory')->get('myeventlane_event_studio');
    }
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_event_extras';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $this->ensureInjectedServices();
    $event = $this->getRouteEvent($node);
    $this->assertCanManageEvent($event);

    $request = $this->getRequest();
    $edit_id = (int) ($request?->query->get('extra') ?? 0);
    $add_type = (string) ($request?->query->get('add') ?? '');
    $action = (string) ($form_state->get('mel_extras_mode') ?? '');
    if ($action === '') {
      if ($edit_id > 0) {
        $action = 'edit';
      }
      elseif ($add_type !== '') {
        $action = 'add';
        $form_state->set('mel_extras_extra_type', $add_type);
      }
      else {
        $action = 'list';
      }
      $form_state->set('mel_extras_mode', $action);
    }

    $form['#attributes']['class'][] = 'mel-event-extras-studio';
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_extras_studio';

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-extras-studio__intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Event extras'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Offer merch, food vouchers, VIP packages, or pickup items guests can add when booking.'),
        '#attributes' => ['class' => ['mel-es-card__hint']],
      ],
    ];

    $mode = (string) $form_state->get('mel_extras_mode');
    if ($mode === 'edit' || $mode === 'add') {
      $form['editor'] = $this->buildEditor($form, $form_state, $event, $mode, $edit_id);
    }
    else {
      $form['list'] = $this->buildList($event);
      $form += $this->buildBookingPlacement($event);
      $form['probe'] = $this->buildProbe($event);
    }

    $form['footer_cta'] = $this->buildFooterCta($event);

    return $form;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildList(NodeInterface $event): array {
    $cards = $this->extrasBuilder->loadExtrasForEvent($event);
    $list = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__list']],
    ];
    if ($cards === []) {
      $list['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No extras yet. Choose a type below to add your first one.'),
        '#attributes' => ['class' => ['mel-event-extras-studio__empty', 'mel-text--muted']],
      ];
      return $list;
    }
    foreach ($cards as $card) {
      $pid = (int) ($card['product_id'] ?? 0);
      if ($pid < 1) {
        continue;
      }
      $show = !empty($card['show_on_booking']);
      $list['card_' . $pid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extra-card', 'mel-es-card']],
        'content' => [
          '#theme' => 'mel_event_studio_extra_card',
          '#card' => $card,
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-extra-actions']],
          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
              'query' => ['extra' => $pid],
            ]),
            '#attributes' => ['class' => ['button', 'mel-event-extra-card__edit']],
          ],
          'toggle_' . $pid => [
            '#type' => 'submit',
            '#value' => $show ? $this->t('Hide from booking') : $this->t('Show on booking'),
            '#name' => 'toggle_' . $pid,
            '#submit' => ['::submitToggleVisibility'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-event-extra-card__toggle', 'button']],
            '#product_id' => $pid,
          ],
        ],
      ];
    }
    return $list;
  }

  /**
   * Vendor control for where extras appear on the public book page.
   *
   * @return array<string, mixed>
   */
  private function buildBookingPlacement(NodeInterface $event): array {
    $cards = $this->extrasBuilder->loadExtrasForEvent($event);
    $published = array_filter($cards, static fn (array $card): bool => !empty($card['show_on_booking']));
    if ($published === []) {
      return ['booking_placement_wrapper' => ['#access' => FALSE]];
    }

    return [
      'booking_placement_wrapper' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Where guests see extras on the booking page'),
        '#attributes' => ['class' => ['mel-event-extras-studio__placement', 'mel-es-card']],
        'extras_book_placement' => [
          '#type' => 'radios',
          '#title' => $this->t('Placement'),
          '#title_display' => 'invisible',
          '#options' => $this->extrasBookPlacementResolver->getOptions(),
          '#default_value' => $this->extrasBookPlacementResolver->getPlacement($event),
          '#required' => TRUE,
          '#parents' => ['extras_book_placement'],
        ],
        'booking_placement_hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Applies to published extras that are shown on the booking page.'),
          '#attributes' => ['class' => ['mel-text--muted', 'mel-event-extras-studio__placement-hint']],
        ],
        'save_extras_placement' => [
          '#type' => 'submit',
          '#value' => $this->t('Save placement'),
          '#name' => 'save_extras_placement',
          '#submit' => ['::submitSaveBookingPlacement'],
          '#limit_validation_errors' => [['extras_book_placement']],
          '#attributes' => ['class' => ['button', 'mel-event-extras-studio__placement-save']],
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildProbe(NodeInterface $event): array {
    $choices = $this->extrasBuilder->extraTypeChoices();
    $items = [];
    foreach ($choices as $key => $label) {
      $items[$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
          'query' => ['add' => $key],
        ]),
        '#attributes' => [
          'class' => ['mel-event-extra-choice', 'mel-event-extra-choice--' . $key],
        ],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__probe', 'mel-es-card']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('What would you like to offer?'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'choices' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extra-choices']],
        '#children' => $items,
      ],
      'none' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No extras yet? You can skip this and add them later.'),
        '#attributes' => ['class' => ['mel-text--muted']],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEditor(array &$form, FormStateInterface $form_state, NodeInterface $event, string $mode, int $edit_id): array {
    $product = NULL;
    $defaults = [
      'extra_type' => (string) ($form_state->get('mel_extras_extra_type') ?? ''),
      'title' => '',
      'customer_summary' => '',
      'pickup_note' => '',
      'price_amount' => '',
      'show_on_booking' => 1,
      'sizes' => [],
    ];
    if ($mode === 'edit' && $edit_id > 0) {
      $product = $this->productCreationManager->assertVendorCanManageProduct($this->currentUser(), $event, $edit_id);
      $defaults = $this->defaultsFromProduct($product);
    }

    $editor = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#parents' => ['editor'],
      '#attributes' => ['class' => ['mel-event-extra-editor', 'mel-es-card']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← All extras'),
        '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()]),
        '#attributes' => ['class' => ['mel-event-extras-studio__back']],
      ],
      'product_id' => [
        '#type' => 'hidden',
        '#value' => $product instanceof ProductInterface ? (string) $product->id() : '',
      ],
    ];

    if ($product === NULL) {
      $editor['extra_type'] = [
        '#type' => 'radios',
        '#title' => $this->t('Extra type'),
        '#options' => $this->extrasBuilder->extraTypeChoices(),
        '#default_value' => $defaults['extra_type'] !== '' ? $defaults['extra_type'] : 'merchandise',
        '#required' => TRUE,
        '#attributes' => ['class' => ['mel-event-extra-type-radios']],
      ];
    }

    $editor['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extra name'),
      '#default_value' => $defaults['title'],
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $editor['customer_summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Short customer description'),
      '#default_value' => $defaults['customer_summary'],
      '#required' => TRUE,
      '#rows' => 3,
    ];
    $editor['pickup_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pickup / venue collection note'),
      '#default_value' => $defaults['pickup_note'],
      '#rows' => 2,
      '#description' => $this->t('E.g. “Collect at the merch desk after check-in.”'),
    ];
    $editor['price_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Price'),
      '#default_value' => $defaults['price_amount'],
      '#required' => TRUE,
      '#min' => 0,
      '#step' => 0.01,
    ];
    $editor['sizes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Sizes (merchandise)'),
      '#options' => OperationalExtraVisualPresenter::SIZE_LABELS,
      '#default_value' => $defaults['sizes'],
      '#description' => $this->t('Creates one purchasable option per size. Leave empty for a single option.'),
    ];
    $editor['show_on_booking'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show on booking page'),
      '#default_value' => (bool) $defaults['show_on_booking'],
    ];

    if ($product instanceof ProductInterface) {
      $editor['preview'] = [
        '#theme' => 'mel_event_studio_extra_preview',
        '#preview' => $this->extrasBuilder->buildPreviewRow($product, $event),
      ];
    }
    else {
      $editor['images_notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('You can add photos after saving this extra.'),
        '#attributes' => ['class' => ['mel-text--muted']],
      ];
    }

    if ($this->currentUser()->hasPermission('administer commerce_product')) {
      $pid = $product instanceof ProductInterface ? (int) $product->id() : 0;
      if ($pid > 0) {
        $editor['admin_commerce'] = [
          '#type' => 'link',
          '#title' => $this->t('Advanced Commerce edit'),
          '#url' => Url::fromRoute('entity.commerce_product.edit_form', ['commerce_product' => $pid]),
          '#attributes' => [
            'class' => ['mel-event-extras-studio__admin-link'],
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
          ],
        ];
      }
    }

    $editor['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save extra'),
        '#button_type' => 'primary',
      ],
      'add_another' => [
        '#type' => 'submit',
        '#value' => $this->t('Save and add another'),
        '#submit' => ['::submitAddAnother'],
      ],
    ];

    if ($product instanceof ProductInterface) {
      $this->attachImagesWidget($product, $form, $editor, $form_state);
    }

    return $editor;
  }

  /**
   * Attaches the Commerce media_library widget (EntityFormDisplay pattern).
   */
  private function attachImagesWidget(ProductInterface $product, array &$form, array &$editor, FormStateInterface $form_state): void {
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
    if (!isset($editor['#parents'])) {
      $editor['#parents'] = ['editor'];
    }
    if (!isset($editor['#tree'])) {
      $editor['#tree'] = TRUE;
    }
    $editor['field_mel_extra_images'] = $widget->form(
      $product->get('field_mel_extra_images'),
      $editor,
      $form_state,
    );
    $editor['field_mel_extra_images']['#title'] = $this->t('Images');
    $editor['field_mel_extra_images']['#attributes']['class'][] = 'mel-event-extra-gallery';
  }

  /**
   * @return array<string, mixed>
   */
  private function buildFooterCta(NodeInterface $event): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__footer-cta']],
      'preview_booking' => [
        '#type' => 'link',
        '#title' => $this->t('Preview booking page'),
        '#url' => Url::fromRoute('entity.node.canonical', ['node' => $event->id()]),
        '#attributes' => ['class' => ['button', 'mel-event-extras-studio__cta']],
      ],
      'publish' => [
        '#type' => 'link',
        '#title' => $this->t('Continue to publish'),
        '#url' => Url::fromRoute('myeventlane_event_studio.edit_publish', ['node' => $event->id()]),
        '#attributes' => ['class' => ['button', 'button--primary', 'mel-event-extras-studio__cta']],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function defaultsFromProduct(ProductInterface $product): array {
    $sizes = [];
    foreach ($product->getVariations() as $variation) {
      if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty() && $variation->isPublished()) {
        $key = strtolower((string) $variation->get('field_mel_size')->value);
        $sizes[$key] = $key;
      }
    }
    $price = '';
    foreach ($product->getVariations() as $variation) {
      if ($variation->isPublished() && $variation->getPrice() !== NULL) {
        $price = $variation->getPrice()->getNumber();
        break;
      }
    }
    $short = $product->hasField('field_mel_extra_short_desc') && !$product->get('field_mel_extra_short_desc')->isEmpty()
      ? (string) $product->get('field_mel_extra_short_desc')->value
      : '';
    $pickup = $product->hasField('field_mel_extra_pickup_note') && !$product->get('field_mel_extra_pickup_note')->isEmpty()
      ? (string) $product->get('field_mel_extra_pickup_note')->value
      : '';
    return [
      'extra_type' => '',
      'title' => $product->label(),
      'customer_summary' => $short,
      'pickup_note' => $pickup,
      'price_amount' => $price,
      'show_on_booking' => $product->isPublished() ? 1 : 0,
      'sizes' => $sizes,
    ];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }
    $trigger = $form_state->getTriggeringElement();
    if (!is_array($trigger)) {
      return;
    }
    if (str_starts_with((string) ($trigger['#name'] ?? ''), 'toggle_')) {
      return;
    }
    if ($this->isPlacementSaveTrigger($trigger)) {
      return;
    }
    if (($trigger['#type'] ?? '') !== 'submit') {
      return;
    }
    $input = $this->collectInput($form_state);
    foreach ($this->productCreationManager->validateEventExtraInput($this->currentUser(), $event, $input) as $error) {
      $form_state->setErrorByName('', $error);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && $this->isPlacementSaveTrigger($trigger)) {
      return;
    }
    $this->saveExtra($form_state, FALSE);
  }

  public function submitAddAnother(array &$form, FormStateInterface $form_state): void {
    $this->saveExtra($form_state, TRUE);
  }

  public function submitSaveBookingPlacement(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }
    $placement = $this->getSubmittedExtrasBookPlacement($form_state);
    if (!$this->extrasBookPlacementResolver->isValid($placement)) {
      $this->logger->warning('Extras book placement submit had invalid value @value for event @nid', [
        '@value' => $placement === '' ? '(empty)' : $placement,
        '@nid' => (string) $event->id(),
      ]);
      $this->messenger()->addError($this->t('Choose a valid placement option.'));
      return;
    }
    try {
      $this->extrasBookPlacementResolver->savePlacement($event, $placement);
      $this->logger->notice('Extras book placement saved for event @nid: @placement', [
        '@nid' => (string) $event->id(),
        '@placement' => $placement,
      ]);
      $this->messenger()->addStatus($this->t('Booking page placement saved.'));
    }
    catch (\Throwable $e) {
      $this->logger->error('Extras book placement save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not save placement.'));
    }
    $form_state->setRedirect('myeventlane_event_studio.workspace_extras', ['node' => $event->id()]);
  }

  /**
   * Reads placement from form state / user input (flat radios element).
   */
  private function getSubmittedExtrasBookPlacement(FormStateInterface $form_state): string {
    $value = $form_state->getValue('extras_book_placement');
    if (is_string($value) && $value !== '') {
      return $value;
    }
    $input = $form_state->getUserInput();
    if (isset($input['extras_book_placement']) && is_string($input['extras_book_placement'])) {
      return $input['extras_book_placement'];
    }
    return '';
  }

  /**
   * @param array<string, mixed> $trigger
   */
  private function isPlacementSaveTrigger(array $trigger): bool {
    if (($trigger['#name'] ?? '') === 'save_extras_placement') {
      return TRUE;
    }
    foreach ((array) ($trigger['#submit'] ?? []) as $handler) {
      if ($handler === '::submitSaveBookingPlacement') {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function submitToggleVisibility(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      return;
    }
    $trigger = $form_state->getTriggeringElement();
    $pid = (int) ($trigger['#product_id'] ?? 0);
    if ($pid < 1) {
      return;
    }
    try {
      $product = $this->productCreationManager->assertVendorCanManageProduct($this->currentUser(), $event, $pid);
      $show = !$product->isPublished();
      $product->setPublished($show);
      $product->save();
      $this->messenger()->addStatus($show
        ? $this->t('“@title” is now shown on the booking page.', ['@title' => $product->label()])
        : $this->t('“@title” is hidden from the booking page.', ['@title' => $product->label()]));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('myeventlane_event_studio.workspace_extras', ['node' => $event->id()]);
  }

  private function saveExtra(FormStateInterface $form_state, bool $add_another): void {
    $this->ensureInjectedServices();
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }
    try {
      $input = $this->collectInput($form_state);
      $product = $this->productCreationManager->saveEventExtraForVendor($this->currentUser(), $event, $input);
      $this->extractImagesOntoProduct($form_state, $product);
      $this->messenger()->addStatus($this->t('Your extra “@title” is saved. Guests can add it when booking.', [
        '@title' => $product->label(),
      ]));
      if ($add_another) {
        $form_state->setRedirect('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
          'query' => ['add' => 'merchandise'],
        ]);
        return;
      }
      $form_state->setRedirect('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
        'query' => ['extra' => (int) $product->id()],
      ]);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    catch (\Throwable $e) {
      $this->logger->error('Event extra save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not save this extra.'));
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function collectInput(FormStateInterface $form_state): array {
    $sizes = [];
    foreach ((array) ($this->editorValue($form_state, 'sizes') ?? []) as $key => $on) {
      if (!empty($on)) {
        $sizes[] = (string) $key;
      }
    }
    $image_media_ids = $this->extractImageMediaIds($form_state, ['editor', 'field_mel_extra_images']);
    if ($image_media_ids === []) {
      $image_media_ids = $this->extractImageMediaIds($form_state, 'field_mel_extra_images');
    }
    return [
      'product_id' => (int) ($this->editorValue($form_state, 'product_id') ?? 0),
      'extra_type' => (string) ($this->editorValue($form_state, 'extra_type') ?? ''),
      'title' => (string) ($this->editorValue($form_state, 'title') ?? ''),
      'customer_summary' => (string) ($this->editorValue($form_state, 'customer_summary') ?? ''),
      'pickup_note' => (string) ($this->editorValue($form_state, 'pickup_note') ?? ''),
      'price_amount' => $this->editorValue($form_state, 'price_amount'),
      'sizes' => $sizes,
      'show_on_booking' => !empty($this->editorValue($form_state, 'show_on_booking')),
      'image_media_ids' => $image_media_ids,
    ];
  }

  /**
   * Reads a value from the editor subtree (#tree + #parents).
   */
  private function editorValue(FormStateInterface $form_state, string $key): mixed {
    $editor = $form_state->getValue('editor');
    if (is_array($editor) && array_key_exists($key, $editor)) {
      return $editor[$key];
    }
    return $form_state->getValue($key);
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
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      throw new AccessDeniedHttpException();
    }
  }

  private function extractImagesOntoProduct(FormStateInterface $form_state, ProductInterface $product): void {
    $display_id = 'commerce_product.' . $product->bundle() . '.default';
    $form_display = EntityFormDisplay::load($display_id);
    if ($form_display === NULL || !$product->hasField('field_mel_extra_images')) {
      return;
    }
    $complete = $form_state->getCompleteForm();
    if (!is_array($complete) || !isset($complete['editor']['field_mel_extra_images'])) {
      return;
    }
    $form_display->extractFormValues($product, $complete['editor'], $form_state);
    $product->save();
  }

  /**
   * @param string|list<string> $key
   *
   * @return list<int>
   */
  private function extractImageMediaIds(FormStateInterface $form_state, string|array $key = 'field_mel_extra_images'): array {
    $images_value = $form_state->getValue($key);
    $ids = [];
    if (!is_array($images_value)) {
      return $ids;
    }
    $walker = function (mixed $value) use (&$walker, &$ids): void {
      if (is_array($value)) {
        if (isset($value['target_id']) && is_numeric($value['target_id'])) {
          $id = (int) $value['target_id'];
          if ($id > 0) {
            $ids[$id] = $id;
          }
        }
        foreach ($value as $child) {
          $walker($child);
        }
      }
    };
    $walker($images_value);
    return array_values($ids);
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
