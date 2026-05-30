<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Form\EventTicketManagerForm;
use Drupal\node\NodeInterface;

/**
 * Builds Event Studio `mel` default values from the event node (no monolithic form).
 *
 * Produces the same shape as extracting #default_value from EventStudioForm's mel tree.
 */
final class EventStudioMelDefaultsProvider {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountProxyInterface $currentUser,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the mel Form API subtree used for baseline extraction and normalization.
   *
   * @return array<string, mixed>
   */
  public function buildMelFormTree(NodeInterface $event): array {
    $has_saved_event = $event->id() !== NULL && (int) $event->id() > 0;

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

    $venue_default = $this->entityAutocompleteMelNormalizer->normalizeSingle($venue_default, 'myeventlane_venue', 'mel.venue_saved');
    $product_default = $this->entityAutocompleteMelNormalizer->normalizeSingle($product_default, 'commerce_product', 'mel.field_product_target');
    $category_default = $this->entityAutocompleteMelNormalizer->normalizeTags($category_default, 'taxonomy_term', 'mel.field_category');
    $tags_default = $this->entityAutocompleteMelNormalizer->normalizeTags($tags_default, 'taxonomy_term', 'mel.field_tags');
    $field_accessibility_default = $this->entityAutocompleteMelNormalizer->normalizeTags($field_accessibility_default, 'taxonomy_term', 'mel.field_accessibility');

    $title_trimmed = trim((string) $event->getTitle());
    $is_placeholder_title = $title_trimmed === '' || $title_trimmed === (string) $this->t('Untitled event');

    $mel = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio']],
    ];

