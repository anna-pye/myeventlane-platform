<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_questions\Entity\VendorQuestionInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\MelPlatformSupportWizardFormHelper;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventStudioAiAssistBuilder;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceComponentBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_vendor\Form\EventTicketManagerForm;
use Drupal\myeventlane_vendor\Service\EventTicketReconciliationService;
use Drupal\myeventlane_vendor\Service\VendorPublishRequirementsGate;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Event Studio form — custom MEL UI; persistence only via EventStudioSaveService.
 */
final class EventStudioForm extends FormBase {

  private const ATTENDEE_QUESTION_LIMIT = 5;

  /**
   * Injected services must be protected (not private readonly) so FormBase
   * serialization can restore them from the container on cached form rebuilds.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected EntityFieldManagerInterface $entityFieldManager;

  protected FormBuilderInterface $formBuilder;

  protected EventStudioSaveService $saveService;

  protected AccountProxyInterface $currentUser;

  protected ?LocationProviderManager $locationProvider = NULL;

  /**
   * Allowed icons and highlight row normalization for Event Studio.
   */
  protected EventHighlightHelper $eventHighlightHelper;

  protected TicketTypeManager $ticketTypeManager;

  protected TicketTierLifecycleService $ticketTierLifecycle;

  protected EventTicketReconciliationService $eventTicketReconciliation;

  protected LoggerInterface $logger;

  protected EventStudioMelPayloadService $melPayloadService;

  protected EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer;

  protected VendorPublishRequirementsGate $publishRequirementsGate;

  protected BookingFlowResolver $bookingFlowResolver;

  protected EventStudioGovernanceBuilder $eventStudioGovernanceBuilder;

  protected EventStudioGovernanceComponentBuilder $eventStudioGovernanceComponentBuilder;

  protected MelReadinessHelper $readinessHelper;

  protected EventStudioAiAssistBuilder $aiAssistBuilder;

