<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\myeventlane_vendor\Ticketing\EventTicketsBuilder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Event Studio form — custom MEL UI; persistence only via EventStudioSaveService.
 */
final class EventStudioForm extends FormBase {

  /**
   * Injected services must be protected (not private readonly) so FormBase
   * serialization can restore them from the container on cached form rebuilds.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected EntityFieldManagerInterface $entityFieldManager;

  protected EventStudioSaveService $saveService;

  protected AccountProxyInterface $currentUser;

  protected ?LocationProviderManager $locationProvider = NULL;

  /**
   * Allowed icons and highlight row normalization for Event Studio.
   */
  protected EventHighlightHelper $eventHighlightHelper;

  protected EventTicketsBuilder $eventTicketsBuilder;

  protected TicketTypeManager $ticketTypeManager;

  protected LoggerInterface $logger;

  protected EventStudioMelPayloadService $melPayloadService;

  protected EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->saveService = $container->get('myeventlane_event_studio.save');
    $instance->currentUser = $container->get('current_user');
    $instance->locationProvider = $container->has('myeventlane_location.provider_manager')
      ? $container->get('myeventlane_location.provider_manager')
      : NULL;
    $instance->eventHighlightHelper = $container->get('myeventlane_event_studio.highlight_helper');
    $instance->eventTicketsBuilder = $container->get('myeventlane_vendor.ticket_builder');
    $instance->ticketTypeManager = $container->get('myeventlane_event.ticket_type_manager');
    $instance->logger = $container->get('logger.factory')->get('myeventlane_event_studio');
    $instance->melPayloadService = $container->get('myeventlane_event_studio.mel_payload');
    $instance->entityAutocompleteMelNormalizer = $container->get('myeventlane_event_studio.entity_autocomplete_mel_normalizer');
    return $instance;
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
   *
   * Cached form state restores the form object via serialize/unserialize.
   * DependencySerializationTrait usually reattaches services, but typed
   * subclass properties can still be uninitialized on some paths; repull then.
   */
  private function ensureInjectedServices(): void {
    if (isset($this->entityTypeManager, $this->entityFieldManager, $this->saveService, $this->currentUser, $this->eventHighlightHelper, $this->eventTicketsBuilder, $this->ticketTypeManager, $this->logger, $this->melPayloadService, $this->entityAutocompleteMelNormalizer)) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $container->get('entity_type.manager');
    }
    if (!isset($this->entityFieldManager)) {
      $this->entityFieldManager = $container->get('entity_field.manager');
    }
    if (!isset($this->saveService)) {
      $this->saveService = $container->get('myeventlane_event_studio.save');
    }
    if (!isset($this->currentUser)) {
      $this->currentUser = $container->get('current_user');
    }
    if (!isset($this->locationProvider) && $container->has('myeventlane_location.provider_manager')) {
      $this->locationProvider = $container->get('myeventlane_location.provider_manager');
    }
    if (!isset($this->eventHighlightHelper)) {
      $this->eventHighlightHelper = $container->get('myeventlane_event_studio.highlight_helper');
    }
    if (!isset($this->eventTicketsBuilder)) {
      $this->eventTicketsBuilder = $container->get('myeventlane_vendor.ticket_builder');
    }
    if (!isset($this->ticketTypeManager)) {
      $this->ticketTypeManager = $container->get('myeventlane_event.ticket_type_manager');
    }
    if (!isset($this->logger)) {
      $this->logger = $container->get('logger.factory')->get('myeventlane_event_studio');
    }
    if (!isset($this->melPayloadService)) {
      $this->melPayloadService = $container->get('myeventlane_event_studio.mel_payload');
    }
    if (!isset($this->entityAutocompleteMelNormalizer)) {
      $this->entityAutocompleteMelNormalizer = $container->get('myeventlane_event_studio.entity_autocomplete_mel_normalizer');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_studio_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $route_node = NULL): array {
    $this->ensureInjectedServices();
    $form['#attributes']['class'][] = 'mel-event-studio-form';
    $form['#attributes']['data-mel-event-studio-form'] = '1';
    $form['#attributes']['id'] = 'event-studio-form';

    // AJAX rebuilds do not receive $route_node; cached form state can lose studio_node. Ticket
    // builder actions then exit early in handleAction() with no save. Rehydrate from hidden nid.
    $user_input = $form_state->getUserInput();
    $nid_from_request = 0;
    if (is_array($user_input) && isset($user_input['nid'])) {
      $nid_from_request = (int) $user_input['nid'];
    }
    if ($nid_from_request > 0) {
      $existing = $form_state->get('studio_node');
      $needs_hydrate = !$existing instanceof NodeInterface
        || (!$existing->isNew() && (int) $existing->id() !== $nid_from_request);
      if ($needs_hydrate) {
        $loaded = $this->entityTypeManager->getStorage('node')->load($nid_from_request);
        if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
          if ((int) $loaded->getOwnerId() === (int) $this->currentUser->id() || $this->currentUser->hasPermission('administer nodes')) {
            $form_state->set('studio_node', $loaded);
          }
        }
      }
    }