    if ($is_placeholder_title) {
      $mel['guidance_title'] = [
        '#type' => 'container',
        '#weight' => -20,
        '#attributes' => ['class' => ['mel-studio-guidance', 'mel-studio-guidance--title', 'messages', 'messages--status']],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Start by giving your event a name'),
          '#attributes' => ['class' => ['mel-studio-guidance__text']],
        ],
      ];
    }

    $mel['title'] = [
      '#type' => 'textfield',
      '#default_value' => $event->label(),
    ];

    $mel['summary'] = [
      '#type' => 'textarea',
      '#default_value' => $summary,
    ];

    $mel['ai_settings'] = [
      '#type' => 'container',
      'ai_tone' => [
        '#type' => 'hidden',
        '#default_value' => 'community',
      ],
      'ai_audience' => [
        '#type' => 'hidden',
        '#default_value' => 'general',
      ],
    ];

    $mel['body'] = [
      '#type' => 'textarea',
      '#default_value' => $body_default,
    ];

    $mel['field_event_intro'] = [
      '#type' => 'textarea',
      '#default_value' => $field_event_intro_default,
    ];

    $highlights_json = $this->encodeEventHighlightsJsonForEvent($event);
    $mel['event_highlights'] = [
      '#type' => 'container',
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('What makes your event special?'),
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Add up to 6 highlights that help people decide to attend.'),
      ],
      'errors' => [
        '#type' => 'container',
        'text' => [
          '#markup' => '<p class="mel-highlights-editor__errors-text"></p>',
        ],
      ],
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => $highlights_json,
      ],
      'builder' => [
        '#type' => 'container',
        'table' => [
          '#type' => 'table',
          '#empty' => $this->t('No highlights yet.'),
        ],
        'add' => [
          '#type' => 'button',
          '#value' => $this->t('Add highlight'),
        ],
      ],
    ];

    $mel['field_event_image'] = [
      '#type' => 'managed_file',
      '#default_value' => $image_fids,
    ];

    $mel['field_event_image_alt'] = [
      '#type' => 'textfield',
      '#default_value' => $image_alt_default,
    ];

    $mel['field_contact_email'] = [
      '#type' => 'email',
      '#default_value' => $field_contact_email_default,
    ];

    $mel['field_contact_phone'] = [
      '#type' => 'tel',
      '#default_value' => $field_contact_phone_default,
    ];

    $start_default = $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
      ? new DrupalDateTime($event->get('field_event_start')->value)
      : NULL;
    $end_default = $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
      ? new DrupalDateTime($event->get('field_event_end')->value)
      : NULL;

    $mel['start_date'] = [
      '#type' => 'datetime',
      '#date_increment' => 15,
      '#default_value' => $start_default,
    ];

    $mel['end_date'] = [
      '#type' => 'datetime',
      '#date_increment' => 15,
      '#default_value' => $end_default,
    ];

    $mel['field_sales_start'] = [
      '#type' => 'datetime',
      '#date_increment' => 15,
      '#default_value' => $field_sales_start_default,
    ];

    $mel['field_sales_end'] = [
      '#type' => 'datetime',
      '#date_increment' => 15,
      '#default_value' => $field_sales_end_default,
    ];

    $mel['field_age_policy'] = [
      '#type' => 'select',
      '#options' => $this->listStringFieldOptions('field_age_policy') ?: [
        'all_ages' => $this->t('All ages'),
        '18_plus' => $this->t('18+'),
        '16_plus' => $this->t('16+'),
        'under_18_with_guardian' => $this->t('Under 18 with guardian'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $field_age_policy_default,
    ];

    $mel['field_age_policy_note'] = [
      '#type' => 'textfield',
      '#default_value' => $field_age_policy_note_default,
    ];

    $mel['field_age_restriction'] = [
      '#type' => 'select',
      '#options' => $this->listStringFieldOptions('field_age_restriction'),
      '#default_value' => $field_age_restriction_default,
    ];

    $mel['field_refund_policy'] = [
      '#type' => 'select',
      '#options' => $this->listStringFieldOptions('field_refund_policy'),
      '#default_value' => $field_refund_policy_default,
    ];

    $mel['venue_mode'] = [
      '#type' => 'radios',
      '#default_value' => $venue_mode_default,
    ];

    $mel['venue_saved'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'myeventlane_venue',
      '#default_value' => $venue_default,
    ];

    $mel['venue_create_name'] = [
      '#type' => 'textfield',
    ];

    $mel['location_search'] = [
      '#type' => 'textfield',
    ];

    $mel['field_location'] = [
      '#type' => 'hidden',
      '#default_value' => $location_json_default,
    ];

    $mel['field_location_latitude'] = [
      '#type' => 'hidden',
      '#default_value' => $lat_default,
    ];

    $mel['field_location_longitude'] = [
      '#type' => 'hidden',
      '#default_value' => $lng_default,
    ];

    $mel['tickets_intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Simpler events get more bookings. Start with one ticket — you can add more later.'),
    ];

    $mel['field_event_type'] = [
      '#type' => 'radios',
      '#default_value' => $type_default,
    ];

    $mel['tickets_section'] = [
      '#type' => 'container',
    ];
    if ($has_saved_event) {
      $mel['tickets_section']['tickets_guidance'] = [
        '#type' => 'container',
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Add ticket rows with the quick actions, mark tickets Active when they are ready to sell, then click Save and sync tickets.'),
        ],
      ];
      $mel['tickets_section']['tickets'] = $this->formBuilder->getForm(EventTicketManagerForm::class, $event);
    }
    else {
      $mel['tickets_section']['save_first'] = [
        '#type' => 'container',
        'markup' => [
          '#markup' => '
            <div class="mel-ticket-cta__inner">
              <h3>Tickets</h3>
              <p>Save this event before adding ticket types.</p>
              <span class="button button--primary is-disabled" aria-disabled="true">' . $this->t('Save event to manage tickets') . '</span>
            </div>',
        ],
      ];
    }

    $mel['rsvp_capacity'] = [
      '#type' => 'number',
      '#default_value' => $capacity_default,
    ];

    $mel['field_product_target'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'commerce_product',
      '#default_value' => $product_default,
    ];

    $mel['external_url'] = [
      '#type' => 'url',
      '#default_value' => $external_default,
    ];

    $mel['collect_attendee_questions'] = [
      '#type' => 'hidden',
      '#default_value' => $collect_default ? '1' : '0',
    ];

    $attendee_questions_json_default = $this->encodeAttendeeQuestionsJsonForEvent($event);
    $mel['attendee_questions_editor'] = [
      '#type' => 'container',
      'checkout_workspace_cta' => [
        '#type' => 'container',
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Checkout questions are managed in the Checkout questions workspace. Use that table for status, ticket targeting, and archiving.'),
        ],
        'copy_secondary' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('The list below is a quick preview. Saving this event updates labels and types only; it will not remove questions you edited in the workspace.'),
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Manage checkout questions'),
          '#url' => Url::fromRoute('myeventlane_event_studio.workspace_questions', ['node' => $event->id()]),
        ],
      ],
      'library_wrap' => [
        '#type' => 'container',
        'library' => [
          '#type' => 'select',
          '#options' => [],
          '#empty_option' => $this->t('- Choose a saved question -'),
          '#empty_value' => '',
          '#access' => FALSE,
        ],
        'library_add' => [
          '#type' => 'button',
          '#value' => $this->t('Add from library'),
          '#access' => FALSE,
        ],
      ],
      'guidance' => [
        '#type' => 'container',
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Default off. Simpler events get more bookings — only ask what you truly need.'),
        ],
      ],
      'limit_warn' => [
        '#type' => 'container',
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('More questions may reduce bookings'),
        ],
      ],
      'add_menu' => [
        '#type' => 'container',
        'add' => [
          '#type' => 'button',
          '#value' => $this->t('Add question'),
        ],
        'menu' => [
          '#type' => 'container',
          'p_dietary' => [
            '#type' => 'button',
            '#value' => $this->t('Dietary requirements'),
          ],
          'p_access' => [
            '#type' => 'button',
            '#value' => $this->t('Accessibility needs'),
          ],
          'p_phone' => [
            '#type' => 'button',
            '#value' => $this->t('Phone number'),
          ],
          'p_custom' => [
            '#type' => 'button',
            '#value' => $this->t('Custom question'),
          ],
        ],
      ],
      'list' => [
        '#type' => 'container',
      ],
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => $attendee_questions_json_default,
      ],
    ];

    $mel['enable_donations'] = [
      '#type' => 'checkbox',
      '#default_value' => $enable_donations_default,
    ];

    $mel['donation_amount'] = [
      '#type' => 'number',
      '#default_value' => $donation_amount_default,
    ];

    $mel['donation_options'] = [
      '#type' => 'textfield',
      '#default_value' => $donation_options_default,
    ];

    $mel['donation_label'] = [
      '#type' => 'textfield',
      '#default_value' => $donation_label_default,
    ];

    $mel['ticket_summary'] = [
      '#type' => 'container',
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Ticket setup summary'),
      ],
      'body' => [
        '#type' => 'container',
        '#markup' => '',
      ],
    ];

    $mel['field_category'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#default_value' => $category_default,
    ];

    $mel['field_tags'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#default_value' => $tags_default,
    ];

    $mel['field_accessibility'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#default_value' => $field_accessibility_default,
    ];

    $mel['field_accessibility_contact'] = [
      '#type' => 'textarea',
      '#default_value' => $field_accessibility_contact_default,
    ];

    $mel['field_accessibility_directions'] = [
      '#type' => 'textarea',
      '#default_value' => $field_accessibility_directions_default,
    ];

    $mel['field_accessibility_entry'] = [
      '#type' => 'textarea',
      '#default_value' => $field_accessibility_entry_default,
    ];

    $mel['field_accessibility_parking'] = [
      '#type' => 'textarea',
      '#default_value' => $field_accessibility_parking_default,
    ];

    $mel['status'] = [
      '#type' => 'hidden',
      '#default_value' => $event->isPublished() ? '1' : '0',
    ];

    return $mel;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildNormalizedBaselineMel(NodeInterface $event): array {
    $melForm = $this->buildMelFormTree($event);
    $baseline = EventStudioMelDefaultsExtraction::extractDefaultsRecursive($melForm);
    if (!is_array($baseline)) {
      return [];
    }

    return $this->entityAutocompleteMelNormalizer->normalizeValuesForForm($melForm, $baseline, [
      'section' => 'baseline',
      'event_id' => (int) $event->id(),
      'uid' => NULL,
    ]);
  }

  /**
   * @return array<string, string>
   */
  private function listStringFieldOptions(string $field_name): array {
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

  private function encodeAttendeeQuestionsJsonForEvent(NodeInterface $event): string {
    if (!$event->hasField('field_attendee_questions') || $event->get('field_attendee_questions')->isEmpty()) {
      return '[]';
    }
    $rows = [];
    foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
      if ($paragraph->bundle() !== 'attendee_extra_field') {
        continue;
      }
      $label = '';
      if ($paragraph->hasField('field_question_label') && !$paragraph->get('field_question_label')->isEmpty()) {
        $label = trim((string) ($paragraph->get('field_question_label')->value ?? ''));
      }
      if ($label === '') {
        continue;
      }
      $type = 'textfield';
      if ($paragraph->hasField('field_question_type') && !$paragraph->get('field_question_type')->isEmpty()) {
        $type = (string) ($paragraph->get('field_question_type')->value ?? 'textfield');
      }
      $required = FALSE;
      if ($paragraph->hasField('field_question_required') && !$paragraph->get('field_question_required')->isEmpty()) {
        $required = (bool) $paragraph->get('field_question_required')->value;
      }
      $row = [
        'id' => (int) $paragraph->id(),
        'label' => $label,
        'type' => $type,
        'required' => $required,
        'save_to_library' => FALSE,
      ];
      if ($paragraph->hasField('field_question_status') && !$paragraph->get('field_question_status')->isEmpty()) {
        $row['status'] = (string) $paragraph->get('field_question_status')->value;
      }
      if ($paragraph->hasField('field_question_applicability') && !$paragraph->get('field_question_applicability')->isEmpty()) {
        $row['applicability'] = (string) $paragraph->get('field_question_applicability')->value;
      }
      if ($paragraph->hasField('field_question_ticket_types') && !$paragraph->get('field_question_ticket_types')->isEmpty()) {
        $ticket_type_ids = [];
        foreach ($paragraph->get('field_question_ticket_types')->getValue() as $item) {
          $target_id = isset($item['target_id']) ? (int) $item['target_id'] : 0;
          if ($target_id > 0) {
            $ticket_type_ids[] = $target_id;
          }
        }
        if ($ticket_type_ids !== []) {
          $row['ticket_type_ids'] = $ticket_type_ids;
        }
      }
      if ($paragraph->hasField('field_question_options') && !$paragraph->get('field_question_options')->isEmpty()) {
        $opt_raw = trim((string) ($paragraph->get('field_question_options')->value ?? ''));
        if ($opt_raw !== '') {
          $opts = [];
          foreach (preg_split('/\r?\n/', $opt_raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
              $opts[] = $line;
            }
          }
          if ($opts !== []) {
            $row['options'] = $opts;
          }
        }
      }
      if ($paragraph->hasField('field_question_machine_name') && !$paragraph->get('field_question_machine_name')->isEmpty()) {
        $machine = trim((string) ($paragraph->get('field_question_machine_name')->value ?? ''));
        if ($machine !== '') {
          $row['machine_name'] = $machine;
        }
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

}