  /**
   * Lazily restored for cached form AJAX (see {@see getMelPlatformSupportWizardForm()}).
   *
   * @var \Drupal\myeventlane_event\Service\MelPlatformSupportWizardFormHelper|null
   */
  protected ?MelPlatformSupportWizardFormHelper $melPlatformSupportWizardForm = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->formBuilder = $container->get('form_builder');
    $instance->saveService = $container->get('myeventlane_event_studio.save');
    $instance->currentUser = $container->get('current_user');
    $instance->locationProvider = $container->has('myeventlane_location.provider_manager')
      ? $container->get('myeventlane_location.provider_manager')
      : NULL;
    $instance->eventHighlightHelper = $container->get('myeventlane_event_studio.highlight_helper');
    $instance->ticketTypeManager = $container->get('myeventlane_event.ticket_type_manager');
    $instance->ticketTierLifecycle = $container->get('myeventlane_event.ticket_tier_lifecycle');
    $instance->eventTicketReconciliation = $container->get('myeventlane_vendor.event_ticket_reconciliation');
    $instance->logger = $container->get('logger.factory')->get('myeventlane_event_studio');
    $instance->melPayloadService = $container->get('myeventlane_event_studio.mel_payload');
    $instance->entityAutocompleteMelNormalizer = $container->get('myeventlane_event_studio.entity_autocomplete_mel_normalizer');
    $instance->publishRequirementsGate = $container->get('myeventlane_vendor.publish_requirements_gate');
    $instance->bookingFlowResolver = $container->get('myeventlane_event.booking_flow_resolver');
    $instance->melPlatformSupportWizardForm = $container->get('myeventlane_event.mel_platform_support_wizard_form');
    $instance->eventStudioGovernanceBuilder = $container->get('myeventlane_event_studio.governance_builder');
    $instance->eventStudioGovernanceComponentBuilder = $container->get('myeventlane_event_studio.governance_component_builder');
    $instance->readinessHelper = $container->get('myeventlane_surface.state_readiness_helper');
    $instance->aiAssistBuilder = $container->get('myeventlane_event_studio.ai_assist_builder');
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
    if (isset($this->entityTypeManager, $this->entityFieldManager, $this->formBuilder, $this->saveService, $this->currentUser, $this->eventHighlightHelper, $this->ticketTypeManager, $this->ticketTierLifecycle, $this->eventTicketReconciliation, $this->logger, $this->melPayloadService, $this->entityAutocompleteMelNormalizer, $this->publishRequirementsGate, $this->bookingFlowResolver, $this->eventStudioGovernanceBuilder, $this->eventStudioGovernanceComponentBuilder, $this->readinessHelper, $this->aiAssistBuilder)
      && isset($this->melPlatformSupportWizardForm)) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $container->get('entity_type.manager');
    }
    if (!isset($this->entityFieldManager)) {
      $this->entityFieldManager = $container->get('entity_field.manager');
    }
    if (!isset($this->formBuilder)) {
      $this->formBuilder = $container->get('form_builder');
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
    if (!isset($this->ticketTypeManager)) {
      $this->ticketTypeManager = $container->get('myeventlane_event.ticket_type_manager');
    }
    if (!isset($this->ticketTierLifecycle)) {
      $this->ticketTierLifecycle = $container->get('myeventlane_event.ticket_tier_lifecycle');
    }
    if (!isset($this->eventTicketReconciliation)) {
      $this->eventTicketReconciliation = $container->get('myeventlane_vendor.event_ticket_reconciliation');
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
    if (!isset($this->publishRequirementsGate)) {
      $this->publishRequirementsGate = $container->get('myeventlane_vendor.publish_requirements_gate');
    }
    if (!isset($this->bookingFlowResolver)) {
      $this->bookingFlowResolver = $container->get('myeventlane_event.booking_flow_resolver');
    }
    if (!isset($this->melPlatformSupportWizardForm)) {
      $this->melPlatformSupportWizardForm = $container->get('myeventlane_event.mel_platform_support_wizard_form');
    }
    if (!isset($this->eventStudioGovernanceBuilder)) {
      $this->eventStudioGovernanceBuilder = $container->get('myeventlane_event_studio.governance_builder');
    }
    if (!isset($this->eventStudioGovernanceComponentBuilder)) {
      $this->eventStudioGovernanceComponentBuilder = $container->get('myeventlane_event_studio.governance_component_builder');
    }
    if (!isset($this->readinessHelper)) {
      $this->readinessHelper = $container->get('myeventlane_surface.state_readiness_helper');
    }
    if (!isset($this->aiAssistBuilder)) {
      $this->aiAssistBuilder = $container->get('myeventlane_event_studio.ai_assist_builder');
    }
  }

  protected function getMelPlatformSupportWizardForm(): MelPlatformSupportWizardFormHelper {
    $this->ensureInjectedServices();
    if (!$this->melPlatformSupportWizardForm instanceof MelPlatformSupportWizardFormHelper) {
      $this->melPlatformSupportWizardForm = \Drupal::getContainer()->get('myeventlane_event.mel_platform_support_wizard_form');
    }
    return $this->melPlatformSupportWizardForm;
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

    if (!$this->currentUser->hasPermission('administer nodes') && (int) $this->currentUser->id() > 0) {
      $flags = $this->publishRequirementsGate->getReadinessFlags($this->currentUser->getAccount());
      $items = $this->readinessHelper->eventStudioPublishReadinessLines($flags);
      if ($items !== []) {
        $form['mel_studio_readiness'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['messages', 'messages--warning', 'mel-event-studio-readiness'],
            'role' => 'status',
          ],
          '#weight' => -100,
        ];
        foreach ($items as $i => $text) {
          $form['mel_studio_readiness']['line_' . $i] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $text,
            '#attributes' => ['class' => ['mel-event-studio-readiness__line']],
          ];
        }
      }
    }

    // AJAX rebuilds do not receive $route_node; cached form state can lose studio_node. Ticket
    // Ticket saves can submit independently from the event save. Rehydrate from hidden nid.
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
    $paid_booking_ready = FALSE;
    if ($has_saved_event && in_array($type_default, ['paid', 'both'], TRUE)) {
      $paid_booking_ready = $this->ticketTypeManager->loadPublishedPaidTicketPrices($event) !== [];
    }

    $venue_default = $this->entityAutocompleteMelNormalizer->normalizeSingle($venue_default, 'myeventlane_venue', 'mel.venue_saved');
    $product_default = $this->entityAutocompleteMelNormalizer->normalizeSingle($product_default, 'commerce_product', 'mel.field_product_target');
    $category_default = $this->entityAutocompleteMelNormalizer->normalizeTags($category_default, 'taxonomy_term', 'mel.field_category');
    $tags_default = $this->entityAutocompleteMelNormalizer->normalizeTags($tags_default, 'taxonomy_term', 'mel.field_tags');
    $field_accessibility_default = $this->entityAutocompleteMelNormalizer->normalizeTags($field_accessibility_default, 'taxonomy_term', 'mel.field_accessibility');

    $title_trimmed = trim((string) $event->getTitle());
    $is_placeholder_title = $title_trimmed === '' || $title_trimmed === (string) $this->t('Untitled event');

    // MEL contribution (donations / platform support): show for non-external events only.
    $show_mel_contribution = $type_default !== 'external';

    $form['mel'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio']],
    ];

    if ($is_placeholder_title) {
      $form['mel']['guidance_title'] = [
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

    $form['mel']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#description' => $this->t('Make it clear and specific.'),
      '#default_value' => $event->label(),
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-input']],
      '#weight' => -10,
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

    $this->aiAssistBuilder->attachToElement($form['mel']['title'], $event, 'title', 'basic');
    $this->aiAssistBuilder->attachToElement($form['mel']['summary'], $event, 'summary', 'basic');
    $this->aiAssistBuilder->attachToElement($form['mel']['body'], $event, 'body', 'content');
    $this->aiAssistBuilder->attachToElement($form['mel']['field_event_intro'], $event, 'field_event_intro', 'content');

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
      '#mel_option_cards' => TRUE,
      '#mel_option_descriptions' => [
        'saved' => $this->t('Pick from venues under your organizer account.'),
        'create' => $this->t('Save a reusable venue while you prepare this event.'),
        'one_off' => $this->t('Use an address once without saving a venue profile.'),
      ],
      '#options' => [
        'saved' => $this->t('Use saved venue'),
        'create' => $this->t('Create new venue'),
        'one_off' => $this->t('One-off address'),
      ],
      '#default_value' => $form_state->getValue(['mel', 'venue_mode'], $venue_mode_default),
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
      '#value' => $this->t('Simpler events get more bookings. Start with one ticket — you can add more later.'),
      '#attributes' => ['class' => ['mel-tickets-intro']],
    ];

    $form['mel']['field_event_type'] = [
      '#type' => 'radios',
      '#title' => '',
      '#mel_option_cards' => TRUE,
      '#mel_option_cards_tickets_layout' => TRUE,
      '#mel_option_descriptions' => [
        'rsvp' => $this->t('Collect RSVPs without taking payment.'),
        'paid' => $this->t('Sell tickets through MyEventLane.'),
        'external' => $this->t('Send guests to Humanitix, Eventbrite, or your site.'),
      ],
      '#options' => [
        'rsvp' => $this->t('RSVP (free)'),
        'paid' => $this->t('Paid tickets'),
        'external' => $this->t('External link'),
      ],
      '#default_value' => $type_default,
    ];

    $form['mel']['tickets_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-tickets-section']],
    ];
    if ($has_saved_event) {
      $form['mel']['tickets_section']['tickets_guidance'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-tickets-paid-guidance']],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Add ticket rows with the quick actions, mark tickets Active when they are ready to sell, then click Save and sync tickets.'),
          '#attributes' => ['class' => ['mel-tickets-paid-guidance__text']],
        ],
      ];
      $form['mel']['tickets_section']['tickets'] = $this->formBuilder->getForm(EventTicketManagerForm::class, $event);
    }
    else {
      $form['mel']['tickets_section']['save_first'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-cta']],
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
    $form['mel']['tickets_section']['#states'] = [
      'visible' => [
        ':input[name="mel[field_event_type]"]' => ['value' => 'paid'],
      ],
    ];

    $form['mel']['rsvp_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('RSVP capacity'),
      '#description' => $this->t('Leave empty for unlimited tickets'),
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
      '#description' => $this->t('Required for paid events: link your Commerce ticket product, then manage ticket types on the Tickets screen.'),
      '#attributes' => ['class' => ['mel-input']],
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

    // Kept for autosave/compatibility; actual collect flag also derives from attendee JSON in payload.
    $form['mel']['collect_attendee_questions'] = [
      '#type' => 'hidden',
      '#default_value' => $collect_default ? '1' : '0',
      '#attributes' => ['data-mel-collect-attendee-sync' => '1'],
    ];

    $attendee_questions_json_default = $this->encodeAttendeeQuestionsJsonForEvent($event);
    $vendor_question_library_options = $this->buildVendorQuestionLibraryOptions();
    $has_vendor_question_library = $vendor_question_library_options !== [];
    $form['mel']['attendee_questions_editor'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-attendee-questions-editor',
        ],
      ],
      'checkout_workspace_cta' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-questions__builder-cta']],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Checkout questions are managed in the Checkout questions workspace. Use that table for status, ticket targeting, and archiving.'),
          '#attributes' => ['class' => ['mel-event-studio-questions__builder-cta-copy']],
        ],
        'copy_secondary' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('The list below is a quick preview. Saving this event updates labels and types only; it will not remove questions you edited in the workspace.'),
          '#attributes' => ['class' => ['mel-event-studio-questions__builder-cta-hint']],
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Manage checkout questions'),
          '#url' => Url::fromRoute('myeventlane_event_studio.workspace_questions', ['node' => $event->id()]),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-event-studio-questions__builder-cta-link']],
        ],
      ],
      'library_wrap' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-attendee-questions-library']],
        'library' => [
          '#type' => 'select',
          '#title' => $this->t('Reuse from library'),
          '#description' => $this->t('Add a question you have saved for this organiser account.'),
          '#options' => $vendor_question_library_options,
          '#empty_option' => $this->t('- Choose a saved question -'),
          '#empty_value' => '',
          '#attributes' => [
            'class' => ['mel-input'],
            'data-mel-attendee-library-select' => '1',
          ],
          '#access' => $has_vendor_question_library,
        ],
        'library_add' => [
          '#type' => 'button',
          '#value' => $this->t('Add from library'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['mel-btn', 'mel-btn--secondary', 'button'],
            'data-mel-attendee-library-add' => '1',
          ],
          '#access' => $has_vendor_question_library,
        ],
      ],
      'guidance' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-attendee-questions-guidance', 'messages', 'messages--status']],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Default off. Simpler events get more bookings — only ask what you truly need.'),
          '#attributes' => ['class' => ['mel-attendee-questions-guidance__text']],
        ],
      ],
      'limit_warn' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'mel-attendee-questions-limit-warn',
          'class' => ['mel-attendee-questions-limit-warn', 'messages', 'messages--warning'],
          'hidden' => 'hidden',
          'data-mel-attendee-question-limit' => (string) self::ATTENDEE_QUESTION_LIMIT,
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('More questions may reduce bookings'),
          '#attributes' => ['class' => ['mel-attendee-questions-limit-warn__text']],
        ],
      ],
      'add_menu' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-attendee-questions-add']],
        'add' => [
          '#type' => 'button',
          '#value' => $this->t('Add question'),
          '#attributes' => [
            'type' => 'button',
            'id' => 'mel-attendee-add-question',
            'class' => ['mel-btn', 'mel-btn--primary', 'button'],
            'aria-haspopup' => 'true',
            'aria-expanded' => 'false',
            'aria-controls' => 'mel-attendee-preset-menu',
          ],
        ],
        'menu' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'mel-attendee-preset-menu',
            'class' => ['mel-attendee-preset-menu'],
            'hidden' => 'hidden',
            'role' => 'menu',
          ],
          'p_dietary' => [
            '#type' => 'button',
            '#value' => $this->t('Dietary requirements'),
            '#attributes' => [
              'type' => 'button',
              'role' => 'menuitem',
              'class' => ['mel-attendee-preset-item'],
              'data-mel-attendee-preset' => 'dietary',
            ],
          ],
          'p_access' => [
            '#type' => 'button',
            '#value' => $this->t('Accessibility needs'),
            '#attributes' => [
              'type' => 'button',
              'role' => 'menuitem',
              'class' => ['mel-attendee-preset-item'],
              'data-mel-attendee-preset' => 'accessibility',
            ],
          ],
          'p_phone' => [
            '#type' => 'button',
            '#value' => $this->t('Phone number'),
            '#attributes' => [
              'type' => 'button',
              'role' => 'menuitem',
              'class' => ['mel-attendee-preset-item'],
              'data-mel-attendee-preset' => 'phone',
            ],
          ],
          'p_custom' => [
            '#type' => 'button',
            '#value' => $this->t('Custom question'),
            '#attributes' => [
              'type' => 'button',
              'role' => 'menuitem',
              'class' => ['mel-attendee-preset-item'],
              'data-mel-attendee-preset' => 'custom',
            ],
          ],
        ],
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-attendee-question-list'],
          'id' => 'mel-attendee-question-list',
          'data-mel-attendee-question-list' => '1',
          'aria-live' => 'polite',
        ],
      ],
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => $attendee_questions_json_default,
        '#attributes' => [
          'id' => 'mel-attendee-questions-json',
          'data-mel-attendee-questions-state' => '1',
        ],
      ],
    ];

    $form['mel']['enable_donations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable optional donations'),
      '#default_value' => $enable_donations_default,
      '#states' => [
        'visible' => [
          'or' => [
            [':input[name="mel[field_event_type]"]' => ['value' => 'rsvp']],
            [':input[name="mel[field_event_type]"]' => ['value' => 'paid']],
          ],
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
      '#type' => 'hidden',
      '#default_value' => $event->isPublished() ? '1' : '0',
    ];

    $this->getMelPlatformSupportWizardForm()->buildSection(
      $form,
      $form_state,
      $event,
      94,
      'mel-mel-support-heading',
      TRUE,
      TRUE,
    );
    if (isset($form['mel_mel_support'])) {
      $form['mel_mel_support']['#access'] = $show_mel_contribution;
      $form['mel_mel_support']['#weight'] = 96;
      $form['mel_mel_support']['#attributes']['class'][] = 'mel-mel-support--studio-card';
      $form['mel_mel_support']['mode']['#title_display'] = 'invisible';
      $form['mel_mel_support']['mode']['#weight'] = 10;
      $form['mel_mel_support']['_trust'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Independent platform fee — never mixed into ticket totals. You choose; attendees see transparent checkout.'),
        '#attributes' => ['class' => ['mel-mel-support__trust']],
        '#weight' => 5,
      ];
    }

    if ($this->locationProvider instanceof LocationProviderManager) {
      $form['#attached']['drupalSettings']['myeventlaneLocation'] = $this->locationProvider->getFrontendSettings();
    }

    $book_url = $this->buildMelEventStudioBookUrl($event, $type_default);
    /** @phpstan-var array{bookingMode: string, availability: string, pricing: array<string, mixed>|null, cta: array<string, mixed>}|null $preview_resolver */
    $preview_resolver = NULL;
    if ($has_saved_event && $event->isPublished()) {
      $preview_resolver = [
        'bookingMode' => $this->bookingFlowResolver->getBookingMode($event),
        'availability' => $this->bookingFlowResolver->getAvailabilityState($event),
        'pricing' => $this->bookingFlowResolver->getDisplayPricing($event),
        'cta' => $this->bookingFlowResolver->getPrimaryCta($event),
      ];
    }

    $form['#attached']['drupalSettings']['melEventStudio'] = [
      'initial' => [
        'published' => $event->isPublished(),
        'hasCoverImage' => $has_cover,
        'needsTicketProduct' => $needs_ticket_product,
        'paidBookingReady' => $paid_booking_ready,
        'ticketType' => $type_default,
        'venueMode' => $venue_mode_default,
      ],
      'highlightIconOptions' => $this->eventHighlightHelper->getIconOptionsForJs(),
      'highlightIconPicker' => $this->eventHighlightHelper->getHighlightIconPickerItems(),
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
        'typeRsvp' => (string) $this->t('RSVP (free)'),
        'typePaid' => (string) $this->t('Paid tickets'),
        'typeExternal' => (string) $this->t('External link'),
      ],
      'defaultCurrency' => $this->resolveStudioDefaultCurrency($event),
      'showMelContribution' => $show_mel_contribution,
      'vendorQuestionLibrary' => $this->buildVendorQuestionLibraryPayload(),
      'urls' => [
        'book' => $book_url,
        'governanceRefresh' => !$event->isNew()
          ? Url::fromRoute('myeventlane_event_studio.governance_refresh', ['node' => (int) $event->id()])->toString()
          : '',
        'governanceComponents' => !$event->isNew()
          ? [
            'intelligence' => Url::fromRoute('myeventlane_event_studio.governance_component', [
              'node' => (int) $event->id(),
              'component' => 'intelligence',
            ])->toString(),
            'workflow' => Url::fromRoute('myeventlane_event_studio.governance_component', [
              'node' => (int) $event->id(),
              'component' => 'workflow',
            ])->toString(),
            'state' => Url::fromRoute('myeventlane_event_studio.governance_component', [
              'node' => (int) $event->id(),
              'component' => 'state',
            ])->toString(),
            'continuity' => Url::fromRoute('myeventlane_event_studio.governance_component', [
              'node' => (int) $event->id(),
              'component' => 'continuity',
            ])->toString(),
          ]
          : [],
      ],
      'previewResolver' => $preview_resolver,
      'previewStrings' => [
        'freeRsvp' => (string) $this->t('Free RSVP'),
        'external' => (string) $this->t('External'),
        'multiplePrices' => (string) $this->t('Multiple prices'),
        'free' => (string) $this->t('Free'),
        'paidIncomplete' => (string) $this->t('Add ticket types and a product to show pricing.'),
        'ctaRsvp' => (string) $this->t('RSVP free'),
        'ctaTickets' => (string) $this->t('Get your tickets'),
        'ctaExternal' => (string) $this->t('View details'),
        'ctaDisabled' => (string) $this->t('Complete ticket setup'),
        'ctaSaveFirst' => (string) $this->t('Save event to enable booking link'),
        'drawerOpen' => (string) $this->t('Show preview'),
        'drawerClose' => (string) $this->t('Hide preview'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-form-actions']],
      '#weight' => 999,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary', 'button--primary', 'mel-builder-save-submit'],
      ],
    ];

    $form['#mel_studio_node'] = $event;

    $governance_bundle = $this->eventStudioGovernanceBuilder->buildForEvent($event);
    CacheableMetadata::createFromRenderArray([
      '#cache' => $governance_bundle['#cache'] ?? ['contexts' => [], 'tags' => []],
    ])->applyTo($form);
    $form['#mel_studio_governance'] = $governance_bundle['twig'] ?? ['enabled' => FALSE];
    if (!empty($governance_bundle['enabled'])) {
      $form['#mel_studio_governance']['components'] = $this->eventStudioGovernanceComponentBuilder->buildAll($governance_bundle);
    }
    if (!empty($governance_bundle['enabled']) && is_array($governance_bundle['js'] ?? NULL)) {
      $form['#attached']['drupalSettings']['melEventStudioGovernance'] = $governance_bundle['js'];
      foreach ([
        'myeventlane_surface/interactions',
        'myeventlane_surface/experience',
        'myeventlane_surface/intelligence',
        'myeventlane_surface/operational_policy',
      ] as $mel_library) {
        $form['#attached']['library'][] = $mel_library;
      }
      if (($governance_bundle['js']['observabilityTier'] ?? '') !== '') {
        $form['#attached']['library'][] = 'myeventlane_surface/observability';
        $form['#attached']['drupalSettings']['melObservability'] = [
          'enabled' => TRUE,
          'tier' => (string) $governance_bundle['js']['observabilityTier'],
        ];
      }
    }

    // Optional advanced Commerce ticket workspace — surfaced in sidebar only (access-checked).
    $form['#mel_advanced_ticket_manager_url'] = NULL;
    if (!$event->isNew()) {
      try {
        $advanced_tickets = Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => (int) $event->id()]);
        if ($advanced_tickets->access()) {
          $form['#mel_advanced_ticket_manager_url'] = $advanced_tickets->toString();
        }
      }
      catch (\Throwable) {
        // Route may be absent in minimal installs.
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $this->ensureInjectedServices();
    $event = $form_state->get('studio_node');

    $mel = $form_state->getValue('mel');
    $event_type = is_array($mel) ? (string) ($mel['field_event_type'] ?? '') : '';
    if ($event_type === ''
      && $event instanceof NodeInterface
      && $event->hasField('field_event_type')
      && !$event->get('field_event_type')->isEmpty()) {
      $event_type = (string) $event->get('field_event_type')->value;
    }

    if ($event instanceof NodeInterface && $event->hasField('field_event_type')) {
      if (in_array($event_type, ['paid', 'both'], TRUE)) {
        if (!$this->ticketTypeManager->hasVendorStore($event)) {
          $this->logger->warning('Event Studio: tickets validation blocked — no vendor store for event @nid', [
            '@nid' => (string) $event->id(),
          ]);
          $form_state->setErrorByName('mel][field_event_type', $this->t('This event does not have a valid vendor store. Complete organiser setup and ensure your vendor account has a store assigned before selling paid tickets.'));
        }
      }
    }

    if ($event instanceof NodeInterface) {
      $this->getMelPlatformSupportWizardForm()->validate($form_state, $event, $event_type);
    }

    $mel = $form_state->getValue('mel');
    if (is_array($mel) && isset($mel['event_highlights']) && is_array($mel['event_highlights'])) {
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

    $mel = $form_state->getValue('mel');
    if (is_array($mel) && isset($mel['attendee_questions_editor']['items_state'])) {
      $raw = trim((string) $mel['attendee_questions_editor']['items_state']);
      if ($raw !== '' && $raw !== '[]') {
        try {
          $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
          if (!is_array($decoded)) {
            $form_state->setErrorByName('mel][attendee_questions_editor][items_state', $this->t('Attendee questions could not be read. Reset the list or reload the page.'));
          }
          elseif (count($decoded) > self::ATTENDEE_QUESTION_LIMIT) {
            $form_state->setErrorByName('mel][attendee_questions_editor][items_state', $this->t('Add at most 5 attendee questions. More questions may reduce bookings.'));
          }
        }
        catch (\JsonException) {
          $form_state->setErrorByName('mel][attendee_questions_editor][items_state', $this->t('Attendee questions could not be read. Reset the list or reload the page.'));
        }
      }
    }

    if ($event instanceof NodeInterface && in_array($event_type, ['paid', 'both'], TRUE)) {
      $ticket_rows = $this->getEmbeddedTicketManagerTicketRows($form_state);
      $active_count = 0;
      $best_value_count = 0;
      $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      foreach ($ticket_rows as $row_key => $row) {
        if (!is_array($row) || !empty($row['more']['delete'])) {
          continue;
        }
        if (!$this->embeddedTicketRowHasInput($row)) {
          continue;
        }
        $active_count++;
        if (!empty($row['best_value'])) {
          $best_value_count++;
        }
        $prefix = 'mel][tickets_section][tickets][tickets][' . $row_key;
        $vid = $this->ticketTierLifecycle->managerSubmittedVariationId($row_key, $row);
        $loaded = $vid > 0 ? $variation_storage->load($vid) : NULL;
        $variation = $loaded instanceof ProductVariationInterface ? $loaded : NULL;
        $this->validateEmbeddedTicketManagerRow($form_state, $row, $prefix, $variation);
      }
      if ($active_count > 1 && $best_value_count > 1) {
        $form_state->setErrorByName('mel][tickets_section][tickets][tickets', $this->t('Only one ticket can be marked as best value.'));
      }
      if ($event->id() !== NULL && $active_count === 0 && !$this->eventHasPaidTicketTypesOnField($event)) {
        $form_state->setErrorByName('mel][tickets_section][tickets][tickets', $this->t('Paid events need at least one ticket with a name and price. Add a ticket row below or use the Advanced ticket manager.'));
      }

      $needs_active_paid = FALSE;
      $sellable_paid = 0;
      foreach ($ticket_rows as $row_key => $row) {
        if (!is_array($row) || !empty($row['more']['delete'])) {
          continue;
        }
        if (!$this->embeddedTicketRowHasInput($row)) {
          continue;
        }
        $price = trim((string) ($row['price'] ?? ''));
        if ($price !== '' && is_numeric($price) && (float) $price > 0) {
          $needs_active_paid = TRUE;
        }
        $vid = $this->ticketTierLifecycle->managerSubmittedVariationId($row_key, $row);
        $loaded = $vid > 0 ? $variation_storage->load($vid) : NULL;
        $variation = $loaded instanceof ProductVariationInterface ? $loaded : NULL;
        if ($this->ticketTierLifecycle->managerPaidRowIsSellable($row, $variation)) {
          $sellable_paid++;
        }
      }
      if ($needs_active_paid && $sellable_paid === 0) {
        $form_state->setErrorByName(
          'mel][tickets_section][tickets][tickets',
          $this->t('Turn on at least one active paid ticket before this event can sell tickets.'),
        );
      }
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

    $wasPublished = $existing instanceof NodeInterface && $existing->isPublished();
    $payload = $this->melPayloadService->buildFromFormState($form_state, $this->entityTypeManager);
    $result = $this->saveService->save($payload, $existing, $this->currentUser, FALSE);
    if ($result['errors'] !== []) {
      foreach ($result['errors'] as $msg) {
        $this->messenger()->addError($msg);
      }
      return;
    }
    $node = $result['node'];
    if ($node instanceof NodeInterface) {
      try {
        $this->getMelPlatformSupportWizardForm()->apply($node, $form_state);
        if ($node->hasField('field_mel_sup_mode')) {
          EventNodeRevisionSave::prepare($node, 'Event Studio: MEL support.');
          $node->save();
        }
      }
      catch (\Throwable $e) {
        $this->logger->error(
          'Event Studio: persist MEL support fields failed post-save (@nid): @message',
          [
            '@nid' => $node->id() ? (string) $node->id() : 'new',
            '@message' => $e->getMessage(),
          ]
        );
        $this->messenger()->addWarning($this->t('Event saved, but contribution preferences could not be stored. Try again shortly.'));
      }
      $wentLiveTransition = $node->isPublished() && !$wasPublished;
      $form_state->set('studio_node', $node);
      if ($wentLiveTransition) {
        $this->messenger()->addStatus($this->t('Your event is live'));
      }
      else {
        $this->messenger()->addStatus($this->t('Event saved.'));
      }

      $ticket_rows = $this->getEmbeddedTicketManagerTicketRows($form_state);
      $saved_event_type = $node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()
        ? (string) $node->get('field_event_type')->value
        : '';
      if ($ticket_rows !== [] && in_array($saved_event_type, ['paid', 'both'], TRUE)) {
        $persist = $this->ticketTierLifecycle->persistTicketManagerRows($node, $this->currentUser(), $ticket_rows);
        if (!$persist['ok']) {
          foreach ($persist['messages'] as $msg) {
            $this->messenger()->addError($msg);
          }
        }
        else {
          $this->logger->notice('Event Studio: ticket save/sync completed for nid @nid (persistTicketManagerRows).', [
            '@nid' => (string) $node->id(),
          ]);
          $this->messenger()->addStatus($this->eventTicketReconciliation->formatPostTicketPersistAuditSummary($node));
        }
      }

      try {
        $redirect_options = [];
        if ($wentLiveTransition) {
          $redirect_options['query']['mel_celebrate'] = '1';
        }
        $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event_studio.edit', ['node' => $node->id()], $redirect_options));
      }
      catch (\Throwable) {
        $canonical_options = [];
        if ($wentLiveTransition) {
          $canonical_options['query']['mel_celebrate'] = '1';
        }
        $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()], $canonical_options));
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
   * Encodes existing attendee question paragraphs for the Event Studio JS editor.
   */
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

  /**
   * @return array<string, string>
   *   Options keyed by vendor_question id.
   */
  private function buildVendorQuestionLibraryOptions(): array {
    if (!$this->entityTypeManager->hasDefinition('vendor_question')) {
      return [];
    }
    if (!$this->currentUser->hasPermission('view vendor question library')) {
      return [];
    }
    $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')->loadByProperties([
      'uid' => $this->currentUser->id(),
    ]);
    $vendor = reset($vendors);
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return [];
    }
    $store = $vendor->get('field_vendor_store')->entity;
    if ($store === NULL) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('vendor_question')->getQuery()
      ->condition('field_store', $store->id())
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('label', 'ASC')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $out = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      $q = $this->entityTypeManager->getStorage('vendor_question')->load($id);
      if ($q instanceof VendorQuestionInterface) {
        $out[(string) $id] = $q->getLabel();
      }
    }
    return $out;
  }

  /**
   * @return list<array{id: int, label: string, type: string, required: bool}>
   */
  private function buildVendorQuestionLibraryPayload(): array {
    if (!$this->entityTypeManager->hasDefinition('vendor_question')) {
      return [];
    }
    if (!$this->currentUser->hasPermission('view vendor question library')) {
      return [];
    }
    $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')->loadByProperties([
      'uid' => $this->currentUser->id(),
    ]);
    $vendor = reset($vendors);
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return [];
    }
    $store = $vendor->get('field_vendor_store')->entity;
    if ($store === NULL) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('vendor_question')->getQuery()
      ->condition('field_store', $store->id())
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('label', 'ASC')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $rows = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      $q = $this->entityTypeManager->getStorage('vendor_question')->load($id);
      if ($q instanceof VendorQuestionInterface) {
        $rows[] = [
          'id' => $id,
          'label' => $q->getLabel(),
          'type' => $q->getQuestionType(),
          'required' => $q->isRequired(),
        ];
      }
    }
    return $rows;
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
      default => (string) $this->t('RSVP (free)'),
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
   * Event book URL for live preview CTA when the route is accessible.
   */
  private function buildMelEventStudioBookUrl(NodeInterface $event, string $event_type): string {
    if ($event->isNew() || $event->id() === NULL || (int) $event->id() <= 0 || $event_type === 'external') {
      return '';
    }
    try {
      $url = Url::fromRoute('myeventlane_commerce.event_book', ['node' => (int) $event->id()]);
      if ($url->access()) {
        return $url->toString();
      }
    }
    catch (\Throwable) {
    }
    return '';
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

  /**
   * @return array<string, mixed>
   */
  private function getEmbeddedTicketManagerTicketRows(FormStateInterface $form_state): array {
    $mel = $form_state->getValue('mel');
    if (!is_array($mel)) {
      return [];
    }
    $section = $mel['tickets_section'] ?? [];
    if (!is_array($section)) {
      return [];
    }
    $embed = $section['tickets'] ?? [];
    if (!is_array($embed)) {
      return [];
    }
    $tickets = $embed['tickets'] ?? [];
    return is_array($tickets) ? $tickets : [];
  }

  /**
   * @param array<string, mixed> $values
   */
  private function embeddedTicketRowHasInput(array $values): bool {
    $price = trim((string) ($values['price'] ?? ''));
    return trim((string) ($values['title'] ?? '')) !== ''
      || ($price !== '' && is_numeric($price) && (float) $price > 0)
      || !empty($values['best_value']);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function validateEmbeddedTicketManagerRow(FormStateInterface $form_state, array $values, string $prefix, ?ProductVariationInterface $variation = NULL): void {
    if ($this->ticketTierLifecycle->resolveManagerRowTitle($values, $variation) === '') {
      $form_state->setErrorByName($prefix . '][title', $this->t('Ticket name is required.'));
    }

    $price = trim((string) ($values['price'] ?? ''));
    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
      $form_state->setErrorByName($prefix . '][price', $this->t('Ticket price must be zero or greater.'));
    }

    $more = isset($values['more']) && is_array($values['more']) ? $values['more'] : [];
    $capacity = trim((string) ($more['capacity'] ?? ''));
    if ($capacity !== '' && (!ctype_digit($capacity) || (int) $capacity < 0)) {
      $form_state->setErrorByName($prefix . '][more][capacity', $this->t('Ticket capacity must be a whole number greater than or equal to zero.'));
    }

    $limit_per_order = trim((string) ($more['limit_per_order'] ?? ''));
    if ($limit_per_order !== '' && (!ctype_digit($limit_per_order) || (int) $limit_per_order < 0)) {
      $form_state->setErrorByName($prefix . '][more][limit_per_order', $this->t('Limit per order must be a whole number greater than or equal to zero.'));
    }
  }

  private function eventHasPaidTicketTypesOnField(NodeInterface $event): bool {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return FALSE;
    }
    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if ($entity instanceof TicketTypeInterface && !$entity->isArchived() && $entity->getTicketKind() === 'paid') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