    if (!$form_state->has('studio_node')) {
      if ($route_node instanceof NodeInterface) {
        if ((int) $route_node->getOwnerId() !== (int) $this->currentUser->id() && !$this->currentUser->hasPermission('administer nodes')) {
          throw new AccessDeniedHttpException();
        }
        $form_state->set('studio_node', $route_node);
      }
      else {
        $storage = $this->entityTypeManager->getStorage('node');
        /** @var \Drupal\node\NodeInterface $new */
        $new = $storage->create([
          'type' => 'event',
          'title' => $this->t('Untitled event'),
          'uid' => (int) $this->currentUser->id(),
        ]);
        $form_state->set('studio_node', $new);
      }
    }

    /** @var \Drupal\node\NodeInterface $event */
    $event = $form_state->get('studio_node');

    $has_saved_event = $event->id() !== NULL && (int) $event->id() > 0;

    $form['nid'] = [
      '#type' => 'hidden',
      '#default_value' => $event->id() ? (string) (int) $event->id() : '',
    ];

    $venue_mode_default = 'one_off';
    if ($event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      $venue_mode_default = 'saved';
    }

    $venue_default = $event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()
      ? $event->get('field_venue')->entity
      : NULL;

    $summary = '';
    if ($event->hasField('field_event_summary') && !$event->get('field_event_summary')->isEmpty()) {
      $summary = (string) $event->get('field_event_summary')->value;
    }

    $body_default = '';
    if ($event->hasField('body') && !$event->get('body')->isEmpty()) {
      $body_default = (string) ($event->get('body')->value ?? '');
    }

