<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Url;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\node\NodeInterface;
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

  protected EventStudioSaveService $saveService;

  protected AccountProxyInterface $currentUser;

  protected ?LocationProviderManager $locationProvider = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->saveService = $container->get('myeventlane_event_studio.save');
    $instance->currentUser = $container->get('current_user');
    $instance->locationProvider = $container->has('myeventlane_location.provider_manager')
      ? $container->get('myeventlane_location.provider_manager')
      : NULL;
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
    if (isset($this->entityTypeManager, $this->saveService, $this->currentUser)) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $container->get('entity_type.manager');
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

    $has_cover = $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty();
    $needs_ticket_product = FALSE;
    if ($type_default === 'paid'
      && $event->hasField('field_product_target')
      && $event->get('field_product_target')->isEmpty()) {
      $needs_ticket_product = TRUE;
    }

    $studio_tiers_json = $this->encodeStudioTiersJsonForEvent($event);

    $form['mel'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio']],
    ];

    $form['mel']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#default_value' => $event->label(),
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summary'),
      '#default_value' => $summary,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('About the event'),
      '#default_value' => $body_default,
      '#description' => $this->t('Longer description for your event page (plain text).'),
      '#attributes' => ['class' => ['mel-input', 'mel-input--body']],
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
      '#description' => $this->t('Hero image (PNG, JPG, WebP; max 5 MB). Recommended 1200×630.'),
      '#upload_location' => 'public://events',
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg webp'],
        'file_validate_size' => [5 * 1024 * 1024],
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

    $form['mel']['start_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start'),
      '#default_value' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
        ? new DrupalDateTime($event->get('field_event_start')->value)
        : NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['end_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End'),
      '#default_value' => $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
        ? new DrupalDateTime($event->get('field_event_end')->value)
        : NULL,
      '#date_increment' => 15,
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

    $form['mel']['studio_ticket_builder'] = [
      '#type' => 'container',
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
      '#description' => $this->t('Required for paid events: link your Commerce ticket product. You can refine types and pricing in the Tickets workspace.'),
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
      '#description' => $this->t('Optional: attach existing ticket type entities. Full builder remains in the Tickets workspace.'),
      '#attributes' => ['class' => ['mel-input']],
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
        '#markup' => '<p class="mel-panel__text">' . $this->t('Use the ticket product above for checkout. Configure tiers, pricing, and inventory in the Tickets workspace after save.') . '</p>',
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
      '#description' => $this->t('Paid: collect per ticket where supported. RSVP: add custom questions in the Tickets workspace after save.'),
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
      '#description' => $this->t('Help attendees discover your event.'),
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
      'highlightIconOptions' => $this->getHighlightIconOptionsForJs(),
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
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

    $non_empty = 0;
    foreach ($decoded as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      $icon = trim((string) ($item['icon'] ?? ''));
      if ($text === '' && $icon === '') {
        continue;
      }
      if ($text === '' && $icon !== '') {
        $form_state->setErrorByName('mel][event_highlights][items_state', $this->t('Each highlight with an icon needs highlight text.'));
        return;
      }
      $non_empty++;
    }

    if ($non_empty > 6) {
      $form_state->setErrorByName('mel][event_highlights][items_state', $this->t('You can add at most 6 highlights.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
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

    $payload = $this->buildPayloadFromMel($form_state);
    $result = $this->saveService->save($payload, $existing, $this->currentUser, FALSE);
    if ($result['errors'] !== []) {
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
        $form_state->setRedirectUrl(Url::fromRoute('myeventlane_vendor.console.event_workspace', ['event' => $node->id()]));
      }
      catch (\Throwable) {
        $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()]));
      }
    }
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

  /**
   * @return array<string, string>
   */
  private function getHighlightIconOptionsForJs(): array {
    $storage = FieldStorageConfig::load('paragraph.field_highlight_icon');
    if ($storage === NULL) {
      return [];
    }
    $raw = $storage->getSetting('allowed_values');
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $item) {
      if (is_array($item) && isset($item['value'], $item['label'])) {
        $out[(string) $item['value']] = (string) $item['label'];
      }
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $mel
   *
   * @return list<array{icon: string, text: string, weight: int}>
   */
  private function normalizeEventHighlightsPayload(array $mel): array {
    if (!isset($mel['event_highlights']['items_state'])) {
      return [];
    }
    $raw = trim((string) $mel['event_highlights']['items_state']);
    if ($raw === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    if (!is_array($decoded)) {
      return [];
    }
    $allowed = array_keys($this->getHighlightIconOptionsForJs());
    $out = [];
    $weight = 0;
    foreach ($decoded as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $icon = trim((string) ($item['icon'] ?? ''));
      if ($icon !== '' && !in_array($icon, $allowed, TRUE)) {
        $icon = '';
      }
      $out[] = [
        'icon' => $icon,
        'text' => $text,
        'weight' => $weight,
      ];
      $weight++;
    }
    return array_slice($out, 0, 6);
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
      ? '<li class="mel-ticket-summary__warn">' . (string) $this->t('Ticket product still needed — link one above or use the Tickets workspace.') . '</li>'
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
   * Builds the flat save payload from submitted `mel` values only.
   *
   * @return array<string, mixed>
   */
  private function buildPayloadFromMel(FormStateInterface $form_state): array {
    $mel = $form_state->getValue('mel');
    if (!is_array($mel)) {
      $mel = [];
    }

    $choice = (string) ($mel['venue_mode'] ?? 'one_off');
    $venue_id = NULL;
    if ($choice === 'saved' && !empty($mel['venue_saved'])) {
      $raw = $mel['venue_saved'];
      if (is_array($raw) && isset($raw[0]['target_id'])) {
        $venue_id = (int) $raw[0]['target_id'];
      }
      elseif (is_numeric($raw)) {
        $venue_id = (int) $raw;
      }
      elseif (is_string($raw)) {
        $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);
        $venue_id = $eid !== NULL ? (int) $eid : NULL;
      }
    }

    $new_name = '';
    if ($choice === 'create') {
      $new_name = trim((string) ($mel['venue_create_name'] ?? ''));
    }

    $field_location = $choice === 'saved' ? [] : ($mel['field_location'] ?? '');

    $ticket_type = (string) ($mel['field_event_type'] ?? 'rsvp');
    $capacity_raw = $mel['rsvp_capacity'] ?? '';
    $capacity = NULL;
    if ($ticket_type === 'rsvp') {
      if ($capacity_raw === '' || $capacity_raw === NULL) {
        $capacity = NULL;
      }
      else {
        $cap = (int) $capacity_raw;
        $capacity = $cap > 0 ? $cap : NULL;
      }
    }

    $external_url = trim((string) ($mel['external_url'] ?? ''));
    $collect_per_ticket = !empty($mel['collect_attendee_questions']);

    $image_fids = $mel['field_event_image'] ?? [];
    $image_fid = 0;
    if (is_array($image_fids) && $image_fids !== []) {
      $image_fid = (int) reset($image_fids);
    }

    $tiers_raw = $mel['studio_ticket_tiers'] ?? '';
    $studio_ticket_tiers = [];
    if (is_string($tiers_raw) && $tiers_raw !== '') {
      try {
        $decoded = json_decode($tiers_raw, TRUE, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
          foreach ($decoded as $item) {
            if (is_array($item)) {
              $studio_ticket_tiers[] = $this->normalizeStudioTierRow($item);
            }
          }
        }
      }
      catch (\JsonException) {
        $studio_ticket_tiers = [];
      }
    }

    return [
      'title' => $mel['title'] ?? '',
      'summary' => $mel['summary'] ?? '',
      'body' => $mel['body'] ?? '',
      'field_event_image' => $image_fid,
      'field_event_image_alt' => trim((string) ($mel['field_event_image_alt'] ?? '')),
      'field_category' => $this->extractMultipleEntityIds($mel['field_category'] ?? ''),
      'field_tags' => $this->extractMultipleEntityIds($mel['field_tags'] ?? ''),
      'field_product_target' => $this->extractSingleEntityId($mel['field_product_target'] ?? NULL),
      'field_ticket_types' => $this->extractMultipleEntityIds($mel['field_ticket_types'] ?? ''),
      'studio_ticket_tiers' => $studio_ticket_tiers,
      'venue_choice' => $choice,
      'venue_id' => $venue_id,
      'new_venue_name' => $new_name,
      'field_location' => $field_location,
      'field_event_start' => $this->normalizeDatetimeValue($mel['start_date'] ?? NULL),
      'field_event_end' => $this->normalizeDatetimeValue($mel['end_date'] ?? NULL),
      'field_event_type' => $ticket_type,
      'ticket_type' => $ticket_type,
      'capacity' => $capacity,
      'external_url' => $external_url,
      'collect_per_ticket' => $collect_per_ticket,
      'collect_attendee_questions' => $collect_per_ticket,
      'enable_donations' => !empty($mel['enable_donations']),
      'donation_enabled' => !empty($mel['enable_donations']),
      'donation_amount' => ($mel['donation_amount'] ?? '') !== '' ? (string) $mel['donation_amount'] : NULL,
      'donation_options' => trim((string) ($mel['donation_options'] ?? '')),
      'donation_label' => trim((string) ($mel['donation_label'] ?? 'Support this event')) ?: 'Support this event',
      'status' => !empty($mel['status']),
      'field_location_latitude' => $mel['field_location_latitude'] ?? NULL,
      'field_location_longitude' => $mel['field_location_longitude'] ?? NULL,
      'event_highlights' => $this->normalizeEventHighlightsPayload($mel),
      'event_highlights_items_state' => trim((string) (($mel['event_highlights'] ?? [])['items_state'] ?? '')),
    ];
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function normalizeStudioTierRow(array $row): array {
    $row['capacity'] = max(1, (int) ($row['capacity'] ?? 0));
    return $row;
  }

  /**
   * @return list<int>
   */
  private function extractMultipleEntityIds(mixed $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (is_array($raw)) {
      $ids = [];
      foreach ($raw as $item) {
        if (is_array($item) && isset($item['target_id'])) {
          $tid = (int) $item['target_id'];
          if ($tid > 0) {
            $ids[] = $tid;
          }
        }
        elseif (is_numeric($item)) {
          $tid = (int) $item;
          if ($tid > 0) {
            $ids[] = $tid;
          }
        }
        elseif (is_string($item)) {
          $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($item);
          if ($eid !== NULL) {
            $ids[] = (int) $eid;
          }
        }
      }
      return array_values(array_unique(array_filter($ids)));
    }
    if (is_string($raw)) {
      $ids = [];
      foreach (Tags::explode($raw) as $part) {
        $part = trim($part);
        if ($part === '') {
          continue;
        }
        $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($part);
        if ($eid !== NULL) {
          $ids[] = (int) $eid;
        }
      }
      return array_values(array_unique(array_filter($ids)));
    }
    return [];
  }

  private function extractSingleEntityId(mixed $raw): ?int {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (is_numeric($raw)) {
      $id = (int) $raw;
      return $id > 0 ? $id : NULL;
    }
    if (is_string($raw)) {
      $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);
      return $eid !== NULL ? (int) $eid : NULL;
    }
    if (is_array($raw) && isset($raw[0]['target_id'])) {
      $id = (int) $raw[0]['target_id'];
      return $id > 0 ? $id : NULL;
    }
    return NULL;
  }

  private function normalizeDatetimeValue(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if ($value instanceof DrupalDateTime) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if (is_array($value) && isset($value['object']) && $value['object'] instanceof DrupalDateTime) {
      return $value['object']->format('Y-m-d\TH:i:s');
    }
    if (is_array($value)) {
      $date = trim((string) ($value['date'] ?? ''));
      $time = trim((string) ($value['time'] ?? ''));
      if ($date === '') {
        return NULL;
      }
      if ($time === '') {
        $time = '00:00:00';
      }
      try {
        $dt = new DrupalDateTime($date . 'T' . $time);
        return $dt->format('Y-m-d\TH:i:s');
      }
      catch (\Throwable) {
        return NULL;
      }
    }
    return is_string($value) ? $value : NULL;
  }

}
