<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_commerce\Service\EventExtrasBookPlacementResolver;
use Drupal\myeventlane_event_studio\Service\EventStudioEventExtrasBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioExtrasProductEditorBuilder;
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

  protected EventStudioExtrasProductEditorBuilder $productEditorBuilder;

  protected EventExtrasBookPlacementResolver $extrasBookPlacementResolver;

  protected LoggerInterface $logger;

  protected ?DomainDetector $domainDetector = NULL;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EventVendorAccessChecker $event_vendor_access_checker,
    VendorOperationalProductCreationManager $product_creation_manager,
    EventStudioEventExtrasBuilder $extras_builder,
    EventStudioExtrasProductEditorBuilder $product_editor_builder,
    EventExtrasBookPlacementResolver $extras_book_placement_resolver,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->eventVendorAccessChecker = $event_vendor_access_checker;
    $this->productCreationManager = $product_creation_manager;
    $this->extrasBuilder = $extras_builder;
    $this->productEditorBuilder = $product_editor_builder;
    $this->extrasBookPlacementResolver = $extras_book_placement_resolver;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.vendor_operational_product_creation_manager'),
      $container->get('myeventlane_event_studio.event_extras_builder'),
      $container->get('myeventlane_event_studio.extras_product_editor_builder'),
      $container->get('myeventlane_commerce.event_extras_book_placement_resolver'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
    $form->domainDetector = $container->get('myeventlane_core.domain_detector');
    return $form;
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
    if (isset($this->entityTypeManager, $this->eventVendorAccessChecker, $this->productCreationManager, $this->extrasBuilder, $this->productEditorBuilder, $this->extrasBookPlacementResolver, $this->logger)) {
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
    if (!isset($this->productEditorBuilder)) {
      $this->productEditorBuilder = $container->get('myeventlane_event_studio.extras_product_editor_builder');
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
        if ($this->productCreationManager->normalizeVendorExtraType($add_type) === '') {
          throw new NotFoundHttpException();
        }
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

    $mode = (string) $form_state->get('mel_extras_mode');
    if ($mode !== 'list') {
      $form['#attributes']['class'][] = 'mel-event-extras-studio--editor';
    }

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-extras-studio__intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Merch & add-ons'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Sell event extras like parking, meal packages, T-shirts, or VIP upgrades alongside your tickets.'),
        '#attributes' => ['class' => ['mel-es-card__hint']],
      ],
      'safety' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extras-studio__safety']],
        'items' => [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('Merch and add-ons are sold through the same checkout.'),
            $this->t('They do not count as event tickets.'),
            $this->t('Fulfilment and stock controls are still being improved.'),
          ],
        ],
      ],
      'ticket_note' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Tickets stay separate. Extras do not create admission tickets.'),
        '#attributes' => ['class' => ['mel-text--muted', 'mel-event-extras-studio__ticket-note']],
      ],
    ];

    if ($mode === 'edit' || $mode === 'add') {
      $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_extras_product_editor';
      $form['editor'] = $this->productEditorBuilder->buildEditor(
        $form,
        $form_state,
        $event,
        $this->currentUser(),
        $mode,
        $edit_id,
      );
      $form['intro']['#access'] = FALSE;
    }
    else {
      $form['list'] = $this->buildList($event);
      $form += $this->buildBookingPlacement($event);
      $form['probe'] = $this->buildProbe($event);
      $form['footer_cta'] = $this->buildFooterCta($event);
    }

    return $form;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildList(NodeInterface $event): array {
    $request = $this->getRequest();
    $filter = (string) ($request?->query->get('filter') ?? 'all');
    if (!array_key_exists($filter, $this->extrasBuilder->listFilterOptions())) {
      $filter = 'all';
    }
    $cards = $this->extrasBuilder->loadExtrasForEventByClassification($event, $filter);
    $all_count = count($this->extrasBuilder->loadExtrasForEvent($event));

    $list = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__list']],
    ];

    $list['tabs'] = $this->buildListFilterTabs($event, $filter);

    if ($all_count === 0) {
      $list['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extras-studio__empty-state', 'mel-es-card']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Add merch or extras to this event'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Sell event extras like parking, meal packages, T-shirts, or VIP upgrades alongside your tickets.'),
          '#attributes' => ['class' => ['mel-text--muted']],
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Add merch or add-on'),
          '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
            'query' => ['add' => 'merchandise'],
          ]),
          '#attributes' => ['class' => ['button', 'button--primary', 'mel-event-extras-studio__empty-cta']],
        ],
      ];
      return $list;
    }

    if ($cards === []) {
      $list['filter_empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No items in this tab yet.'),
        '#attributes' => ['class' => ['mel-text--muted']],
      ];
      return $list;
    }

    foreach ($cards as $card) {
      $pid = (int) ($card['product_id'] ?? 0);
      if ($pid < 1) {
        continue;
      }
      $show = !empty($card['show_on_booking']);
      $book_url = (string) ($card['book_page_url'] ?? '');
      $list['card_' . $pid] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'mel-event-extra-card',
            'mel-es-card',
            'mel-event-extra-card--' . (string) ($card['classification'] ?? 'addon'),
          ],
        ],
        'content' => [
          '#theme' => 'mel_event_studio_extra_card',
          '#card' => $card,
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-extra-actions']],
          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('Edit product'),
            '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
              'query' => ['extra' => $pid],
            ]),
            '#attributes' => ['class' => ['button', 'mel-event-extra-card__edit', 'mel-btn']],
          ],
          'toggle_' . $pid => [
            '#type' => 'submit',
            '#value' => $show ? $this->t('Hide from booking page') : $this->t('Show on booking page'),
            '#name' => 'toggle_' . $pid,
            '#submit' => ['::submitToggleVisibility'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['mel-event-extra-card__toggle', 'button', 'mel-btn', 'mel-btn--secondary']],
            '#product_id' => $pid,
          ],
        ],
      ];
      if ($show && $book_url !== '') {
        $list['card_' . $pid]['actions']['view_booking'] = [
          '#type' => 'link',
          '#title' => $this->t('View on booking page'),
          '#url' => str_starts_with($book_url, '/') ? Url::fromUserInput($book_url) : Url::fromUri($book_url),
          '#attributes' => [
            'class' => ['button', 'mel-event-extra-card__view', 'mel-btn', 'mel-btn--secondary'],
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
          ],
        ];
      }
    }

    $list['add_more'] = [
      '#type' => 'link',
      '#title' => $this->t('Add merch or add-on'),
      '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
        'query' => ['add' => 'merchandise'],
      ]),
      '#attributes' => ['class' => ['button', 'button--primary', 'mel-event-extras-studio__add-more']],
    ];

    return $list;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildListFilterTabs(NodeInterface $event, string $active): array {
    $items = [];
    foreach ($this->extrasBuilder->listFilterOptions() as $key => $label) {
      $items[$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
          'query' => $key === 'all' ? [] : ['filter' => $key],
        ]),
        '#attributes' => [
          'class' => array_filter([
            'mel-event-extras-studio__tab',
            $key === $active ? 'is-active' : NULL,
          ]),
        ],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__tabs']],
      '#children' => $items,
    ];
  }

  /**
   * Vendor control for where extras appear on the public book page.
   *
   * @return array<string, mixed>
   */
  private function buildBookingPlacement(NodeInterface $event): array {
    if (!$event->hasField(EventExtrasBookPlacementResolver::FIELD_NAME)) {
      if ($this->currentUser()->hasPermission('administer nodes')) {
        return [
          'booking_placement_wrapper' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['messages', 'messages--warning']],
            'message' => [
              '#markup' => '<p>' . $this->t('Extras booking placement is not configured on this site. Import the <code>field_mel_extras_book_placement</code> field configuration, then reload this page.') . '</p>',
            ],
          ],
        ];
      }
      return ['booking_placement_wrapper' => ['#access' => FALSE]];
    }

    $cards = $this->extrasBuilder->loadExtrasForEvent($event);
    $published = array_filter($cards, static fn (array $card): bool => !empty($card['show_on_booking']));
    if ($published === []) {
      return ['booking_placement_wrapper' => ['#access' => FALSE]];
    }

    return [
      'booking_placement_wrapper' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extras-studio__placement', 'mel-es-card']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Where guests see extras on the booking page'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Applies to published extras that are shown on the booking page.'),
          '#attributes' => ['class' => ['mel-text--muted', 'mel-event-extras-studio__placement-hint']],
        ],
        'extras_book_placement' => [
          '#type' => 'radios',
          '#title' => $this->t('Placement'),
          '#title_display' => 'invisible',
          '#options' => $this->extrasBookPlacementResolver->getOptions(),
          '#default_value' => $this->extrasBookPlacementResolver->resolve($event),
          '#required' => TRUE,
          '#parents' => ['extras_book_placement'],
        ],
        'save_extras_placement' => [
          '#type' => 'submit',
          '#value' => $this->t('Save placement'),
          '#name' => 'save_extras_placement',
          '#button_type' => 'primary',
          '#submit' => ['::submitSaveBookingPlacement'],
          '#limit_validation_errors' => [['extras_book_placement']],
          '#executes_submit_callback' => TRUE,
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildProbe(NodeInterface $event): array {
    $merch_items = [];
    foreach ($this->extrasBuilder->merchandiseTypeChoices() as $key => $label) {
      $merch_items[$key] = $this->buildExtraTypeChoiceLink($event, $key, $label, 'merchandise');
    }
    $addon_items = [];
    foreach ($this->extrasBuilder->addonTypeChoices() as $key => $label) {
      $addon_items[$key] = $this->buildExtraTypeChoiceLink($event, $key, $label, 'addon');
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-extras-studio__probe']],
      'merch' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extras-studio__probe-panel', 'mel-es-card']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Merchandise'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('T-shirts, hoodies, posters, or digital items.'),
          '#attributes' => ['class' => ['mel-text--muted']],
        ],
        'choices' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-extra-choices']],
          '#children' => $merch_items,
        ],
      ],
      'addons' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-extras-studio__probe-panel', 'mel-es-card']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Add-ons'),
          '#attributes' => ['class' => ['mel-es-card__title']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Parking, meals, camping, VIP extras, or transport.'),
          '#attributes' => ['class' => ['mel-text--muted']],
        ],
        'choices' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-extra-choices']],
          '#children' => $addon_items,
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildExtraTypeChoiceLink(NodeInterface $event, string $key, string $label, string $group): array {
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event->id()], [
        'query' => ['add' => $key],
      ]),
      '#attributes' => [
        'class' => [
          'mel-event-extra-choice',
          'mel-event-extra-choice--' . $key,
          'mel-event-extra-choice--' . $group,
        ],
      ],
    ];
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
        '#url' => $this->buildPublicEventUrl($event),
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

  private function buildPublicEventUrl(NodeInterface $event): Url {
    $canonical_path = Url::fromRoute('entity.node.canonical', ['node' => $event->id()])->toString();
    $target = $this->domainDetector?->publicUrl($canonical_path) ?? $canonical_path;
    if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
      return Url::fromUri($target);
    }
    return Url::fromRoute('entity.node.canonical', ['node' => $event->id()]);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }
    $this->assertCanManageEvent($event);
    $trigger = $form_state->getTriggeringElement();
    if (!is_array($trigger)) {
      return;
    }
    if (str_starts_with((string) ($trigger['#name'] ?? ''), 'toggle_')) {
      return;
    }
    if (($trigger['#name'] ?? '') === 'save_extras_placement') {
      return;
    }
    if (($trigger['#type'] ?? '') !== 'submit') {
      return;
    }
    $input = $this->collectInput($form_state);
    $status = (string) ($input['product_status'] ?? '');
    if ($status !== '' && !array_key_exists($status, $this->productCreationManager->productStatusOptions())) {
      $form_state->setErrorByName('', $this->t('Choose a valid product status.'));
    }
    foreach ($this->productCreationManager->validateEventExtraInput($this->currentUser(), $event, $input) as $error) {
      $form_state->setErrorByName('', $error);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
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
    $this->assertCanManageEvent($event);
    $placement = $this->getSubmittedExtrasBookPlacement($form_state);
    if (!$this->extrasBookPlacementResolver->isValid($placement)) {
      $form_state->setErrorByName('extras_book_placement', $this->t('Choose a valid placement option.'));
      return;
    }
    try {
      $this->extrasBookPlacementResolver->savePlacement($event, $placement);
      $this->messenger()->addStatus($this->t('Extras placement saved.'));
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

  public function submitToggleVisibility(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      return;
    }
    $this->assertCanManageEvent($event);
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
    $this->assertCanManageEvent($event);
    try {
      $input = $this->collectInput($form_state);
      $product = $this->productCreationManager->saveEventExtraForVendor($this->currentUser(), $event, $input);
      $this->extractImagesOntoProduct($form_state, $product);
      $this->messenger()->addStatus($this->t('Product “@title” saved.', [
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
    $editor = $form_state->getValue('editor');
    if (!is_array($editor)) {
      return [];
    }
    $basics = is_array($editor['basics'] ?? NULL) ? $editor['basics'] : [];
    $pricing = is_array($editor['pricing'] ?? NULL) ? $editor['pricing'] : [];
    $quantity = is_array($editor['quantity'] ?? NULL) ? $editor['quantity'] : [];
    $variants = is_array($editor['variants'] ?? NULL) ? $editor['variants'] : [];
    $collection = is_array($editor['collection'] ?? NULL) ? $editor['collection'] : [];
    $visibility = is_array($editor['visibility'] ?? NULL) ? $editor['visibility'] : [];

    $sizes = [];
    foreach ((array) ($variants['sizes'] ?? []) as $key => $on) {
      if (!empty($on)) {
        $sizes[] = (string) $key;
      }
    }

    $extra_type = (string) ($editor['extra_type'] ?? '');
    if ($extra_type === '' && isset($basics['extra_type'])) {
      $extra_type = (string) $basics['extra_type'];
    }

    $image_media_ids = $this->extractImageMediaIds($form_state, ['editor', 'media', 'field_mel_extra_images']);
    if ($image_media_ids === []) {
      $image_media_ids = $this->extractImageMediaIds($form_state, ['editor', 'field_mel_extra_images']);
    }

    return [
      'product_id' => (int) ($editor['product_id'] ?? 0),
      'extra_type' => $extra_type,
      'title' => (string) ($basics['title'] ?? ''),
      'customer_summary' => (string) ($basics['customer_summary'] ?? ''),
      'product_status' => (string) ($basics['product_status'] ?? 'draft'),
      'pickup_note' => (string) ($collection['pickup_note'] ?? ''),
      'price_amount' => $pricing['price_amount'] ?? NULL,
      'sku' => (string) ($pricing['sku'] ?? ''),
      'capacity_note' => (string) ($quantity['capacity_note'] ?? ''),
      'sizes' => $sizes,
      'show_on_booking' => !empty($visibility['show_on_booking']),
      'image_media_ids' => $image_media_ids,
    ];
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
    if (!is_array($complete)) {
      return;
    }
    if (isset($complete['editor']['media']['field_mel_extra_images'])) {
      $form_display->extractFormValues($product, $complete['editor']['media'], $form_state);
      $product->save();
      return;
    }
    if (!isset($complete['editor']['field_mel_extra_images'])) {
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

  /**
   * Reads extras book placement from form state (flat or nested under wrapper).
   */
  private function getSubmittedExtrasBookPlacement(FormStateInterface $form_state): string {
    $value = $form_state->getValue('extras_book_placement');
    if (is_string($value) && $value !== '') {
      return $value;
    }
    $wrapper = $form_state->getValue('booking_placement_wrapper');
    if (is_array($wrapper) && isset($wrapper['extras_book_placement']) && is_string($wrapper['extras_book_placement'])) {
      return $wrapper['extras_book_placement'];
    }
    $input = $form_state->getUserInput();
    if (isset($input['extras_book_placement']) && is_string($input['extras_book_placement'])) {
      return $input['extras_book_placement'];
    }
    if (isset($input['booking_placement_wrapper']['extras_book_placement'])
      && is_string($input['booking_placement_wrapper']['extras_book_placement'])) {
      return $input['booking_placement_wrapper']['extras_book_placement'];
    }
    return '';
  }

}
