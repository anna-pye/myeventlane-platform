<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_vendor\Service\EventTicketReconciliationService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Canonical vendor ticket manager backed by Commerce product variations.
 *
 * Injected services must be protected (not private readonly) so FormBase
 * serialization can restore them from the container on cached form rebuilds.
 */
final class EventTicketManagerForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected LoggerChannelInterface $logger;

  protected TicketTierLifecycleService $ticketTierLifecycle;

  protected EventTicketReconciliationService $eventTicketReconciliation;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelInterface $logger,
    TicketTierLifecycleService $ticket_tier_lifecycle,
    EventTicketReconciliationService $event_ticket_reconciliation,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->ticketTierLifecycle = $ticket_tier_lifecycle;
    $this->eventTicketReconciliation = $event_ticket_reconciliation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.channel.myeventlane_vendor'),
      $container->get('myeventlane_event.ticket_tier_lifecycle'),
      $container->get('myeventlane_vendor.event_ticket_reconciliation'),
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
   *
   * DependencySerializationTrait::__wakeup() repopulates properties listed in
   * _serviceIds; when it returns early (empty _serviceIds) or a property was
   * not tracked, typed constructor-injected properties can stay uninitialized.
   */
  private function ensureInjectedServices(): void {
    if (isset($this->entityTypeManager, $this->logger, $this->ticketTierLifecycle, $this->eventTicketReconciliation)) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $container->get('entity_type.manager');
    }
    if (!isset($this->logger)) {
      $this->logger = $container->get('logger.channel.myeventlane_vendor');
    }
    if (!isset($this->ticketTierLifecycle)) {
      $this->ticketTierLifecycle = $container->get('myeventlane_event.ticket_tier_lifecycle');
    }
    if (!isset($this->eventTicketReconciliation)) {
      $this->eventTicketReconciliation = $container->get('myeventlane_vendor.event_ticket_reconciliation');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_ticket_manager_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $this->ensureInjectedServices();
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->error('Ticket manager opened without a valid event route parameter.');
      $form['error'] = [
        '#markup' => '<p>' . $this->t('The event could not be loaded.') . '</p>',
      ];
      return $form;
    }

    $form_state->set('event', $event);
    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'mel-ticket-manager-form';
    $form['#attributes']['data-mel-ticket-manager-form'] = '1';
    $form['#prefix'] = '<div id="mel-ticket-manager-wrapper" class="mel-ticket-manager-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'myeventlane_vendor/event_ticket_manager';

    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-manager-header']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Tickets'),
      ],
    ];

    $workspace_url = Url::fromRoute('myeventlane_vendor.console.event_workspace', ['event' => $event->id()]);
    $studio_url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()]);
    $show_workspace = $workspace_url->access();
    $show_studio = $studio_url->access();
    if ($show_workspace || $show_studio) {
      $form['back_nav'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-manager-back-nav']],
      ];
      if ($show_workspace) {
        $form['back_nav']['workspace'] = [
          '#type' => 'link',
          '#title' => $this->t('Event workspace'),
          '#url' => $workspace_url,
          '#attributes' => ['class' => ['mel-ticket-manager-back-nav__link']],
        ];
      }
      if ($show_studio) {
        $form['back_nav']['studio'] = [
          '#type' => 'link',
          '#title' => $this->t('Event Studio'),
          '#url' => $studio_url,
          '#attributes' => ['class' => ['mel-ticket-manager-back-nav__link']],
        ];
      }
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    $supports_auto_product = in_array($event_type, ['paid', 'both'], TRUE);

    $product = $this->loadTicketProduct($event);
    if (!$product instanceof ProductInterface && !$supports_auto_product) {
      $form['missing_product'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'message' => [
          '#markup' => '<p>' . $this->t('This event does not have a linked Commerce ticket product. Link a ticket product before managing tickets.') . '</p>',
        ],
      ];
      return $form;
    }

    if (!$product instanceof ProductInterface) {
      $form['missing_product_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'message' => [
          '#markup' => '<p>' . $this->t('Add a ticket row below. The ticket product will be created when you save and sync tickets.') . '</p>',
        ],
      ];
    }

    $ticket_rows = $product instanceof ProductInterface ? $this->buildTicketRows($product, $form_state) : [];
    $new_ticket_rows = $form_state->get('new_ticket_rows');
    if (!is_array($new_ticket_rows)) {
      $new_ticket_rows = [];
    }

    $form['quick_add'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-manager-quick-add']],
      'free' => [
        '#type' => 'submit',
        '#value' => $this->t('Quick add free ticket'),
        '#name' => 'ticket_add_free',
        '#submit' => ['::addTicketRowSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'mel-ticket-manager-wrapper',
        ],
        '#attributes' => [
          'class' => ['mel-ticket-manager-add', 'button', 'button--secondary'],
          'data-mel-ticket-kind' => 'free',
        ],
      ],
      'paid' => [
        '#type' => 'submit',
        '#value' => $this->t('Quick add paid ticket'),
        '#name' => 'ticket_add_paid',
        '#submit' => ['::addTicketRowSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'mel-ticket-manager-wrapper',
        ],
        '#attributes' => [
          'class' => ['mel-ticket-manager-add', 'button', 'button--secondary'],
          'data-mel-ticket-kind' => 'paid',
        ],
      ],
    ];

    $form['sync_intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-manager-sync-intro']],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Add ticket rows with the quick actions, mark tickets Active when they are ready to sell, then click Save and sync tickets to update the public event page and checkout mapping.'),
        '#attributes' => ['class' => ['mel-ticket-manager-sync-intro__text']],
      ],
    ];

    $feedback = $form_state->get('ticket_feedback');
    $form['feedback'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-manager-feedback'],
        'aria-live' => 'polite',
        'data-mel-ticket-feedback' => '1',
      ],
      '#access' => is_string($feedback) && $feedback !== '',
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => is_string($feedback) ? $feedback : '',
      ],
    ];

    if ($ticket_rows === [] && $new_ticket_rows === []) {
      $form['empty_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-manager-warning']],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('No tickets yet. Add a free or paid ticket before publishing.'),
        ],
      ];
    }

    $form['tickets'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-ticket-manager-list']],
    ];

    foreach ($ticket_rows as $row_key => $variation) {
      $form['tickets'][$row_key] = $this->buildTicketRow($variation, $row_key, $form_state);
    }

    foreach ($new_ticket_rows as $row_key => $defaults) {
      if (!is_array($defaults)) {
        continue;
      }
      $form['tickets'][$row_key] = $this->buildTicketRow(NULL, (string) $row_key, $form_state, $defaults);
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-ticket-manager-sync-actions']],
      'submit' => [
        '#type' => 'submit',
        '#name' => 'ticket_save_sync',
        '#value' => $this->t('Save and sync tickets'),
        '#button_type' => 'primary',
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'mel-ticket-manager-wrapper',
        ],
        '#attributes' => [
          'class' => [
            'mel-ticket-manager-save',
            'mel-ticket-manager-save-sync',
            'button',
            'button--primary',
          ],
        ],
      ],
    ];

    $form['tools'] = [
      '#type' => 'details',
      '#title' => $this->t('Groups, access codes, widgets, and scanner'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mel-ticket-manager-tools']],
      'links' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->buildToolLink('myeventlane_tickets.event_tickets_groups', $event, $this->t('Ticket groups')),
          $this->buildToolLink('myeventlane_tickets.event_tickets_access_codes', $event, $this->t('Access codes')),
          $this->buildToolLink('myeventlane_tickets.event_tickets_settings', $event, $this->t('Ticket settings')),
          $this->buildToolLink('myeventlane_tickets.event_tickets_widgets', $event, $this->t('Embedded widgets')),
          $this->buildToolLink('myeventlane_tickets.ticket_scan', $event, $this->t('Door scanner')),
          $this->buildToolLink('myeventlane_tickets.ticket_checkin_analytics', $event, $this->t('Check-in analytics')),
        ],
      ],
    ];

    return $form;
  }

  /**
   * AJAX callback for ticket row add/save operations.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Adds one unsaved ticket row to the manager UI.
   */
  public function addTicketRowSubmit(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $kind = is_array($trigger) && (($trigger['#attributes']['data-mel-ticket-kind'] ?? '') === 'paid') ? 'paid' : 'free';
    $new_ticket_rows = $form_state->get('new_ticket_rows');
    if (!is_array($new_ticket_rows)) {
      $new_ticket_rows = [];
    }
    $row_key = 'new_' . count($new_ticket_rows) . '_' . time();
    $new_ticket_rows[$row_key] = [
      'title' => $kind === 'paid' ? (string) $this->t('General Admission') : (string) $this->t('Free RSVP'),
      'price' => $kind === 'paid' ? '' : '0.00',
      'active' => TRUE,
      'autofocus' => TRUE,
    ];
    $form_state->set('new_ticket_rows', $new_ticket_rows);
    $form_state->set('ticket_feedback', '');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Builds Commerce variation rows indexed by variation ID.
   *
   * @return array<int, \Drupal\commerce_product\Entity\ProductVariationInterface>
   *   Variations keyed by ID.
   */
  private function buildTicketRows(ProductInterface $product, FormStateInterface $form_state): array {
    $rows = [];
    foreach ($product->getVariations() as $variation) {
      if ($variation instanceof ProductVariationInterface && $variation->id() !== NULL) {
        $rows[(int) $variation->id()] = $variation;
      }
    }
    return $rows;
  }

  /**
   * Builds one compact Commerce variation row.
   *
   * @param array<string, mixed> $defaults
   *   Defaults for a newly-added, unsaved row.
   */
  private function buildTicketRow(?ProductVariationInterface $variation, int|string $row_key, FormStateInterface $form_state, array $defaults = []): array {
    $price = $variation?->getPrice();
    $submitted = $form_state->getValue(['tickets', $row_key], []);
    if (!is_array($submitted)) {
      $submitted = [];
    }

    $variation_id = $variation instanceof ProductVariationInterface ? (int) $variation->id() : 0;
    $autofocus = !empty($defaults['autofocus']);
    $active_default = TRUE;
    if (array_key_exists('active', $submitted)) {
      $active_default = !empty($submitted['active']);
    }
    elseif (array_key_exists('active', $defaults)) {
      $active_default = (bool) $defaults['active'];
    }
    elseif ($variation instanceof ProductVariationInterface && $variation->id() !== NULL) {
      $active_default = $variation->isPublished();
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-manager-row'],
        'data-mel-ticket-row' => '1',
      ] + ($autofocus ? ['data-mel-ticket-autofocus' => '1'] : []),
      'variation_id' => [
        '#type' => 'hidden',
        '#name' => 'variation_id',
        '#value' => (string) $variation_id,
      ],
      'currency' => [
        '#type' => 'hidden',
        '#value' => $price ? $price->getCurrencyCode() : 'AUD',
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-manager-row__summary']],
      ],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => $submitted['title'] ?? $defaults['title'] ?? $variation?->getTitle() ?? '',
        '#required' => TRUE,
        '#maxlength' => 255,
        '#attributes' => [
          'class' => ['mel-ticket-manager-row__name'],
          'data-mel-ticket-first-input' => $autofocus ? '1' : '0',
        ],
      ],
      'price' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => $submitted['price'] ?? $defaults['price'] ?? ($price ? $price->getNumber() : '0.00'),
        '#attributes' => ['class' => ['mel-ticket-manager-row__price']],
      ],
      'active' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Active'),
        '#description' => $this->t('Active tickets can appear on the event page when priced and synced.'),
        '#default_value' => $active_default,
        '#attributes' => ['class' => ['mel-ticket-manager-row__active']],
        '#wrapper_attributes' => ['class' => ['mel-ticket-manager-active-field']],
      ],
      'best_value' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Best value'),
        '#default_value' => (bool) ($submitted['best_value'] ?? $this->getBooleanFieldValue($variation, 'field_best_value')),
        '#access' => $variation === NULL || $variation->hasField('field_best_value'),
      ],
      'more' => [
        '#type' => 'details',
        '#title' => $this->t('More options'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['mel-ticket-manager-row__more']],
        'capacity' => [
          '#type' => 'number',
          '#title' => $this->t('Capacity'),
          '#min' => 0,
          '#step' => 1,
          '#default_value' => $submitted['more']['capacity'] ?? $this->getIntegerFieldValue($variation, 'field_capacity'),
          '#description' => $this->t('Leave empty for unlimited.'),
          '#access' => $variation === NULL || $variation->hasField('field_capacity'),
        ],
        'limit_per_order' => [
          '#type' => 'number',
          '#title' => $this->t('Limit per order'),
          '#min' => 0,
          '#step' => 1,
          '#default_value' => $submitted['more']['limit_per_order'] ?? $this->getIntegerFieldValue($variation, 'field_limit_per_order'),
          '#description' => $this->t('Leave empty for no ticket-level limit.'),
          '#access' => $variation === NULL || $variation->hasField('field_limit_per_order'),
        ],
        'show_remaining' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Show remaining tickets'),
          '#default_value' => (bool) ($submitted['more']['show_remaining'] ?? $this->getBooleanFieldValue($variation, 'field_show_remaining')),
          '#access' => $variation === NULL || $variation->hasField('field_show_remaining'),
        ],
        'collect_questions' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Collect attendee info for this ticket'),
          '#default_value' => (bool) ($submitted['more']['collect_questions'] ?? $this->getBooleanFieldValue($variation, 'field_collect_questions')),
          '#access' => $variation === NULL || $variation->hasField('field_collect_questions'),
        ],
        'ticket_start' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sales start'),
          '#default_value' => $submitted['more']['ticket_start'] ?? $this->getDateFieldValue($variation, 'field_ticket_start'),
          '#access' => $variation === NULL || $variation->hasField('field_ticket_start'),
        ],
        'ticket_end' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sales end'),
          '#default_value' => $submitted['more']['ticket_end'] ?? $this->getDateFieldValue($variation, 'field_ticket_end'),
          '#access' => $variation === NULL || $variation->hasField('field_ticket_end'),
        ],
        'delete' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Delete this ticket on save'),
          '#default_value' => !empty($submitted['more']['delete']),
          '#attributes' => ['data-mel-ticket-delete' => '1'],
        ],
      ],
    ];
  }

  /**
   * Builds one tool link render item.
   */
  private function buildToolLink(string $route_name, NodeInterface $event, mixed $title): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute($route_name, ['event' => $event->id()]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->getEventFromFormState($form_state);
    if (!$event instanceof NodeInterface) {
      $this->logger->error('Ticket manager validation failed: event context missing.');
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    $product = $this->loadTicketProduct($event);
    if (!$product instanceof ProductInterface && !in_array($event_type, ['paid', 'both'], TRUE)) {
      $form_state->setErrorByName('', $this->t('Ticket product missing. Finish ticket setup in Event Studio or link a ticket product before saving tickets.'));
      return;
    }

    $rows = $form_state->getValue('tickets') ?? [];
    $best_value_count = 0;
    $active_count = 0;
    if (is_array($rows)) {
      foreach ($rows as $row_key => $row) {
        if (!is_array($row) || !empty($row['more']['delete'])) {
          continue;
        }
        if (!$this->ticketRowHasInput($row)) {
          continue;
        }
        $active_count++;
        if (!empty($row['best_value'])) {
          $best_value_count++;
        }
        $this->validateTicketValues($form_state, $row, 'tickets][' . $row_key);
      }
    }

    if ($active_count > 1 && $best_value_count > 1) {
      $form_state->setErrorByName('tickets', $this->t('Only one ticket can be marked as best value.'));
    }

    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      $needs_active_paid = FALSE;
      $sellable_paid = 0;
      $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      foreach ($rows as $row_key => $row) {
        if (!is_array($row) || !empty($row['more']['delete'])) {
          continue;
        }
        if (!$this->ticketRowHasInput($row)) {
          continue;
        }
        $price = trim((string) ($row['price'] ?? ''));
        if ($price !== '' && is_numeric($price) && (float) $price > 0) {
          $needs_active_paid = TRUE;
        }
        $vid = $this->ticketTierLifecycle->managerSubmittedVariationId($row_key, $row);
        $loaded_variation = $vid > 0 ? $variation_storage->load($vid) : NULL;
        $variation = $loaded_variation instanceof ProductVariationInterface ? $loaded_variation : NULL;
        if ($this->ticketTierLifecycle->managerPaidRowIsSellable($row, $variation)) {
          $sellable_paid++;
        }
      }
      if ($needs_active_paid && $sellable_paid === 0) {
        $form_state->setErrorByName(
          'tickets',
          $this->t('Turn on at least one active paid ticket before this event can sell tickets.'),
        );
      }
    }
  }

  /**
   * Validates one submitted ticket row.
   *
   * @param array<string, mixed> $values
   *   Submitted ticket values.
   */
  private function validateTicketValues(FormStateInterface $form_state, array $values, string $prefix): void {
    if (trim((string) ($values['title'] ?? '')) === '') {
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

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $event = $this->getEventFromFormState($form_state);
    if (!$event instanceof NodeInterface) {
      $this->logger->error('Ticket manager submit failed: event context missing.');
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    $product = $this->loadTicketProduct($event);
    if (!$product instanceof ProductInterface && !in_array($event_type, ['paid', 'both'], TRUE)) {
      $this->messenger()->addError($this->t('Ticket product missing. Finish ticket setup before saving tickets.'));
      return;
    }

    $rows = $form_state->getValue('tickets') ?? [];
    if (!is_array($rows)) {
      $rows = [];
    }

    $result = $this->ticketTierLifecycle->persistTicketManagerRows($event, $this->currentUser(), $rows);
    if (!$result['ok']) {
      foreach ($result['messages'] as $msg) {
        $this->messenger()->addError($msg);
      }
      $form_state->setErrorByName('tickets', $this->t('Tickets could not be saved. Please check the details and try again.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $this->logger->notice('Ticket save/sync completed for event @nid (canonical persistTicketManagerRows).', [
      '@nid' => (string) $event->id(),
    ]);

    $audit_summary = $this->eventTicketReconciliation->formatPostTicketPersistAuditSummary($event);
    $this->messenger()->addStatus($audit_summary);

    $form_state->set('new_ticket_rows', []);
    $form_state->set('ticket_feedback', $audit_summary);
    $form_state->setRebuild(TRUE);
  }

  private function ticketRowHasInput(array $values): bool {
    $price = trim((string) ($values['price'] ?? ''));
    return trim((string) ($values['title'] ?? '')) !== ''
      || ($price !== '' && is_numeric($price) && (float) $price > 0)
      || !empty($values['best_value']);
  }

  /**
   * Resolves a submitted variation ID without trusting form state as storage.
   *
   * @param int|string $row_key
   *   The submitted ticket row key.
   * @param array<string, mixed> $row
   *   Submitted ticket row values.
   */
  private function getSubmittedVariationId(int|string $row_key, array $row): int {
    $submitted_id = (int) ($row['variation_id'] ?? 0);
    if ($submitted_id > 0) {
      return $submitted_id;
    }
    return is_numeric($row_key) ? (int) $row_key : 0;
  }

  private function getIntegerFieldValue(?ProductVariationInterface $variation, string $field_name): string {
    if (!$variation instanceof ProductVariationInterface || !$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
      return '';
    }
    return (string) $variation->get($field_name)->value;
  }

  private function getBooleanFieldValue(?ProductVariationInterface $variation, string $field_name): bool {
    if (!$variation instanceof ProductVariationInterface || !$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
      return FALSE;
    }
    return (bool) $variation->get($field_name)->value;
  }

  private function getDateFieldValue(?ProductVariationInterface $variation, string $field_name): ?DrupalDateTime {
    if (!$variation instanceof ProductVariationInterface || !$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = (string) $variation->get($field_name)->value;
    return $value !== '' ? new DrupalDateTime($value) : NULL;
  }

  private function normalizeSubmittedDate(mixed $value): ?string {
    if ($value instanceof DrupalDateTime) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if (is_array($value) && isset($value['date'], $value['time'])) {
      $raw = trim((string) $value['date'] . ' ' . (string) $value['time']);
      if ($raw !== '') {
        return (new DrupalDateTime($raw))->format('Y-m-d\TH:i:s');
      }
    }
    $raw = trim((string) $value);
    return $raw === '' ? NULL : (new DrupalDateTime($raw))->format('Y-m-d\TH:i:s');
  }

  private function loadTicketProduct(NodeInterface $event): ?ProductInterface {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }

    $product = $event->get('field_product_target')->entity;
    return $product instanceof ProductInterface ? $product : NULL;
  }

  private function getEventFromFormState(FormStateInterface $form_state): ?NodeInterface {
    $event = $form_state->get('event');
    return $event instanceof NodeInterface ? $event : NULL;
  }

}
