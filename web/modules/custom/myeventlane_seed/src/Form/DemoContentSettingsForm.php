<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_seed\Service\DemoEventPurger;
use Drupal\myeventlane_seed\Service\DemoEventSeeder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings for configurable MyEventLane demo event generation.
 */
final class DemoContentSettingsForm extends ConfigFormBase {

  /**
   * @var list<array{string, string, string, string, string, string, string}>
   */
  private const FIELD_TABLE_ROWS = [
    ['Title', 'title', 'node:event', 'yes', 'populate_title', 'deterministic seed title', 'event deleted by seed key'],
    ['Event type', 'field_event_type', 'node:event', 'yes', 'populate_event_type', 'RSVP/Paid mix', 'event deleted by seed key'],
    ['Start date', 'field_event_start', 'node:event', 'yes', 'populate_start_end_dates', 'relative future dates', 'event deleted by seed key'],
    ['End date', 'field_event_end', 'node:event', 'yes', 'populate_start_end_dates', 'start + duration', 'event deleted by seed key'],
    ['Vendor', 'field_event_vendor', 'node:event', 'yes', 'populate_vendor_reference', 'CurrentVendorResolver / Anna', 'reference only'],
    ['Store', 'field_event_store', 'node:event', 'yes', 'populate_store_reference', 'vendor field_vendor_store', 'reference only'],
    ['Capacity', 'field_capacity', 'node:event', 'no', 'populate_capacity', 'generated per event', 'event deleted by seed key'],
    ['Event image', 'field_event_image', 'node:event', 'no', 'populate_event_image', 'ImageFactory', 'seeded files purged only'],
    ['Location', 'field_location', 'node:event', 'no', 'populate_location', 'existing location save service if available', 'event deleted by seed key'],
    ['Venue name', 'field_venue_name', 'node:event', 'no', 'populate_venue_name', 'deterministic venue names', 'event deleted by seed key'],
    ['Body', 'body', 'node:event', 'no', 'populate_body', 'generated MEL event copy', 'event deleted by seed key'],
    ['Summary', 'field_event_summary', 'node:event', 'no', 'populate_event_summary', 'includes mel_seed_key marker', 'event deleted by seed key'],
    ['Product target', 'field_product_target', 'node:event', 'paid only', 'populate_ticket_types', 'TicketTierLifecycleService', 'seeded products/variations purged only if seed-owned'],
    ['Ticket types', 'field_ticket_types', 'node:event', 'paid only', 'populate_ticket_types', 'mel_ticket_type lifecycle', 'seeded ticket types purged only'],
    ['Highlights', 'field_event_highlights', 'paragraph:event_highlight', 'no', 'populate_highlights', 'Paragraph entity', 'seeded paragraphs purged'],
    ['Accessibility', 'field_accessibility', 'taxonomy:accessibility', 'no', 'populate_accessibility', 'term lookup by name', 'reference only'],
    ['Attendee questions', 'field_attendee_questions', 'paragraph:attendee_extra_field', 'no', 'populate_attendee_questions', 'Paragraph entity', 'seeded paragraphs purged'],
    ['RSVP settings', 'existing RSVP model', 'event/RSVP entity', 'RSVP only', 'populate_rsvp_settings', 'reuse existing RSVP logic if present', 'seeded RSVP data purged only'],
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly DemoEventSeeder $demoEventSeeder,
    private readonly DemoEventPurger $demoEventPurger,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('myeventlane_seed.demo_event_seeder'),
      $container->get('myeventlane_seed.demo_event_purger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_seed_demo_content_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_seed.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_seed.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General generation settings'),
      '#tree' => TRUE,
      '#open' => TRUE,
    ];
    $form['general']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable demo content generation'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['general']['owner_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner username'),
      '#default_value' => $config->get('owner_username'),
      '#required' => TRUE,
    ];
    $form['general']['event_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Event count'),
      '#default_value' => $config->get('event_count'),
      '#min' => 1,
      '#max' => 50,
      '#required' => TRUE,
    ];
    $form['general']['title_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title prefix'),
      '#default_value' => $config->get('title_prefix'),
      '#required' => TRUE,
    ];
    $form['general']['seed_key_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Seed key prefix'),
      '#default_value' => $config->get('seed_key_prefix'),
      '#required' => TRUE,
    ];
    $form['general']['default_timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Default timezone'),
      '#options' => TimeZoneFormHelper::getOptionsList(TRUE, TRUE),
      '#default_value' => $config->get('default_timezone'),
      '#required' => TRUE,
    ];
    $form['general']['future_date_start_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Future date start (days from now)'),
      '#default_value' => $config->get('future_date_start_days'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['general']['future_date_spacing_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Future date spacing (days between events)'),
      '#default_value' => $config->get('future_date_spacing_days'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['general']['update_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update existing seeded events (match by seed key)'),
      '#default_value' => $config->get('update_existing'),
    ];
    $form['general']['purge_before_generate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Purge seeded demo content before generating'),
      '#default_value' => $config->get('purge_before_generate'),
    ];

    $form['population'] = [
      '#type' => 'details',
      '#title' => $this->t('Event field population toggles'),
      '#tree' => TRUE,
      '#open' => TRUE,
    ];
    foreach ($this->populationToggleKeys() as $key => $label) {
      $form['population'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $config->get($key),
      ];
    }

    $form['mix'] = [
      '#type' => 'details',
      '#title' => $this->t('Content type mix'),
      '#tree' => TRUE,
      '#open' => TRUE,
    ];
    $form['mix']['generate_rsvp_events'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate RSVP events'),
      '#default_value' => $config->get('generate_rsvp_events'),
    ];
    $form['mix']['generate_paid_events'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate paid events'),
      '#default_value' => $config->get('generate_paid_events'),
    ];
    $form['mix']['rsvp_event_count'] = [
      '#type' => 'number',
      '#title' => $this->t('RSVP event count'),
      '#default_value' => $config->get('rsvp_event_count'),
      '#min' => 0,
    ];
    $form['mix']['paid_event_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Paid event count'),
      '#default_value' => $config->get('paid_event_count'),
      '#min' => 0,
    ];

    $form['tickets'] = [
      '#type' => 'details',
      '#title' => $this->t('Ticket settings'),
      '#tree' => TRUE,
      '#open' => FALSE,
    ];
    $form['tickets']['default_currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default currency'),
      '#default_value' => $config->get('default_currency'),
      '#size' => 5,
      '#maxlength' => 3,
    ];
    $form['tickets']['default_paid_ticket_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Default paid ticket capacity'),
      '#default_value' => $config->get('default_paid_ticket_capacity'),
      '#min' => 1,
    ];
    $form['tickets']['default_rsvp_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Default RSVP capacity'),
      '#default_value' => $config->get('default_rsvp_capacity'),
      '#min' => 1,
    ];
    foreach ([
      'create_general_ticket' => $this->t('Create general admission ticket'),
      'create_concession_ticket' => $this->t('Create concession ticket'),
      'create_early_bird_ticket' => $this->t('Create early bird ticket'),
      'create_supporter_ticket' => $this->t('Create supporter ticket'),
    ] as $key => $label) {
      $form['tickets'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $config->get($key),
      ];
    }
    $form['tickets']['min_ticket_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum ticket price'),
      '#default_value' => $config->get('min_ticket_price'),
      '#min' => 0,
      '#step' => 0.01,
    ];
    $form['tickets']['max_ticket_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum ticket price'),
      '#default_value' => $config->get('max_ticket_price'),
      '#min' => 0,
      '#step' => 0.01,
    ];

    $form['accessibility'] = [
      '#type' => 'details',
      '#title' => $this->t('Accessibility settings'),
      '#tree' => TRUE,
      '#open' => FALSE,
    ];
    foreach ([
      'use_wheelchair_access' => $this->t('Wheelchair accessible'),
      'use_auslan_interpreter' => $this->t('Sign language interpreter'),
      'use_sensory_friendly' => $this->t('Sensory-friendly (cognitive accessibility)'),
      'use_quiet_space' => $this->t('Quiet space'),
      'use_captions' => $this->t('Closed captions (if term exists)'),
    ] as $key => $label) {
      $form['accessibility'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $config->get($key),
      ];
    }
    $form['accessibility']['skip_missing_accessibility_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip missing accessibility terms (log warning)'),
      '#default_value' => $config->get('skip_missing_accessibility_terms'),
    ];

    $form['images'] = [
      '#type' => 'details',
      '#title' => $this->t('Image settings'),
      '#tree' => TRUE,
      '#open' => FALSE,
    ];
    $form['images']['generate_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate placeholder images'),
      '#default_value' => $config->get('generate_images'),
    ];
    $form['images']['image_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image directory'),
      '#default_value' => $config->get('image_directory'),
    ];
    $form['images']['image_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Image width'),
      '#default_value' => $config->get('image_width'),
      '#min' => 100,
    ];
    $form['images']['image_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Image height'),
      '#default_value' => $config->get('image_height'),
      '#min' => 100,
    ];
    $form['images']['reuse_existing_seed_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Reuse existing seed images when present'),
      '#default_value' => $config->get('reuse_existing_seed_images'),
    ];

    $form['safety'] = [
      '#type' => 'details',
      '#title' => $this->t('Safety settings'),
      '#tree' => TRUE,
      '#open' => FALSE,
    ];
    $form['safety']['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run (log only, no saves)'),
      '#default_value' => $config->get('dry_run'),
    ];
    $form['safety']['allow_purge_from_ui'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow purge from this form'),
      '#default_value' => $config->get('allow_purge_from_ui'),
    ];
    $form['safety']['require_confirm_text'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require confirmation text for generate/purge actions'),
      '#default_value' => $config->get('require_confirm_text'),
    ];
    $form['safety']['confirm_text_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Confirmation text'),
      '#default_value' => $config->get('confirm_text_value'),
    ];

    if ($config->get('allow_purge_from_ui') || $config->get('require_confirm_text')) {
      $form['confirm'] = [
        '#type' => 'details',
        '#title' => $this->t('Confirmation'),
        '#tree' => TRUE,
        '#open' => TRUE,
      ];
      $form['confirm']['confirm_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Type confirmation text to run generate or purge'),
        '#description' => $this->t('Required text: @text', ['@text' => $config->get('confirm_text_value')]),
      ];
    }

    $form['field_table'] = [
      '#type' => 'details',
      '#title' => $this->t('Generatable event fields'),
      '#open' => TRUE,
    ];
    $header = [
      $this->t('Field / feature'),
      $this->t('Machine name'),
      $this->t('Entity / bundle'),
      $this->t('Required for valid event'),
      $this->t('Controlled by setting'),
      $this->t('Population method'),
      $this->t('Purge impact'),
    ];
    $rows = [];
    foreach (self::FIELD_TABLE_ROWS as $row) {
      $rows[] = $row;
    }
    $form['field_table']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No fields defined.'),
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
      '#submit' => ['::submitForm'],
    ];
    $form['actions']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and generate demo content'),
      '#submit' => ['::submitGenerate'],
    ];
    if ($config->get('allow_purge_from_ui')) {
      $form['actions']['purge'] = [
        '#type' => 'submit',
        '#value' => $this->t('Purge seeded demo content only'),
        '#submit' => ['::submitPurge'],
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $values = $this->extractSettingsFromFormState($form_state);
    try {
      $this->demoEventSeeder->validateSettings($values);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('general][event_count', $e->getMessage());
    }

    $parents = $form_state->getTriggeringElement()['#parents'] ?? [];
    $trigger = is_array($parents) ? (string) end($parents) : '';
    if (in_array($trigger, ['generate', 'purge'], TRUE)) {
      $this->validateConfirmText($form_state, $values);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveConfig($form_state);
    $this->messenger()->addStatus($this->t('Demo content settings saved.'));
  }

  /**
   * Saves config then generates demo events from saved settings.
   */
  public function submitGenerate(array &$form, FormStateInterface $form_state): void {
    $this->saveConfig($form_state);
    try {
      $summary = $this->demoEventSeeder->seed(['use-settings' => TRUE]);
      if (!empty($summary['dry_run'])) {
        $this->messenger()->addStatus($this->t('Dry-run complete. @count events would be generated.', [
          '@count' => count($summary['events']),
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Generated @count demo events for @user.', [
          '@count' => count($summary['events']),
          '@user' => $summary['owner_username'],
        ]));
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Demo generation failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Purges seeded demo content for the configured owner.
   */
  public function submitPurge(array &$form, FormStateInterface $form_state): void {
    $this->saveConfig($form_state);
    if (!$this->config('myeventlane_seed.settings')->get('allow_purge_from_ui')) {
      $this->messenger()->addError($this->t('Purge from the UI is disabled.'));
      return;
    }
    try {
      $stats = $this->demoEventPurger->purge();
      $this->messenger()->addStatus($this->t('Purged @events events, @tickets ticket types, @products products.', [
        '@events' => $stats['events_deleted'],
        '@tickets' => $stats['ticket_types_deleted'],
        '@products' => $stats['products_deleted'],
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Purge failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  private function saveConfig(FormStateInterface $form_state): void {
    $values = $this->extractSettingsFromFormState($form_state);
    $editable = $this->config('myeventlane_seed.settings');
    foreach ($values as $key => $value) {
      $editable->set($key, $value);
    }
    $editable->save();
  }

  /**
   * @return array<string, mixed>
   */
  private function extractSettingsFromFormState(FormStateInterface $form_state): array {
    $bool = static fn (mixed $v): bool => !empty($v);
    $g = static fn (string $key) => $form_state->getValue(['general', $key]);
    $p = static fn (string $key) => $form_state->getValue(['population', $key]);
    $m = static fn (string $key) => $form_state->getValue(['mix', $key]);
    $t = static fn (string $key) => $form_state->getValue(['tickets', $key]);
    $a = static fn (string $key) => $form_state->getValue(['accessibility', $key]);
    $i = static fn (string $key) => $form_state->getValue(['images', $key]);
    $s = static fn (string $key) => $form_state->getValue(['safety', $key]);

    return [
      'enabled' => $bool($g('enabled')),
      'owner_username' => trim((string) $g('owner_username')),
      'event_count' => (int) $g('event_count'),
      'title_prefix' => trim((string) $g('title_prefix')),
      'seed_key_prefix' => trim((string) $g('seed_key_prefix')),
      'default_timezone' => (string) $g('default_timezone'),
      'future_date_start_days' => (int) $g('future_date_start_days'),
      'future_date_spacing_days' => (int) $g('future_date_spacing_days'),
      'update_existing' => $bool($g('update_existing')),
      'purge_before_generate' => $bool($g('purge_before_generate')),
      'populate_title' => $bool($p('populate_title')),
      'populate_event_type' => $bool($p('populate_event_type')),
      'populate_start_end_dates' => $bool($p('populate_start_end_dates')),
      'populate_vendor_reference' => $bool($p('populate_vendor_reference')),
      'populate_store_reference' => $bool($p('populate_store_reference')),
      'populate_capacity' => $bool($p('populate_capacity')),
      'populate_event_image' => $bool($p('populate_event_image')),
      'populate_location' => $bool($p('populate_location')),
      'populate_venue_name' => $bool($p('populate_venue_name')),
      'populate_body' => $bool($p('populate_body')),
      'populate_event_summary' => $bool($p('populate_event_summary')),
      'populate_highlights' => $bool($p('populate_highlights')),
      'populate_accessibility' => $bool($p('populate_accessibility')),
      'populate_attendee_questions' => $bool($p('populate_attendee_questions')),
      'populate_ticket_types' => $bool($p('populate_ticket_types')),
      'populate_rsvp_settings' => $bool($p('populate_rsvp_settings')),
      'generate_rsvp_events' => $bool($m('generate_rsvp_events')),
      'generate_paid_events' => $bool($m('generate_paid_events')),
      'rsvp_event_count' => (int) $m('rsvp_event_count'),
      'paid_event_count' => (int) $m('paid_event_count'),
      'default_currency' => strtoupper(trim((string) $t('default_currency'))),
      'default_paid_ticket_capacity' => (int) $t('default_paid_ticket_capacity'),
      'default_rsvp_capacity' => (int) $t('default_rsvp_capacity'),
      'create_general_ticket' => $bool($t('create_general_ticket')),
      'create_concession_ticket' => $bool($t('create_concession_ticket')),
      'create_early_bird_ticket' => $bool($t('create_early_bird_ticket')),
      'create_supporter_ticket' => $bool($t('create_supporter_ticket')),
      'min_ticket_price' => (float) $t('min_ticket_price'),
      'max_ticket_price' => (float) $t('max_ticket_price'),
      'use_wheelchair_access' => $bool($a('use_wheelchair_access')),
      'use_auslan_interpreter' => $bool($a('use_auslan_interpreter')),
      'use_sensory_friendly' => $bool($a('use_sensory_friendly')),
      'use_quiet_space' => $bool($a('use_quiet_space')),
      'use_captions' => $bool($a('use_captions')),
      'skip_missing_accessibility_terms' => $bool($a('skip_missing_accessibility_terms')),
      'generate_images' => $bool($i('generate_images')),
      'image_directory' => trim((string) $i('image_directory')),
      'image_width' => (int) $i('image_width'),
      'image_height' => (int) $i('image_height'),
      'reuse_existing_seed_images' => $bool($i('reuse_existing_seed_images')),
      'dry_run' => $bool($s('dry_run')),
      'allow_purge_from_ui' => $bool($s('allow_purge_from_ui')),
      'require_confirm_text' => $bool($s('require_confirm_text')),
      'confirm_text_value' => trim((string) $s('confirm_text_value')),
    ];
  }

  /**
   * @param array<string, mixed> $values
   */
  private function validateConfirmText(FormStateInterface $form_state, array $values): void {
    if (empty($values['require_confirm_text'])) {
      return;
    }
    $expected = (string) ($values['confirm_text_value'] ?? '');
    $typed = trim((string) $form_state->getValue(['confirm', 'confirm_text']));
    if ($typed !== $expected) {
      $form_state->setErrorByName('confirm][confirm_text', $this->t('Confirmation text does not match.'));
    }
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function populationToggleKeys(): array {
    return [
      'populate_title' => $this->t('Populate title'),
      'populate_event_type' => $this->t('Populate event type'),
      'populate_start_end_dates' => $this->t('Populate start/end dates'),
      'populate_vendor_reference' => $this->t('Populate vendor reference'),
      'populate_store_reference' => $this->t('Populate store reference'),
      'populate_capacity' => $this->t('Populate capacity'),
      'populate_event_image' => $this->t('Populate event image'),
      'populate_location' => $this->t('Populate location'),
      'populate_venue_name' => $this->t('Populate venue name'),
      'populate_body' => $this->t('Populate body'),
      'populate_event_summary' => $this->t('Populate event summary'),
      'populate_highlights' => $this->t('Populate highlights'),
      'populate_accessibility' => $this->t('Populate accessibility'),
      'populate_attendee_questions' => $this->t('Populate attendee questions'),
      'populate_ticket_types' => $this->t('Populate ticket types'),
      'populate_rsvp_settings' => $this->t('Populate RSVP settings'),
    ];
  }

}