    $image_fids = [];
    $image_alt_default = '';
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $img = $event->get('field_event_image')->first();
      if ($img) {
        $fid = (int) ($img->get('target_id')->getValue() ?? 0);
        if ($fid > 0) {
          $image_fids[] = $fid;
        }
        $image_alt_default = (string) ($img->get('alt')->getValue() ?? '');
      }
    }

    $category_default = [];
    if ($event->hasField('field_category') && !$event->get('field_category')->isEmpty()) {
      $category_default = $event->get('field_category')->referencedEntities();
    }

    $tags_default = [];
    if ($event->hasField('field_tags') && !$event->get('field_tags')->isEmpty()) {
      $tags_default = $event->get('field_tags')->referencedEntities();
    }

    $location_json_default = '';
    $lat_default = '';
    $lng_default = '';
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $addr = $event->get('field_location')->getValue();
      $first = $addr[0] ?? [];
      if (is_array($first)) {
        $row = [
          'country_code' => (string) ($first['country_code'] ?? 'AU'),
          'address_line1' => (string) ($first['address_line1'] ?? ''),
          'address_line2' => (string) ($first['address_line2'] ?? ''),
          'locality' => (string) ($first['locality'] ?? ''),
          'administrative_area' => (string) ($first['administrative_area'] ?? ''),
          'postal_code' => (string) ($first['postal_code'] ?? ''),
        ];
        $location_json_default = (string) json_encode($row);
      }
    }
    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $lat_default = (string) $event->get('field_location_latitude')->value;
    }
    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $lng_default = (string) $event->get('field_location_longitude')->value;
    }

    $type_default = $event->hasField('field_event_type') ? ($event->get('field_event_type')->value ?? 'rsvp') : 'rsvp';

    $capacity_default = '';
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $v = (int) $event->get('field_capacity')->value;
      if ($v > 0) {
        $capacity_default = (string) $v;
      }
    }

    $external_default = '';
    if ($event->hasField('field_external_url') && !$event->get('field_external_url')->isEmpty()) {
      $link = $event->get('field_external_url')->first();
      if ($link !== NULL) {
        $external_default = (string) ($link->get('uri')->getValue() ?? '');
      }
    }

    $collect_default = FALSE;
    if ($event->hasField('field_collect_per_ticket') && !$event->get('field_collect_per_ticket')->isEmpty()) {
      $collect_default = (bool) $event->get('field_collect_per_ticket')->value;
    }
    elseif ($type_default === 'rsvp'
      && $event->hasField('field_attendee_questions')
      && !$event->get('field_attendee_questions')->isEmpty()) {
      $collect_default = TRUE;
    }

    $enable_donations_default = FALSE;
    if ($event->hasField('field_enable_donations') && !$event->get('field_enable_donations')->isEmpty()) {
      $enable_donations_default = (bool) $event->get('field_enable_donations')->value;
    }

    $donation_amount_default = '';
    if ($event->hasField('field_donation_suggested_amount') && !$event->get('field_donation_suggested_amount')->isEmpty()) {
      $donation_amount_default = (string) $event->get('field_donation_suggested_amount')->value;
    }
    if ($event->hasField('field_donation_default') && !$event->get('field_donation_default')->isEmpty()) {
      $donation_amount_default = (string) $event->get('field_donation_default')->value;
    }

    $donation_options_default = '5,10,25,50';
    if ($event->hasField('field_donation_options') && !$event->get('field_donation_options')->isEmpty()) {
      $rawOptions = trim((string) $event->get('field_donation_options')->value);
      if ($rawOptions !== '') {
        $decoded = json_decode($rawOptions, TRUE);
        if (is_array($decoded) && $decoded !== []) {
          $donation_options_default = implode(',', array_map(static fn($item) => (string) $item, $decoded));
        }
      }
    }

    $donation_label_default = 'Support this event';
    if ($event->hasField('field_donation_label') && !$event->get('field_donation_label')->isEmpty()) {
      $donation_label_default = trim((string) $event->get('field_donation_label')->value) ?: 'Support this event';
    }

    $product_default = NULL;
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product_default = $event->get('field_product_target')->entity;
    }

    $ticket_types_default = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types_default = $event->get('field_ticket_types')->referencedEntities();
    }

    $field_event_intro_default = '';
    if ($event->hasField('field_event_intro') && !$event->get('field_event_intro')->isEmpty()) {
      $field_event_intro_default = (string) ($event->get('field_event_intro')->value ?? '');
    }

    $field_sales_start_default = NULL;
    if ($event->hasField('field_sales_start') && !$event->get('field_sales_start')->isEmpty()) {
      $field_sales_start_default = new DrupalDateTime($event->get('field_sales_start')->value);
    }

    $field_sales_end_default = NULL;
    if ($event->hasField('field_sales_end') && !$event->get('field_sales_end')->isEmpty()) {
      $field_sales_end_default = new DrupalDateTime($event->get('field_sales_end')->value);
    }

    $field_age_policy_default = 'all_ages';
    if ($event->hasField('field_age_policy') && !$event->get('field_age_policy')->isEmpty()) {
      $field_age_policy_default = (string) $event->get('field_age_policy')->value;
    }

    $field_age_policy_note_default = '';
    if ($event->hasField('field_age_policy_note') && !$event->get('field_age_policy_note')->isEmpty()) {
      $field_age_policy_note_default = (string) ($event->get('field_age_policy_note')->value ?? '');
    }

    $field_age_restriction_default = '';
    if ($event->hasField('field_age_restriction') && !$event->get('field_age_restriction')->isEmpty()) {
      $field_age_restriction_default = (string) $event->get('field_age_restriction')->value;
    }

    $field_refund_policy_default = '';
    if ($event->hasField('field_refund_policy') && !$event->get('field_refund_policy')->isEmpty()) {
      $field_refund_policy_default = (string) $event->get('field_refund_policy')->value;
    }

    $field_accessibility_default = [];
    if ($event->hasField('field_accessibility') && !$event->get('field_accessibility')->isEmpty()) {
      $field_accessibility_default = $event->get('field_accessibility')->referencedEntities();
    }

    $field_accessibility_contact_default = '';
    if ($event->hasField('field_accessibility_contact') && !$event->get('field_accessibility_contact')->isEmpty()) {
      $field_accessibility_contact_default = (string) ($event->get('field_accessibility_contact')->value ?? '');
    }

    $field_accessibility_directions_default = '';
    if ($event->hasField('field_accessibility_directions') && !$event->get('field_accessibility_directions')->isEmpty()) {
      $field_accessibility_directions_default = (string) ($event->get('field_accessibility_directions')->value ?? '');
    }

    $field_accessibility_entry_default = '';
    if ($event->hasField('field_accessibility_entry') && !$event->get('field_accessibility_entry')->isEmpty()) {
      $field_accessibility_entry_default = (string) ($event->get('field_accessibility_entry')->value ?? '');
    }

    $field_accessibility_parking_default = '';
    if ($event->hasField('field_accessibility_parking') && !$event->get('field_accessibility_parking')->isEmpty()) {
      $field_accessibility_parking_default = (string) ($event->get('field_accessibility_parking')->value ?? '');
    }

    $field_contact_email_default = '';
    if ($event->hasField('field_contact_email') && !$event->get('field_contact_email')->isEmpty()) {
      $field_contact_email_default = (string) ($event->get('field_contact_email')->value ?? '');
    }

    $field_contact_phone_default = '';
    if ($event->hasField('field_contact_phone') && !$event->get('field_contact_phone')->isEmpty()) {
      $field_contact_phone_default = (string) ($event->get('field_contact_phone')->value ?? '');
    }

    $has_cover = $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty();
    $needs_ticket_product = FALSE;
    if ($type_default === 'paid'
      && $event->hasField('field_product_target')
      && $event->get('field_product_target')->isEmpty()) {
      $needs_ticket_product = TRUE;
    }

    $venue_default = $this->entityAutocompleteMelNormalizer->normalizeSingle($venue_default, 'myeventlane_venue', 'mel.venue_saved');
    $product_default = $this->entityAutocompleteMelNormalizer->normalizeSingle($product_default, 'commerce_product', 'mel.field_product_target');
    $ticket_types_default = $this->entityAutocompleteMelNormalizer->normalizeTags($ticket_types_default, 'mel_ticket_type', 'mel.field_ticket_types');
    $category_default = $this->entityAutocompleteMelNormalizer->normalizeTags($category_default, 'taxonomy_term', 'mel.field_category');
    $tags_default = $this->entityAutocompleteMelNormalizer->normalizeTags($tags_default, 'taxonomy_term', 'mel.field_tags');
    $field_accessibility_default = $this->entityAutocompleteMelNormalizer->normalizeTags($field_accessibility_default, 'taxonomy_term', 'mel.field_accessibility');

    $studio_tiers_json = $this->encodeStudioTiersJsonForEvent($event);

    $form['mel'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio']],
    ];

    $form['mel']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#description' => $this->t('Make it clear and specific.'),
      '#default_value' => $event->label(),
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summary'),
      '#description' => $this->t('This appears in cards and previews.'),
      '#default_value' => $summary,
      '#attributes' => ['class' => ['mel-input']],
    ];

    // Tone/audience for AI + submit: synced from panel selects .mel-ai-tone / .mel-ai-audience in twig (hidden).
    $form['mel']['ai_settings'] = [
      '#type' => 'container',
      '#weight' => -10,
      '#attributes' => [
        'class' => ['visually-hidden'],
        'aria-hidden' => 'true',
      ],
    ];
    $form['mel']['ai_settings']['ai_tone'] = [
      '#type' => 'hidden',
      '#default_value' => 'community',
    ];
    $form['mel']['ai_settings']['ai_audience'] = [
      '#type' => 'hidden',
      '#default_value' => 'general',
    ];

    $form['mel']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('About the event'),
      '#default_value' => $body_default,
      '#description' => $this->t('Longer description for your event page (plain text).'),
      '#attributes' => ['class' => ['mel-input', 'mel-input--body']],
    ];

    $form['mel']['field_event_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What to expect'),
      '#default_value' => $field_event_intro_default,
      '#description' => $this->t('Shown on the event page (plain text).'),
      '#attributes' => ['class' => ['mel-input']],
    ];

    $highlights_json = $this->encodeEventHighlightsJsonForEvent($event);
    $form['mel']['event_highlights'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-section--card', 'mel-event-highlights-editor']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('What makes your event special?'),
        '#attributes' => ['class' => ['mel-section__title']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Add up to 6 highlights that help people decide to attend.'),
        '#attributes' => ['class' => ['mel-section__hint']],
      ],
      'errors' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'mel-highlights-editor-errors',
          'class' => ['mel-highlights-editor__errors', 'messages', 'messages--error'],
          'role' => 'alert',
          'aria-live' => 'polite',
          'hidden' => 'hidden',
          'data-mel-highlights-errors' => '1',
          'tabindex' => '-1',
        ],
        'text' => [
          '#markup' => '<p class="mel-highlights-editor__errors-text"></p>',
        ],
      ],
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => $highlights_json,
        '#attributes' => [
          'id' => 'mel-event-highlights-json',
          'data-mel-highlights-state' => '1',
        ],
      ],
      'builder' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-highlights-builder'],
          'data-mel-highlights-builder' => '1',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Icon'),
            $this->t('Highlight'),
            $this->t('Order'),
            $this->t('Actions'),
          ],
          '#rows' => [],
          '#empty' => $this->t('No highlights yet.'),
          '#attributes' => [
            'class' => ['mel-highlights-builder-table'],
            'data-mel-highlights-table' => '1',
          ],
        ],
        'add' => [
          '#type' => 'button',
          '#value' => $this->t('Add highlight'),
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--secondary', 'mel-btn--touch', 'button'],
            'type' => 'button',
            'id' => 'mel-add-event-highlight',
            'aria-label' => (string) $this->t('Add highlight row'),
          ],
        ],
      ],
    ];

    $form['mel']['field_event_image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Cover image'),
      '#description' => $this->t('Events perform better with a visual. PNG, JPG, WebP; max 5 MB. Recommended 1200×630.'),
      '#upload_location' => 'public://events',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png gif jpg jpeg webp'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $image_fids,
      '#attributes' => ['class' => ['mel-input-file']],
    ];

    $form['mel']['field_event_image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $image_alt_default,
      '#description' => $this->t('Short description for screen readers and SEO.'),
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact email'),
      '#default_value' => $field_contact_email_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_contact_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Contact phone'),
      '#default_value' => $field_contact_phone_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['start_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start'),
      '#description' => $this->t('Used in search, reminders, and calendars.'),
      '#default_value' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
        ? new DrupalDateTime($event->get('field_event_start')->value)
        : NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['end_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End'),
      '#description' => $this->t('Used in search, reminders, and calendars.'),
      '#default_value' => $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
        ? new DrupalDateTime($event->get('field_event_end')->value)
        : NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_sales_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Ticket sales start'),
      '#default_value' => $field_sales_start_default,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_sales_end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Ticket sales end'),
      '#default_value' => $field_sales_end_default,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_age_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('Age policy'),
      '#options' => $this->listStringFieldOptions('field_age_policy') ?: [
        'all_ages' => $this->t('All ages'),
        '18_plus' => $this->t('18+'),
        '16_plus' => $this->t('16+'),
        'under_18_with_guardian' => $this->t('Under 18 with guardian'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $field_age_policy_default,
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_age_policy_note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Age policy note'),
      '#default_value' => $field_age_policy_note_default,
      '#maxlength' => 255,
      '#description' => $this->t('Use when age policy is set to Custom.'),
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_age_policy]"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['mel']['field_age_restriction'] = [
      '#type' => 'select',
      '#title' => $this->t('Age suitability'),
      '#options' => $this->listStringFieldOptions('field_age_restriction'),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
      '#default_value' => $field_age_restriction_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_refund_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('Refund policy'),
      '#options' => $this->listStringFieldOptions('field_refund_policy'),
      '#empty_option' => $this->t('- Not specified -'),
      '#empty_value' => '',
      '#default_value' => $field_refund_policy_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['venue_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Location'),
      '#options' => [
        'saved' => $this->t('Use saved venue'),
        'create' => $this->t('Create new venue'),
        'one_off' => $this->t('One-off address'),
      ],
      '#default_value' => $form_state->getValue(['mel', 'venue_mode'], $venue_mode_default),
      '#attributes' => ['class' => ['mel-radios']],
    ];

    $form['mel']['venue_saved'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Search your venues'),
      '#target_type' => 'myeventlane_venue',
      '#default_value' => $venue_default,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[venue_mode]"]' => ['value' => 'saved'],
        ],
      ],
    ];

    $form['mel']['venue_create_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue name'),
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[venue_mode]"]' => ['value' => 'create'],
        ],
      ],
    ];

    $form['mel']['location_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search address'),
      '#attributes' => [
        'class' => ['mel-location-search', 'mel-input'],
        'data-mel-location' => 'true',
      ],
      '#states' => [
        'visible' => [
          'or' => [
            [':input[name="mel[venue_mode]"]' => ['value' => 'one_off']],
            [':input[name="mel[venue_mode]"]' => ['value' => 'create']],
          ],
        ],
      ],
    ];

    $form['mel']['field_location'] = [
      '#type' => 'hidden',
      '#default_value' => $location_json_default,
    ];

    $form['mel']['field_location_latitude'] = [
      '#type' => 'hidden',
      '#default_value' => $lat_default,
    ];

    $form['mel']['field_location_longitude'] = [
      '#type' => 'hidden',
      '#default_value' => $lng_default,
    ];

    $form['mel']['tickets_intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('How will people join?'),
      '#attributes' => ['class' => ['mel-tickets-intro']],
    ];

    $form['mel']['field_event_type'] = [
      '#type' => 'radios',
      '#title' => '',
      '#options' => [
        'rsvp' => $this->t('Free RSVP'),
        'paid' => $this->t('Paid tickets'),
        'external' => $this->t('External link'),
      ],
      '#default_value' => $type_default,
      '#attributes' => ['class' => ['mel-radios', 'mel-radios--tickets']],
    ];

    $form['mel']['studio_ticket_tiers'] = [
      '#type' => 'hidden',
      '#default_value' => $studio_tiers_json,
      '#attributes' => [
        'id' => 'mel-studio-ticket-tiers-json',
        'data-mel-studio-ticket-tiers' => '1',
      ],
    ];

    if ($has_saved_event) {
      $form_state->set('event', $event);
      $form['mel']['embedded_ticket_wizard'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#weight' => 13,
        '#attributes' => [
          'class' => ['mel-ticket-wizard-embed'],
        ],
      ];
      $form_state->set('mel_ticket_builder_value_prefix', ['mel', 'embedded_ticket_wizard']);
      $this->eventTicketsBuilder->build($form['mel']['embedded_ticket_wizard'], $form_state, $event);
    }

    $form['mel']['studio_ticket_builder'] = [
      '#type' => 'container',
      '#access' => !$has_saved_event,
      '#attributes' => ['class' => ['mel-ticket-builder']],
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Build ticket types inline — they are saved as MEL tiers and linked to this event.'),
        '#attributes' => ['class' => ['mel-ticket-builder__help']],
      ],
      'warn' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'mel-ticket-tiers-warn',
          'class' => ['mel-ticket-tiers-warn', 'messages', 'messages--warning'],
          'hidden' => 'hidden',
        ],
        'inner' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Add at least one ticket type.'),
          '#attributes' => ['class' => ['mel-ticket-tiers-warn__text']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Title'),
          $this->t('Price'),
          $this->t('Capacity'),
          $this->t('Actions'),
        ],
        '#rows' => [],
        '#empty' => $this->t('No ticket types yet.'),
        '#attributes' => [
          'class' => ['mel-ticket-builder-table'],
          'data-mel-ticket-builder-table' => '1',
        ],
      ],
      'add' => [
        '#type' => 'button',
        '#value' => $this->t('Add ticket type'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--secondary', 'button'],
          'type' => 'button',
          'id' => 'mel-add-ticket-tier',
        ],
      ],
    ];

    $form['mel']['rsvp_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('RSVP capacity'),
      '#description' => $this->t('Maximum attendees. Leave blank for unlimited.'),
      '#min' => 0,
      '#default_value' => $capacity_default,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'rsvp'],
        ],
      ],
    ];

    $form['mel']['field_product_target'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Ticket product'),
      '#target_type' => 'commerce_product',
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['ticket' => 'ticket'],
      ],
      '#default_value' => $product_default,
      '#description' => $this->t('Required for paid events: link your Commerce ticket product. Configure ticket types below once the event is saved.'),
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'paid'],
        ],
      ],
    ];

    $form['mel']['field_ticket_types'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Ticket types'),
      '#target_type' => 'mel_ticket_type',
      '#tags' => TRUE,
      '#default_value' => $ticket_types_default,
      '#description' => $has_saved_event
        ? $this->t('Ticket types are managed in the ticket builder above.')
        : $this->t('Optional: attach existing ticket type entities. After your first save, use the full ticket builder in Event Studio.'),
      '#attributes' => ['class' => ['mel-input']],
      '#access' => !$has_saved_event,
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'paid'],
        ],
      ],
    ];

    $form['mel']['paid_ticket_panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-panel', 'mel-panel--placeholder']],
      'text' => [
        '#markup' => '<p class="mel-panel__text">' . $this->t('Use the ticket product above for checkout. Add and edit tiers in the ticket builder on this page.') . '</p>',
      ],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'paid'],
        ],
      ],
    ];

    $form['mel']['external_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Booking or registration URL'),
      '#description' => $this->t('Where attendees complete registration or purchase tickets.'),
      '#default_value' => $external_default,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'external'],
        ],
      ],
    ];

    $form['mel']['collect_attendee_questions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collect extra attendee details'),
      '#description' => $this->t('Paid: collect per ticket where supported. RSVP: add custom questions from the full ticket builder after your event is saved, or from the event Tickets tab.'),
      '#default_value' => $collect_default,
      '#states' => [
        'visible' => [
          'or' => [
            [':input[name="mel[field_event_type]"]' => ['value' => 'rsvp']],
            [':input[name="mel[field_event_type]"]' => ['value' => 'paid']],
          ],
        ],
      ],
    ];

    $form['mel']['enable_donations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable optional donations'),
      '#default_value' => $enable_donations_default,
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'rsvp'],
        ],
      ],
    ];

    $form['mel']['donation_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Default donation'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $donation_amount_default,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[enable_donations]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['mel']['donation_options'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Donation options'),
      '#description' => $this->t('Comma-separated values, e.g. 5,10,25,50'),
      '#default_value' => $donation_options_default,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[enable_donations]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['mel']['donation_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Donation label'),
      '#default_value' => $donation_label_default,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[enable_donations]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['mel']['ticket_summary'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-summary'],
        'id' => 'mel-ticket-summary',
      ],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Ticket setup summary'),
        '#attributes' => ['class' => ['mel-ticket-summary__title']],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-summary__body'], 'id' => 'mel-ticket-summary-body'],
        '#markup' => $this->buildTicketSummaryMarkup($type_default, $capacity_default, $collect_default, $external_default, $needs_ticket_product),
      ],
    ];

    $form['mel']['field_category'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Categories'),
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['categories' => 'categories'],
      ],
      '#default_value' => $category_default,
      '#description' => $this->t('Helps people find your event.'),
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_tags'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Tags'),
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['tags' => 'tags'],
      ],
      '#default_value' => $tags_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Accessibility features'),
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['accessibility' => 'accessibility'],
      ],
      '#default_value' => $field_accessibility_default,
      '#description' => $this->t('Shown on listings and helps attendees plan ahead.'),
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_contact'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessibility contact'),
      '#default_value' => $field_accessibility_contact_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_directions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessible directions'),
      '#default_value' => $field_accessibility_directions_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_entry'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Entry and access'),
      '#default_value' => $field_accessibility_entry_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_parking'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessible parking'),
      '#default_value' => $field_accessibility_parking_default,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => $event->isPublished(),
      '#attributes' => ['class' => ['mel-checkbox-publish']],
    ];

    if ($this->locationProvider instanceof LocationProviderManager) {
      $form['#attached']['drupalSettings']['myeventlaneLocation'] = $this->locationProvider->getFrontendSettings();
    }

    $form['#attached']['drupalSettings']['melEventStudio'] = [
      'initial' => [
        'published' => $event->isPublished(),
        'hasCoverImage' => $has_cover,
        'needsTicketProduct' => $needs_ticket_product,
        'ticketType' => $type_default,
        'venueMode' => $venue_mode_default,
      ],
      'highlightIconOptions' => $this->eventHighlightHelper->getIconOptionsForJs(),
      'highlightErrors' => [
        'max' => (string) $this->t('You can add at most 6 highlights.'),
        'iconNoText' => (string) $this->t('Add text for each highlight that has an icon.'),
        'json' => (string) $this->t('Highlights data could not be read. Reset the list or reload the page.'),
      ],
      'strings' => [
        'draft' => (string) $this->t('Draft'),
        'live' => (string) $this->t('Live'),
        'unlimited' => (string) $this->t('Unlimited'),
        'yes' => (string) $this->t('Yes'),
        'no' => (string) $this->t('No'),
        'typeRsvp' => (string) $this->t('Free RSVP'),
        'typePaid' => (string) $this->t('Paid tickets'),
        'typeExternal' => (string) $this->t('External link'),
      ],
      'defaultCurrency' => $this->resolveStudioDefaultCurrency($event),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-form-actions']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'button--primary']],
    ];

    $form['#mel_studio_node'] = $event;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::logger('mel_debug')->notice('VALIDATION RUN: EventStudioForm::validateForm');
    try {
      parent::validateForm($form, $form_state);
      $this->ensureInjectedServices();
      $event = $form_state->get('studio_node');
      if ($event instanceof NodeInterface && $event->hasField('field_event_type')) {
        $mel = $form_state->getValue('mel');
        $event_type = is_array($mel) ? (string) ($mel['field_event_type'] ?? '') : '';
        if ($event_type === '') {
          $event_type = (string) ($event->get('field_event_type')->value ?? '');
        }
        if (in_array($event_type, ['paid', 'both'], TRUE)) {
          if (!$this->ticketTypeManager->hasVendorStore($event)) {
            $this->logger->warning('Event Studio: tickets validation blocked — no vendor store for event @nid', [
              '@nid' => (string) $event->id(),
            ]);
            $form_state->setErrorByName('mel][field_event_type', $this->t('This event does not have a valid vendor store. Complete organiser setup and ensure your vendor account has a store assigned before selling paid tickets.'));
          }
        }
      }

      $mel = $form_state->getValue('mel');
      if (!is_array($mel) || !isset($mel['event_highlights']) || !is_array($mel['event_highlights'])) {
        return;
      }
      $raw = '';
      if (isset($mel['event_highlights']['items_state'])) {
        $raw = (string) $mel['event_highlights']['items_state'];
      }
      $decoded = [];
      if ($raw !== '') {
        try {
          $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException) {
          $form_state->setErrorByName('mel][event_highlights][items_state', $this->t('Highlights data could not be read. Please refresh and try again.'));
          return;
        }
      }
      if (!is_array($decoded)) {
        $decoded = [];
      }

      $this->ensureInjectedServices();
      $analysis = $this->eventHighlightHelper->analyzeDecodedHighlights($decoded);
      if ($analysis['icon_without_text']) {
        $form_state->setErrorByName('mel][event_highlights][items_state', $this->t('Each highlight with an icon needs highlight text.'));
        return;
      }
      if ($analysis['persistable_count'] > EventHighlightHelper::HIGHLIGHT_LIMIT) {
        $form_state->setErrorByName('mel][event_highlights][items_state', $this->t('You can add at most 6 highlights.'));
      }
    }
    finally {
      if ($form_state->hasAnyErrors()) {
        \Drupal::logger('mel_debug')->notice('VALIDATION FAILED');
      }
      $errors = $form_state->getErrors();
      if ($errors !== []) {
        \Drupal::logger('mel_debug')->notice('VALIDATION FAILED: @c field error(s) on form_state', ['@c' => (string) count($errors)]);
      }
      else {
        \Drupal::logger('mel_debug')->notice('VALIDATION: no form_state errors at end of EventStudioForm::validateForm');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::logger('mel_debug')->notice('SUBMIT HIT: EventStudioForm::submitForm');
    $this->ensureInjectedServices();
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    $existing = NULL;
    if ($nid > 0) {
      $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
        if ((int) $loaded->getOwnerId() !== (int) $this->currentUser->id() && !$this->currentUser->hasPermission('administer nodes')) {
          throw new AccessDeniedHttpException();
        }
        $existing = $loaded;
      }
    }

    $payload = $this->melPayloadService->buildFromFormState($form_state, $this->entityTypeManager);
    $result = $this->saveService->save($payload, $existing, $this->currentUser, FALSE);
    if ($result['errors'] !== []) {
      \Drupal::logger('mel_debug')->notice('EventStudioForm::submitForm: save service returned @c error(s); no redirect', [
        '@c' => (string) count($result['errors']),
      ]);
      foreach ($result['errors'] as $msg) {
        $this->messenger()->addError($msg);
      }
      return;
    }
    $node = $result['node'];
    if ($node instanceof NodeInterface) {
      $form_state->set('studio_node', $node);
      $this->messenger()->addStatus($this->t('Event saved.'));
      try {
        $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event_studio.edit', ['node' => $node->id()]));
      }
      catch (\Throwable) {
        $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()]));
      }
      \Drupal::logger('mel_debug')->notice('EventStudioForm::submitForm: success, redirect set for node @nid', [
        '@nid' => (string) $node->id(),
      ]);
    }
    else {
      \Drupal::logger('mel_debug')->notice('EventStudioForm::submitForm: no errors from save but result node missing; no redirect');
    }
  }

  /**
   * Ticket builder AJAX actions (EventTicketsBuilder); preserves fresh node in form state.
   */
  public function handleAction(array &$form, FormStateInterface $form_state): void {
    \Drupal::logger('mel_debug')->notice('SUBMIT HIT: EventStudioForm::handleAction (ticket builder / AJAX path)');
    $this->ensureInjectedServices();
    $event = $form_state->get('studio_node');
    if (!$event instanceof NodeInterface) {
      $this->logger->error('Event Studio ticket builder: studio_node missing from form state; ticket action skipped. Check nid rehydration on AJAX rebuild.');
      $this->messenger()->addError($this->t('Could not attach tickets to this event (session state). Reload the page and try again.'));
      return;
    }
    $this->eventTicketsBuilder->handleAction($form, $form_state, $event);
    $nid = (int) $event->id();
    if ($nid > 0) {
      $fresh = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($fresh instanceof NodeInterface) {
        $form_state->set('studio_node', $fresh);
        $form_state->set('event', $fresh);
      }
    }
  }

  /**
   * AJAX replace target for the embedded ticket builder shell.
   */
  public function ajaxRebuildTicketBuilder(array &$form, FormStateInterface $form_state): array {
    return $form['mel']['embedded_ticket_wizard']['builder_shell'] ?? [];
  }

  /**
   * Encodes paragraph highlights for the Event Studio JS builder (JSON in hidden field).
   */
  private function encodeEventHighlightsJsonForEvent(NodeInterface $event): string {
    if (!$event->hasField('field_event_highlights') || $event->get('field_event_highlights')->isEmpty()) {
      return '[]';
    }
    $rows = [];
    foreach ($event->get('field_event_highlights')->referencedEntities() as $p) {
      if ($p->bundle() !== 'event_highlight') {
        continue;
      }
      $text = '';
      if ($p->hasField('field_highlight_text') && !$p->get('field_highlight_text')->isEmpty()) {
        $text = trim((string) $p->get('field_highlight_text')->value);
      }
      $icon = '';
      if ($p->hasField('field_highlight_icon') && !$p->get('field_highlight_icon')->isEmpty()) {
        $icon = trim((string) $p->get('field_highlight_icon')->value);
      }
      $rows[] = [
        'text' => $text,
        'icon' => $icon,
      ];
    }
    try {
      return json_encode($rows, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return '[]';
    }
  }

  private function encodeStudioTiersJsonForEvent(NodeInterface $event): string {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return '[]';
    }
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return '[]';
    }
    $rows = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if (!$entity instanceof TicketTypeInterface) {
        continue;
      }
      $row = [
        'id' => (int) $entity->id(),
        'title' => $entity->getTitle(),
        'ticket_kind' => $entity->getTicketKind(),
        'capacity' => 0,
      ];
      if ($entity->hasField('capacity') && !$entity->get('capacity')->isEmpty()) {
        $row['capacity'] = (int) $entity->get('capacity')->value;
      }
      $price = $entity->toPriceValue();
      if ($price !== NULL) {
        $row['price_number'] = $price->getNumber();
        $row['price_currency'] = $price->getCurrencyCode();
      }
      $ext = $entity->getExternalUrlString();
      if ($ext !== NULL && $ext !== '') {
        $row['external_uri'] = $ext;
      }
      $rows[] = $row;
    }
    try {
      return json_encode($rows, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return '[]';
    }
  }

  private function resolveStudioDefaultCurrency(NodeInterface $event): string {
    if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
      $store = $event->get('field_event_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store->getDefaultCurrencyCode();
      }
    }
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $store = $vendor->get('field_vendor_store')->entity;
        if ($store instanceof StoreInterface) {
          return $store->getDefaultCurrencyCode();
        }
      }
    }
    if ($this->entityTypeManager->hasDefinition('commerce_store')) {
      $stores = $this->entityTypeManager->getStorage('commerce_store')->loadByProperties(['is_default' => TRUE]);
      $store = reset($stores);
      if ($store instanceof StoreInterface) {
        return $store->getDefaultCurrencyCode();
      }
    }
    return 'AUD';
  }

  /**
   * Static summary for ticket section (JS keeps it in sync).
   */
  private function buildTicketSummaryMarkup(
    string $type,
    string $capacity_display,
    bool $collect,
    string $external,
    bool $needs_ticket_product,
  ): string {
    $type_label = match ($type) {
      'paid' => (string) $this->t('Paid tickets'),
      'external' => (string) $this->t('External link'),
      default => (string) $this->t('Free RSVP'),
    };
    $cap = $type === 'rsvp'
      ? ($capacity_display !== '' ? $capacity_display : (string) $this->t('Unlimited'))
      : '—';
    $collect_label = ($type === 'rsvp' || $type === 'paid')
      ? ($collect ? (string) $this->t('Yes') : (string) $this->t('No'))
      : '—';
    $ext = $type === 'external'
      ? ($external !== '' ? (string) $this->t('Set') : (string) $this->t('Not set'))
      : '—';
    $paid_note = ($type === 'paid' && $needs_ticket_product)
      ? '<li class="mel-ticket-summary__warn">' . (string) $this->t('Ticket product still needed — link one above or add it from the event Tickets tab.') . '</li>'
      : '';

    return '<ul class="mel-ticket-summary__list">'
      . '<li><span class="mel-ticket-summary__k">' . $this->t('Current type') . '</span> ' . $type_label . '</li>'
      . '<li><span class="mel-ticket-summary__k">' . $this->t('Capacity') . '</span> ' . $cap . '</li>'
      . '<li><span class="mel-ticket-summary__k">' . $this->t('Extra attendee details') . '</span> ' . $collect_label . '</li>'
      . '<li><span class="mel-ticket-summary__k">' . $this->t('External URL') . '</span> ' . $ext . '</li>'
      . $paid_note
      . '</ul>';
  }

  /**
   * Options for a list_string field on the event bundle (empty if unavailable).
   *
   * @return array<string, string>
   */
  private function listStringFieldOptions(string $field_name): array {
    $this->ensureInjectedServices();
    try {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'event');
    }
    catch (\Throwable) {
      return [];
    }
    $def = $definitions[$field_name] ?? NULL;
    if ($def === NULL || $def->getType() !== 'list_string') {
      return [];
    }
    $allowed = $def->getSetting('allowed_values');
    if (!is_array($allowed)) {
      return [];
    }
    $out = [];
    foreach ($allowed as $key => $item) {
      if (is_array($item) && isset($item['value'])) {
        $out[(string) $item['value']] = (string) ($item['label'] ?? $item['value']);
      }
      elseif (!is_array($item) && !is_int($key)) {
        $out[(string) $key] = (string) $item;
      }
    }
    return $out;
  }

}
