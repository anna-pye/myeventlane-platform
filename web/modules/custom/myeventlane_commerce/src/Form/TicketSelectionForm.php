<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_commerce\Service\CustomerTicketTierDisplayBuilder;
use Drupal\myeventlane_commerce\Service\CartTicketHoldManager;
use Drupal\myeventlane_commerce\Service\CartTicketTierHoldStore;
use Drupal\myeventlane_commerce\Service\TicketAccessCodeService;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_commerce\Service\TicketBundlePriceAllocator;
use Drupal\myeventlane_commerce\Service\TicketBookingSessionService;
use Drupal\myeventlane_commerce\Service\TicketStatusEvaluator;
use Drupal\myeventlane_commerce\Service\TicketTierWaitlistService;
use Drupal\myeventlane_commerce\Service\TicketVariationSoldService;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for selecting tickets from multiple variations.
 *
 * INVARIANT:
 * Ticket capacity MUST be enforced here (validateForm + submitForm) and in
 * Commerce subscribers. Do not rely on node edit form validation or UI state.
 */
final class TicketSelectionForm extends FormBase {

  /**
   * Injected services use promoted protected properties (not private readonly).
   *
   * FormBase serializes form objects via DependencySerializationTrait; private
   * readonly properties are not reliably rehydrated on cache rebuild / wakeup,
   * which breaks submit handlers such as applyAccessCode().
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CartProviderInterface $cartProvider,
    protected CartManagerInterface $cartManager,
    protected CurrencyFormatter $currencyFormatter,
    protected TicketAvailabilityService $ticketAvailability,
    protected TicketBundlePriceAllocator $ticketBundlePriceAllocator,
    protected TicketAccessCodeService $accessCodeService,
    protected TicketBookingSessionService $bookingSession,
    protected TicketTierWaitlistService $tierWaitlist,
    protected TicketVariationSoldService $variationSold,
    protected TimeInterface $time,
    protected CapacityOrderInspector $orderInspector,
    protected TicketTypeManager $ticketTypeManager,
    protected ?EventCapacityServiceInterface $capacityService = NULL,
    protected ?CustomerTicketTierDisplayBuilder $customerTicketTierDisplay = NULL,
    protected ?CartTicketHoldManager $cartTicketHold = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_price.currency_formatter'),
      $container->get('myeventlane_commerce.ticket_availability'),
      $container->get('myeventlane_commerce.ticket_bundle_price_allocator'),
      $container->get('myeventlane_commerce.ticket_access_code'),
      $container->get('myeventlane_commerce.ticket_booking_session'),
      $container->get('myeventlane_commerce.ticket_tier_waitlist'),
      $container->get('myeventlane_commerce.ticket_variation_sold'),
      $container->get('datetime.time'),
      $container->get('myeventlane_capacity.order_inspector'),
      $container->get('myeventlane_event.ticket_type_manager'),
      $container->has('myeventlane_capacity.service')
        ? $container->get('myeventlane_capacity.service')
        : NULL,
      $container->has('myeventlane_commerce.customer_ticket_tier_display')
        ? $container->get('myeventlane_commerce.customer_ticket_tier_display')
        : NULL,
      $container->has('myeventlane_commerce.cart_ticket_hold')
        ? $container->get('myeventlane_commerce.cart_ticket_hold')
        : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_ticket_selection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?ProductInterface $product = NULL): array {
    if (!$node || !$product) {
      return $form;
    }

    $form['#node'] = $node;
    $form['#product'] = $product;

    if ($this->capacityService && $this->capacityService->isSoldOut($node)) {
      $form['sold_out'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-alert', 'mel-alert--info']],
        'message' => [
          '#markup' => '<p>' . $this->t('This event is sold out.') . '</p>',
        ],
      ];
      return $form;
    }

    $eventId = (int) $node->id();
    $waitlist_offer_summary = $this->tierWaitlist->getActiveOfferSummaryForEvent(
      $eventId,
      $this->bookingSession->getWaitlistClaimEntryId($eventId),
    );

    $form['booking_access'] = [
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#title' => $this->t('Access code'),
      '#description' => $this->t('If you have an organiser code, enter it here to reveal hidden or invite-only tickets for this browser session.'),
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-ticket-booking-access']],
      '#weight' => 25,
    ];
    $form['booking_access']['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Code'),
      '#maxlength' => 255,
    ];
    $form['booking_access']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply code'),
      '#submit' => ['::applyAccessCode'],
      '#limit_validation_errors' => [['booking_access', 'code']],
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
    ];

    if (is_array($waitlist_offer_summary)) {
      $seconds_left = (int) ($waitlist_offer_summary['seconds_remaining'] ?? 0);
      $form['waitlist_offer_banner'] = [
        '#type' => 'container',
        '#weight' => 5,
        '#attributes' => ['class' => ['mel-offer-countdown-anchor']],
        'panel' => [
          '#theme' => 'mel_waitlist_offer_countdown',
          '#expires_timestamp' => (int) ($waitlist_offer_summary['expires_timestamp'] ?? 0),
          '#seconds_remaining' => $seconds_left,
          '#timer_initial' => $this->formatOfferCountdownDisplay($seconds_left),
          '#tier_title' => (string) ($waitlist_offer_summary['tier_title'] ?? ''),
          '#reserved_quantity' => (int) ($waitlist_offer_summary['reserved_quantity'] ?? 0),
          '#status_label' => (string) ($waitlist_offer_summary['status_label'] ?? ''),
          '#helper_message' => (string) ($waitlist_offer_summary['helper_message'] ?? ''),
        ],
        '#attached' => [
          'library' => [
            'myeventlane_commerce/mel_waitlist_offer_countdown',
          ],
        ],
      ];
    }

    $published_variations = $this->ticketAvailability->filterPurchasableVariations($node, $product);
    $default_tier = $this->ticketTypeManager->getDefaultTicket($node);
    $default_variation_id = $this->resolveDefaultVariationId($default_tier);
    $best_value_variation_id = $this->resolveBestValueVariationId($node);
    if ($default_variation_id !== NULL) {
      $published_variations = $this->sortVariationsDefaultFirst($published_variations, $default_variation_id);
    }

    $waitlist_tiers = $this->buildWaitlistTierOptions($node, $product);

    if ($published_variations === [] && $waitlist_tiers === []) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No tickets available for this event.') . '</p>',
      ];
      return $form;
    }

    if ($published_variations !== []) {
      $form['tickets'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-selection']],
        '#tree' => TRUE,
        '#weight' => 10,
      ];

      $form['tickets']['event_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $node->label(),
        '#attributes' => ['class' => ['mel-event-title']],
      ];

      if ($this->capacityService) {
        $remaining = $this->capacityService->getRemaining($node);
        if ($remaining !== NULL && $remaining <= 10) {
          $form['tickets']['remaining'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Only @count ticket(s) remaining.', ['@count' => $remaining]),
            '#attributes' => ['class' => ['mel-ticket-remaining', 'mel-text--muted']],
            '#weight' => -5,
          ];
        }
      }

      $ticket_type_labels = [];
      $ticket_types_by_variation_id = [];
      $ticket_variation_ids = [];
      if ($node->hasField('field_ticket_types') && !$node->get('field_ticket_types')->isEmpty()) {
        foreach ($node->get('field_ticket_types')->referencedEntities() as $ticket) {
          if ($ticket instanceof TicketTypeInterface && $ticket->isArchived()) {
            continue;
          }
          if ($ticket instanceof TicketType && !$ticket->get('commerce_variation')->isEmpty()) {
            $variation_entity = $ticket->get('commerce_variation')->entity;
            if ($variation_entity) {
              $ticket_type_labels[$variation_entity->uuid()] = $ticket->label();
              $ticket_types_by_variation_id[(int) $variation_entity->id()] = $ticket;
              $ticket_variation_ids[(int) $ticket->id()] = (int) $variation_entity->id();
            }
          }
        }
      }

      $ticket_group_display = $this->loadTicketGroupDisplay(
        $node,
        $product,
        $ticket_variation_ids,
        array_map(static fn (ProductVariationInterface $variation): int => (int) $variation->id(), $published_variations),
      );
      $ticket_group_display['cacheability']->applyTo($form);
      if ($ticket_group_display['bundles'] !== []) {
        $form['bundles'] = $this->buildTicketBundleOptions($ticket_group_display['bundles']);
      }
      if ($ticket_group_display['variation_groups'] !== []) {
        $published_variations = $this->sortVariationsByTicketGroup(
          $published_variations,
          $ticket_group_display,
          $default_variation_id,
        );
      }

      $estimated_total = 0.0;
      $has_grouped_variations = $ticket_group_display['variation_groups'] !== [];
      $open_group_key = NULL;
      $previous_variation_id = NULL;
      foreach ($published_variations as $variation) {
        $variation_id = $variation->id();
        $variation_uuid = $variation->uuid();
        $price = $variation->getPrice();
        $price_formatted = $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';

        $ticket_label = $ticket_type_labels[$variation_uuid] ?? $variation->label();
        if (strpos($ticket_label, ' – ') !== FALSE) {
          $parts = explode(' – ', $ticket_label, 2);
          $ticket_label = $parts[1] ?? $ticket_label;
        }

        $tier = $ticket_types_by_variation_id[(int) $variation_id]
          ?? $this->ticketAvailability->resolveTierForVariation($node, $variation);
        $buyer_desc = '';
        if ($tier instanceof TicketTypeInterface && $tier->hasField('short_description') && !$tier->get('short_description')->isEmpty()) {
          $buyer_desc = trim((string) $tier->get('short_description')->value);
        }
        $qty_attrs = [
          'class' => ['mel-ticket-quantity'],
          'data-variation-id' => $variation_id,
          'aria-label' => (string) $this->t('Quantity for @label', ['@label' => $ticket_label]),
        ];
        $qty_el = [
          '#type' => 'number',
          '#title' => $this->t('Quantity'),
          '#title_display' => 'invisible',
          '#min' => 0,
          '#step' => 1,
          '#default_value' => 0,
          '#attributes' => $qty_attrs,
        ];
        $group_msg = '';
        if ($tier instanceof TicketTypeInterface) {
          $group_msg = $this->groupRuleMessage($tier);
          $rules = $this->quantityWidgetRules($tier);
          $qty_el['#min'] = $rules['min'];
          $qty_el['#step'] = $rules['step'];
          if ($ticket_group_display['bundles'] === []
            && $default_variation_id !== NULL
            && (int) $variation_id === $default_variation_id) {
            $qty_el['#default_value'] = $this->defaultSelectedQuantity($tier);
          }
        }
        if ($price instanceof Price) {
          $estimated_total += ((float) $price->getNumber()) * (int) $qty_el['#default_value'];
        }
        $row_classes = ['mel-ticket-row', 'mel-card', 'mel-ticket-book-card'];
        if ($default_variation_id !== NULL && (int) $variation_id === $default_variation_id) {
          $row_classes[] = 'mel-ticket-row--recommended';
        }
        if ($best_value_variation_id !== NULL && (int) $variation_id === $best_value_variation_id) {
          $row_classes[] = 'mel-ticket-row--best-value';
        }

        $label_cell = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-label-cell']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $ticket_label,
            '#attributes' => ['class' => ['mel-ticket-label']],
          ],
        ];
        if ($best_value_variation_id !== NULL && (int) $variation_id === $best_value_variation_id) {
          $label_cell['best_value'] = [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Best value'),
            '#attributes' => ['class' => ['mel-ticket-best-value-badge']],
            '#weight' => 1,
          ];
        }
        if ($buyer_desc !== '') {
          $label_cell['description'] = [
            '#markup' => '<div class="mel-ticket-description mel-text--muted">' . nl2br(Html::escape($buyer_desc), FALSE) . '</div>',
            '#weight' => 8,
          ];
        }

        $availability_text = $this->buyerFacingAvailabilityMessage($node, $tier, (int) $variation_id);
        $label_cell['availability'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $availability_text,
          '#attributes' => [
            'class' => [
              'mel-ticket-availability',
              'mel-ticket-card__availability',
              'mel-text--muted',
            ],
          ],
          '#weight' => 10,
        ];

        $group_id = $ticket_group_display['variation_groups'][(int) $variation_id] ?? NULL;
        $group_key = $group_id !== NULL ? 'group_' . $group_id : ($has_grouped_variations ? 'ungrouped' : NULL);
        if ($group_key !== NULL && $group_key !== $open_group_key) {
          if ($previous_variation_id !== NULL && $open_group_key !== NULL) {
            $form['tickets'][$previous_variation_id]['#suffix'] = '</section>';
          }
          $group = $group_id !== NULL
            ? $ticket_group_display['groups'][$group_id]
            : [
              'name' => (string) $this->t('Other tickets'),
              'description' => '',
            ];
          $form['tickets']['heading_' . $group_key] = $this->buildTicketGroupHeading($group_key, $group);
          $open_group_key = $group_key;
        }

        $form['tickets'][$variation_id] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => $row_classes,
            'data-variation-id' => $variation_id,
            'data-mel-ticket-recommended' => ($default_variation_id !== NULL && (int) $variation_id === $default_variation_id) ? '1' : '0',
            'data-mel-ticket-best-value' => ($best_value_variation_id !== NULL && (int) $variation_id === $best_value_variation_id) ? '1' : '0',
          ],
          'label_cell' => $label_cell,
          'price' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $price_formatted,
            '#attributes' => ['class' => ['mel-ticket-price']],
          ],
          'group_hint' => $group_msg !== '' ? [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $group_msg,
            '#attributes' => ['class' => ['mel-ticket-group-hint', 'mel-text--muted']],
          ] : ['#access' => FALSE],
          'quantity' => $qty_el,
        ];
        $previous_variation_id = (int) $variation_id;
      }

      if ($previous_variation_id !== NULL && $open_group_key !== NULL) {
        $form['tickets'][$previous_variation_id]['#suffix'] = '</section>';
      }

      $this->appendMelDonationField($form, $node, $estimated_total);

      $form['actions'] = [
        '#type' => 'actions',
        '#weight' => 20,
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Continue to checkout'),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--xl', 'mel-add-to-cart-button']],
        ],
      ];
    }

    if ($waitlist_tiers !== []) {
      $form['tier_waitlist'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-waitlist', 'mel-stack']],
        '#tree' => TRUE,
        '#weight' => 15,
      ];
      $form['tier_waitlist']['intro'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Sold out — join a waitlist'),
        '#attributes' => ['class' => ['mel-ticket-waitlist__title']],
      ];
      foreach ($waitlist_tiers as $tid => $label) {
        $form['tier_waitlist'][(string) $tid] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-waitlist__tier']],
        ];
        $form['tier_waitlist'][(string) $tid]['heading'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $label,
          '#attributes' => ['class' => ['mel-ticket-waitlist__tier-name']],
        ];
        $form['tier_waitlist'][(string) $tid]['mail'] = [
          '#type' => 'email',
          '#title' => $this->t('Email'),
          '#required' => FALSE,
        ];
        $form['tier_waitlist'][(string) $tid]['qty'] = [
          '#type' => 'number',
          '#title' => $this->t('Tickets wanted'),
          '#min' => 1,
          '#step' => 1,
          '#default_value' => 1,
        ];
        $form['tier_waitlist'][(string) $tid]['join'] = [
          '#type' => 'submit',
          '#value' => $this->t('Join waitlist'),
          '#name' => 'waitlist_join_' . $tid,
          '#submit' => ['::joinWaitlistSubmit'],
          '#limit_validation_errors' => [
            ['tier_waitlist', (string) $tid, 'mail'],
            ['tier_waitlist', (string) $tid, 'qty'],
          ],
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
        ];
      }
    }

    return $form;
  }

  /**
   * Applies an access code and stores unlocked tier IDs in the session.
   */
  public function applyAccessCode(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form['#node'];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form['#product'];
    $code = trim((string) $form_state->getValue(['booking_access', 'code']));
    try {
      $existingCodeId = $this->bookingSession->getAccessCodeId((int) $node->id());
      $result = $this->accessCodeService->resolveUnlockedTierIdsWithCodeId($node, $product, $code, $existingCodeId);
      $this->bookingSession->recordAccessGrant(
        (int) $node->id(),
        $result['access_code_id'],
        (int) $product->id(),
        $result['tier_ids'],
      );
      $this->messenger()->addStatus($this->t('Access code applied. Eligible tickets are shown below.'));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * Joins the tier waitlist for one ticket type.
   */
  public function joinWaitlistSubmit(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $name = (string) ($trigger['#name'] ?? '');
    if (!preg_match('/^waitlist_join_(\d+)$/', $name, $m)) {
      return;
    }
    $tid = (int) $m[1];
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form['#node'];
    $values = $form_state->getValue(['tier_waitlist', (string) $tid]) ?? [];
    $mail = trim((string) ($values['mail'] ?? ''));
    $qty = (int) ($values['qty'] ?? 1);

    $tier = $this->entityTypeManager->getStorage('mel_ticket_type')->load($tid);
    if (!$tier instanceof TicketTypeInterface) {
      $this->messenger()->addError($this->t('Invalid ticket type.'));
      $form_state->setRebuild();
      return;
    }

    try {
      $entry = $this->tierWaitlist->joinWaitlist($node, $tier, $mail, $qty, $this->currentUser());
      $pos = $this->tierWaitlist->getWaitingPosition($entry);
      $this->messenger()->addStatus($pos
        ? $this->t('You are on the waitlist (position @p).', ['@p' => (string) $pos])
        : $this->t('You are on the waitlist.'));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['tickets']) || !is_array($form['tickets'])) {
      return;
    }

    $tickets = $form_state->getValue('tickets', []);
    $has_quantity = FALSE;
    $total_quantity = 0;
    $per_variation = [];

    foreach ($tickets as $key => $value) {
      if (!is_numeric($key)) {
        continue;
      }
      if (is_array($value) && isset($value['quantity'])) {
        $quantity = (int) $value['quantity'];
        if ($quantity > 0) {
          $has_quantity = TRUE;
          $total_quantity += $quantity;
          $per_variation[(int) $key] = ($per_variation[(int) $key] ?? 0) + $quantity;
        }
      }
      elseif (is_numeric($value) && (int) $value > 0) {
        $has_quantity = TRUE;
        $total_quantity += (int) $value;
        $per_variation[(int) $key] = ($per_variation[(int) $key] ?? 0) + (int) $value;
      }
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $form['#node'];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form['#product'];
    $selected_bundles = $this->selectedTicketBundles($form_state, $node, $product);
    foreach ($selected_bundles as $selection) {
      $bundle_quantity = $selection['quantity'];
      $has_quantity = TRUE;
      $total_quantity += $selection['bundle']['total_tickets'] * $bundle_quantity;
      foreach ($selection['bundle']['components'] as $component) {
        $variation_id = $component['variation_id'];
        $component_total = $component['quantity'] * $bundle_quantity;
        $per_variation[$variation_id] = ($per_variation[$variation_id] ?? 0) + $component_total;
      }
    }

    if (!$has_quantity) {
      $form_state->setError($form['actions']['submit'], $this->t('Please select at least one ticket.'));
      return;
    }

    $pending = $this->getPendingCartTicketTotals($node, $product);
    $combined_event_total = $pending['event_total'] + $total_quantity;

    // Tier checks must pass before event capacity creates or refreshes a hold.
    // Otherwise a rejected tier can leave global inventory reserved.
    foreach ($per_variation as $variation_id => $qty) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id);
      if (!$variation instanceof ProductVariationInterface) {
        $form_state->setError($form['actions']['submit'], $this->t('An invalid ticket was selected.'));
        return;
      }
      $existing_line = (int) ($pending['variation'][$variation_id] ?? 0);
      $combined_line = $existing_line + $qty;
      try {
        $this->ticketAvailability->assertPaidVariationLineConstraints(
          $node,
          $product,
          $variation,
          $combined_line,
          NULL,
          NULL,
          $this->tierCapacityReservationKey($node, $product, $variation),
        );
      }
      catch (CapacityExceededException $e) {
        $form_state->setError($form['actions']['submit'], $e->getMessage());
        return;
      }
    }

    if ($node) {
      try {
        $this->ticketAvailability->assertEventTotalBookable(
          $node,
          $combined_event_total,
          $this->eventCapacityReservationKey($node, $product),
        );
      }
      catch (CapacityExceededException $e) {
        $remaining = $this->capacityService?->getRemaining($node);
        $message = $remaining !== NULL && $remaining > 0
          ? $this->t('Only @remaining ticket(s) remaining. Please adjust your quantity.', ['@remaining' => $remaining])
          : $this->t('This event is sold out.');
        $form_state->setError($form['actions']['submit'], $message);
        return;
      }
    }

    if ($this->eventDonationsEnabled($node)) {
      $donation = (float) $form_state->getValue('mel_donation', 0);
      if ($donation < 0) {
        $form_state->setErrorByName('mel_donation', $this->t('Contribution must be zero or more.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['tickets'])) {
      return;
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $form['#node'];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form['#product'];

    $tickets = $form_state->getValue('tickets', []);
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $total_quantity = 0;
    $per_variation = [];
    foreach ($tickets as $key => $value) {
      if (!is_numeric($key)) {
        continue;
      }
      $quantity = 0;
      if (is_array($value) && isset($value['quantity'])) {
        $quantity = (int) $value['quantity'];
      }
      if ($quantity > 0) {
        $total_quantity += $quantity;
        $per_variation[(int) $key] = ($per_variation[(int) $key] ?? 0) + $quantity;
      }
    }

    $selected_bundles = $this->selectedTicketBundles($form_state, $node, $product);
    foreach ($selected_bundles as $selection) {
      $bundle_quantity = $selection['quantity'];
      $total_quantity += $selection['bundle']['total_tickets'] * $bundle_quantity;
      foreach ($selection['bundle']['components'] as $component) {
        $variation_id = $component['variation_id'];
        $component_total = $component['quantity'] * $bundle_quantity;
        $per_variation[$variation_id] = ($per_variation[$variation_id] ?? 0) + $component_total;
      }
    }

    $pending = $this->getPendingCartTicketTotals($node, $product);
    $combined_event_total = $pending['event_total'] + $total_quantity;
    $selectionReservationKey = $this->eventCapacityReservationKey($node, $product);

    // Validate every tier before refreshing event-level capacity.
    foreach ($per_variation as $variation_id => $qty) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $variation_storage->load($variation_id);
      if (!$variation instanceof ProductVariationInterface) {
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
        $this->messenger()->addError($this->t('An invalid ticket was selected.'));
        return;
      }
      $existing_line = (int) ($pending['variation'][$variation_id] ?? 0);
      $combined_line = $existing_line + $qty;
      try {
        $this->ticketAvailability->assertPaidVariationLineConstraints(
          $node,
          $product,
          $variation,
          $combined_line,
          NULL,
          NULL,
          $this->tierCapacityReservationKey($node, $product, $variation),
        );
      }
      catch (CapacityExceededException $e) {
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
        $this->messenger()->addError($e->getMessage());
        return;
      }
    }

    try {
      $this->ticketAvailability->assertEventTotalBookable(
        $node,
        $combined_event_total,
        $selectionReservationKey,
      );
    }
    catch (CapacityExceededException $e) {
      $this->releaseProvisionalSelectionReservation($selectionReservationKey);
      $this->messenger()->addError($e->getMessage());
      return;
    }

    $stores = $product->getStores();
    if (empty($stores)) {
      $this->releaseProvisionalSelectionReservation($selectionReservationKey);
      $this->messenger()->addError($this->t('No store available for this product.'));
      return;
    }
    $store = reset($stores);

    $cart = $this->cartProvider->getCart('default', $store)
      ?: $this->cartProvider->createCart('default', $store);

    $donation = $this->eventDonationsEnabled($node)
      ? (float) $form_state->getValue('mel_donation', 0)
      : 0.0;
    $donation_currency = NULL;
    if ($donation > 0) {
      if (!$cart->hasField('field_mel_donation')) {
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
        $this->logger('myeventlane_commerce')->error('Unable to add booking contribution because field_mel_donation is missing from commerce_order bundle @bundle.', [
          '@bundle' => $cart->bundle(),
        ]);
        $this->messenger()->addError($this->t('We could not add the contribution to this booking. Please contact support.'));
        return;
      }

      $donation_currency = $this->resolveDonationCurrency($cart, $per_variation, $variation_storage);
      if ($donation_currency === NULL) {
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
        $this->logger('myeventlane_commerce')->error('Unable to add booking contribution because no currency could be resolved for order @order_id.', [
          '@order_id' => $cart->id() ?: 'new',
        ]);
        $this->messenger()->addError($this->t('We could not add the contribution to this booking. Please contact support.'));
        return;
      }
    }

    $added = FALSE;
    $touchedItems = [];
    $addedBundleItems = [];
    $bundle_component_totals = [];
    foreach ($selected_bundles as $selection) {
      foreach ($selection['bundle']['components'] as $component) {
        $variation_id = $component['variation_id'];
        $bundle_component_totals[$variation_id] = ($bundle_component_totals[$variation_id] ?? 0)
          + ($component['quantity'] * $selection['quantity']);
      }
    }
    foreach ($per_variation as $variation_id => $quantity) {
      $standalone_quantity = $quantity - ($bundle_component_totals[$variation_id] ?? 0);
      if ($standalone_quantity < 1) {
        continue;
      }
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
      $variation = $variation_storage->load($variation_id);
      if ($variation && $variation->isPublished()) {
        $order_item = $this->cartManager->addEntity($cart, $variation, $standalone_quantity, TRUE);
        if ($order_item && $order_item->hasField('field_target_event')) {
          $order_item->set('field_target_event', ['target_id' => $node->id()]);
          $order_item->save();
        }
        if ($order_item) {
          $touchedItems[(int) $variation_id] = $order_item;
        }
        $added = TRUE;
      }
    }

    foreach ($selected_bundles as $group_id => $selection) {
      $bundleItems = $this->addTicketBundleToCart(
        $cart,
        $node,
        (int) $group_id,
        $selection['bundle'],
        $selection['quantity'],
        $variation_storage,
      );
      if ($bundleItems !== []) {
        array_push($addedBundleItems, ...$bundleItems);
        $added = TRUE;
      }
    }

    if ($added) {
      try {
        if ($this->cartTicketHold === NULL) {
          throw new \RuntimeException('The cart ticket hold service is unavailable.');
        }
        $this->cartTicketHold->refresh($cart);
      }
      catch (CapacityExceededException $e) {
        $this->restoreCartTicketQuantities($cart, $touchedItems, $pending['variation']);
        $this->removeAddedBundleItems($cart, $addedBundleItems);
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
        $this->messenger()->addError($e->getMessage());
        return;
      }
      catch (\Throwable $e) {
        $this->restoreCartTicketQuantities($cart, $touchedItems, $pending['variation']);
        $this->removeAddedBundleItems($cart, $addedBundleItems);
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
        $this->logger('myeventlane_commerce')->error(
          'Unable to create a ticket hold for cart @cart: @message',
          [
            '@cart' => (string) $cart->id(),
            '@message' => $e->getMessage(),
          ],
        );
        $this->messenger()->addError($this->t('We could not hold these tickets. Please try again.'));
        return;
      }

      $cartReservationKey = CartTicketHoldManager::reservationKey(
        (int) $cart->id(),
        (int) $node->id(),
      );
      if ($selectionReservationKey !== $cartReservationKey) {
        $this->releaseProvisionalSelectionReservation($selectionReservationKey);
      }
      if ($donation > 0 && $donation_currency !== NULL) {
        $this->applyMelDonationToOrder($cart, $donation, $donation_currency);
      }
      $this->getRequest()->getSession()->set('myeventlane_preferred_cart_id', (int) $cart->id());
      // Commerce adds one status message per component line. Replace those
      // implementation details with one message for the buyer's selection.
      $this->messenger()->deleteByType(MessengerInterface::TYPE_STATUS);
      $this->messenger()->addStatus($this->t('Your ticket selection was added to your cart.'));
      $form_state->setRedirect('commerce_cart.page');
    }
  }

  /**
   * Resolves submitted bundle quantities against fresh, public bundle data.
   *
   * @return array<int, array{quantity: int, bundle: array{name: string, description: string, price: \Drupal\commerce_price\Price, components: array<int, array{ticket_id: int, variation_id: int, label: string, quantity: int}>, total_tickets: int}}>
   */
  private function selectedTicketBundles(FormStateInterface $form_state, NodeInterface $event, ProductInterface $product): array {
    $submitted = (array) $form_state->getValue('bundles', []);
    if ($submitted === []) {
      return [];
    }

    $ticket_variation_ids = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
        if (!$ticket instanceof TicketTypeInterface
          || $ticket->isArchived()
          || $ticket->getTicketKind() !== 'paid'
          || $ticket->get('commerce_variation')->isEmpty()) {
          continue;
        }
        $ticket_variation_ids[(int) $ticket->id()] = (int) $ticket->get('commerce_variation')->target_id;
      }
    }
    $purchasable_variations = $this->ticketAvailability->filterPurchasableVariations($event, $product);
    $display = $this->loadTicketGroupDisplay(
      $event,
      $product,
      $ticket_variation_ids,
      array_map(static fn (ProductVariationInterface $variation): int => (int) $variation->id(), $purchasable_variations),
    );

    $selected = [];
    foreach ($submitted as $group_id => $value) {
      if (!is_numeric($group_id) || !isset($display['bundles'][(int) $group_id])) {
        continue;
      }
      $quantity = is_array($value)
        ? (int) ($value['quantity'] ?? $value['purchase_column']['quantity'] ?? 0)
        : (int) $value;
      if ($quantity < 1) {
        continue;
      }
      $selected[(int) $group_id] = [
        'quantity' => min(20, $quantity),
        'bundle' => $display['bundles'][(int) $group_id],
      ];
    }
    return $selected;
  }

  /**
   * Adds one bundle selection as locked component ticket lines.
   *
   * The underlying variations remain the source of truth for capacity,
   * attendee capture, ticket issuance and sales reporting. The configured
   * bundle total is allocated across those lines as overridden unit prices.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   Newly added component lines, or an empty array when invalid.
   */
  private function addTicketBundleToCart(
    OrderInterface $cart,
    NodeInterface $event,
    int $groupId,
    array $bundle,
    int $bundleQuantity,
    EntityStorageInterface $variationStorage,
  ): array {
    if ($bundleQuantity < 1 || !$bundle['price'] instanceof Price || $bundle['components'] === []) {
      return [];
    }

    $currency = strtoupper($bundle['price']->getCurrencyCode());
    $lines = [];
    foreach ($bundle['components'] as $component) {
      $variation = $variationStorage->load($component['variation_id']);
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished() || !$variation->getPrice()) {
        return [];
      }
      if (strtoupper($variation->getPrice()->getCurrencyCode()) !== $currency) {
        return [];
      }
      $quantity = $component['quantity'] * $bundleQuantity;
      $face_line_total = Calculator::multiply($variation->getPrice()->getNumber(), (string) $quantity);
      $lines[] = [
        'component' => $component,
        'variation' => $variation,
        'quantity' => $quantity,
        'face_line_total' => $face_line_total,
      ];
    }
    if ($lines === []) {
      return [];
    }

    $instance_id = sprintf('%d-%s', $groupId, bin2hex(random_bytes(8)));
    $added_items = [];
    $unit_prices = $this->ticketBundlePriceAllocator->allocateUnitPrices(
      $bundle['price'],
      $bundleQuantity,
      $lines,
    );
    if (count($unit_prices) !== count($lines)) {
      return [];
    }
    foreach ($lines as $index => $line) {
      $unit_price = $unit_prices[$index];

      $order_item = $this->cartManager->createOrderItem($line['variation'], (string) $line['quantity']);
      $order_item->setTitle($this->t('@bundle — @ticket', [
        '@bundle' => $bundle['name'],
        '@ticket' => $line['component']['label'],
      ]), TRUE);
      $order_item->setUnitPrice($unit_price, TRUE);
      if ($order_item->hasField('field_target_event')) {
        $order_item->set('field_target_event', ['target_id' => $event->id()]);
      }
      $order_item->setData('mel_ticket_bundle_id', $groupId);
      $order_item->setData('mel_ticket_bundle_name', $bundle['name']);
      $order_item->setData('mel_ticket_bundle_instance', $instance_id);
      $order_item->setData('mel_ticket_bundle_quantity', $bundleQuantity);
      $order_item->setData('mel_ticket_bundle_component_quantity', $line['component']['quantity']);
      $order_item->setData('mel_ticket_bundle_ticket_type_id', $line['component']['ticket_id']);
      // Store the buyer-facing, tax-inclusive unit amount. MEL forces an order
      // refresh on every draft save and Commerce Tax removes included tax on
      // each refresh. The bundle preprocessor restores this gross amount first
      // so an overridden bundle price cannot drift down across cart refreshes.
      $order_item->setData('mel_ticket_bundle_gross_unit_price', $unit_price->getNumber());
      $order_item->setData('mel_ticket_bundle_currency', $currency);
      $order_item->lock();
      $this->cartManager->addOrderItem($cart, $order_item, FALSE, FALSE);
      $added_items[] = $order_item;
    }
    $cart->save();
    return $added_items;
  }

  /**
   * Paid tiers on this product that are sold out with waitlist enabled.
   *
   * @return array<int, string>
   *   Ticket type ID => label.
   */
  private function buildWaitlistTierOptions(NodeInterface $event, ProductInterface $product): array {
    $out = [];
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return $out;
    }
    foreach ($event->get('field_ticket_types')->referencedEntities() as $tier) {
      if (!$tier instanceof TicketTypeInterface || $tier->getTicketKind() !== 'paid') {
        continue;
      }
      if ($tier->isArchived()) {
        continue;
      }
      if (!$tier->hasField('waitlist_enabled') || !$tier->get('waitlist_enabled')->value) {
        continue;
      }
      if ($tier->get('commerce_variation')->isEmpty()) {
        continue;
      }
      $variation = $tier->get('commerce_variation')->entity;
      if (!$variation || (int) $variation->getProductId() !== (int) $product->id()) {
        continue;
      }
      $status = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
      if ($status !== TicketStatusEvaluator::STATUS_SOLD_OUT) {
        continue;
      }
      $out[(int) $tier->id()] = $tier->label();
    }
    return $out;
  }

  private function resolveDefaultVariationId(?TicketTypeInterface $tier): ?int {
    if (!$tier instanceof TicketTypeInterface
      || !$tier->hasField('commerce_variation')
      || $tier->get('commerce_variation')->isEmpty()) {
      return NULL;
    }
    $variation_id = (int) $tier->get('commerce_variation')->target_id;
    return $variation_id > 0 ? $variation_id : NULL;
  }

  private function resolveBestValueVariationId(NodeInterface $event): ?int {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return NULL;
    }

    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if (!$ticket->hasField('field_is_best_value') || !$ticket->isBestValueTicket()) {
        continue;
      }
      if (!$ticket->hasField('commerce_variation') || $ticket->get('commerce_variation')->isEmpty()) {
        continue;
      }
      $variation_id = (int) $ticket->get('commerce_variation')->target_id;
      return $variation_id > 0 ? $variation_id : NULL;
    }

    return NULL;
  }

  /**
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  private function sortVariationsDefaultFirst(array $variations, int $defaultVariationId): array {
    usort($variations, static function (ProductVariationInterface $a, ProductVariationInterface $b) use ($defaultVariationId): int {
      $a_default = (int) $a->id() === $defaultVariationId;
      $b_default = (int) $b->id() === $defaultVariationId;
      if ($a_default === $b_default) {
        return 0;
      }
      return $a_default ? -1 : 1;
    });
    return $variations;
  }

  /**
   * Loads enabled booking-page groups without applying organiser-only access.
   *
   * Group labels and descriptions are intentionally public presentation data.
   * The query remains event-scoped and status-scoped; no order, customer or
   * payment data is loaded.
   *
   * @param array<int, int> $ticketVariationIds
   *   Ticket type ID => Commerce variation ID.
   *
   * @return array{
   *   groups: array<int, array{name: string, description: string, rank: int}>,
   *   bundles: array<int, array{name: string, description: string, price: \Drupal\commerce_price\Price, components: array<int, array{ticket_id: int, variation_id: int, label: string, quantity: int}>, total_tickets: int}>,
   *   variation_groups: array<int, int>,
   *   cacheability: \Drupal\Core\Cache\CacheableMetadata
   * }
   */
  private function loadTicketGroupDisplay(NodeInterface $event, ProductInterface $product, array $ticketVariationIds, array $purchasableVariationIds = []): array {
    $cacheability = new CacheableMetadata();
    $result = [
      'groups' => [],
      'bundles' => [],
      'variation_groups' => [],
      'cacheability' => $cacheability,
    ];
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_group')) {
      return $result;
    }

    $definition = $this->entityTypeManager->getDefinition('mel_ticket_group');
    $cacheability->setCacheTags($definition->getListCacheTags());
    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event->id())
      ->condition('status', 1)
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC')
      ->execute();
    if ($ids === []) {
      return $result;
    }

    foreach (array_values($storage->loadMultiple($ids)) as $rank => $group) {
      $group_id = (int) $group->id();
      $cacheability->addCacheableDependency($group);
      $group_mode = $group->hasField('group_mode')
        ? (string) ($group->get('group_mode')->value ?? 'section')
        : 'section';

      $ticket_ids = $group->hasField('ticket_types')
        ? array_map('intval', array_column($group->get('ticket_types')->getValue(), 'target_id'))
        : [];

      // Compatibility for an existing group that still points at the event's
      // one Commerce ticket product. Update 8012 persists the equivalent tier
      // references so this branch is not the long-term source of truth.
      if ($ticket_ids === [] && $group->hasField('ticket_products') && !$group->get('ticket_products')->isEmpty()) {
        $product_ids = array_map('intval', array_column($group->get('ticket_products')->getValue(), 'target_id'));
        if (in_array((int) $product->id(), $product_ids, TRUE)) {
          $ticket_ids = array_keys($ticketVariationIds);
        }
      }

      if ($group_mode === 'bundle') {
        $price_item = $group->hasField('bundle_price') ? $group->get('bundle_price')->first() : NULL;
        $price = $price_item && method_exists($price_item, 'toPrice') ? $price_item->toPrice() : NULL;
        $stored_bundle_map = $group->hasField('bundle_components') && !$group->get('bundle_components')->isEmpty()
          ? ($group->get('bundle_components')->first()?->getValue() ?? [])
          : [];
        $component_values = is_array($stored_bundle_map['components'] ?? NULL)
          ? $stored_bundle_map['components']
          : [];
        $components = [];
        $complete = $price instanceof Price && (float) $price->getNumber() > 0;
        foreach ($ticket_ids as $ticket_id) {
          $quantity = max(0, (int) ($component_values[(string) $ticket_id] ?? $component_values[$ticket_id] ?? 0));
          $variation_id = $ticketVariationIds[$ticket_id] ?? NULL;
          $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->load($ticket_id);
          if ($quantity < 1
            || $variation_id === NULL
            || ($purchasableVariationIds !== [] && !in_array($variation_id, $purchasableVariationIds, TRUE))
            || !$ticket instanceof TicketTypeInterface) {
            $complete = FALSE;
            continue;
          }
          $components[$ticket_id] = [
            'ticket_id' => $ticket_id,
            'variation_id' => $variation_id,
            'label' => trim((string) $ticket->label()),
            'quantity' => $quantity,
          ];
        }
        if ($complete && $components !== []) {
          $result['bundles'][$group_id] = [
            'name' => trim((string) $group->label()),
            'description' => trim(strip_tags((string) ($group->get('description')->value ?? ''))),
            'price' => $price,
            'components' => $components,
            'total_tickets' => array_sum(array_column($components, 'quantity')),
          ];
        }
        continue;
      }

      $result['groups'][$group_id] = [
        'name' => trim((string) $group->label()),
        'description' => trim(strip_tags((string) ($group->get('description')->value ?? ''))),
        'rank' => $rank,
      ];

      foreach ($ticket_ids as $ticket_id) {
        $variation_id = $ticketVariationIds[$ticket_id] ?? NULL;
        // First group wins if old data assigned a ticket more than once. The
        // organiser form prevents new duplicate assignments.
        if ($variation_id !== NULL && !isset($result['variation_groups'][$variation_id])) {
          $result['variation_groups'][$variation_id] = $group_id;
        }
      }
    }

    return $result;
  }

  /**
   * Builds buyer-facing bundle cards above standalone tickets.
   *
   * @param array<int, array{name: string, description: string, price: \Drupal\commerce_price\Price, components: array<int, array{ticket_id: int, variation_id: int, label: string, quantity: int}>, total_tickets: int}> $bundles
   */
  private function buildTicketBundleOptions(array $bundles): array {
    $build = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-ticket-bundles']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Ticket bundles'),
        '#attributes' => ['class' => ['mel-ticket-bundles__heading']],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('One bundle includes the fixed mix of tickets listed below.'),
        '#attributes' => ['class' => ['mel-ticket-bundles__intro', 'mel-text--muted']],
      ],
    ];

    foreach ($bundles as $group_id => $bundle) {
      $price = $bundle['price'];
      $formatted_price = $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
      $items = [];
      foreach ($bundle['components'] as $component) {
        $items[] = $this->formatPlural(
          $component['quantity'],
          '1 × @ticket',
          '@count × @ticket',
          ['@ticket' => $component['label']],
        );
      }
      $list = '<ul class="mel-ticket-bundle__components">';
      foreach ($items as $item) {
        $list .= '<li>' . Html::escape((string) $item) . '</li>';
      }
      $list .= '</ul>';

      $build[(string) $group_id] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-ticket-row', 'mel-card', 'mel-ticket-book-card', 'mel-ticket-bundle'],
          'data-ticket-row' => '1',
          'data-ticket-title' => $bundle['name'],
          'data-ticket-price-number' => $price->getNumber(),
          'data-ticket-bundle-id' => (string) $group_id,
        ],
        'label_cell' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-label-cell', 'mel-ticket-row__main']],
          'title_row' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-ticket-row__top']],
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'h4',
              '#value' => $bundle['name'],
              '#attributes' => ['class' => ['mel-ticket-label', 'mel-ticket-row__title']],
            ],
            'price' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $formatted_price,
              '#attributes' => ['class' => ['mel-ticket-row__top-price', 'mel-ticket-price']],
            ],
          ],
          'badge' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Complete bundle'),
            '#attributes' => ['class' => ['mel-ticket-bundle__badge']],
          ],
          'description' => $bundle['description'] !== '' ? [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $bundle['description'],
            '#attributes' => ['class' => ['mel-ticket-description', 'mel-ticket-row__description', 'mel-text--muted']],
          ] : ['#access' => FALSE],
          'components' => ['#markup' => $list],
          'selection_badge' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Selected'),
            '#attributes' => [
              'class' => ['mel-ticket-row__selection-badge'],
              'data-mel-ticket-selection-badge' => '1',
              'hidden' => 'hidden',
            ],
          ],
        ],
        'purchase_column' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-row__meta']],
          'quantity' => [
            '#type' => 'number',
            '#title' => $this->t('Number of @bundle bundles', ['@bundle' => $bundle['name']]),
            '#min' => 0,
            '#max' => 20,
            '#step' => 1,
            '#default_value' => 0,
            '#attributes' => [
              'class' => ['mel-ticket-quantity', 'mel-ticket-bundle__quantity'],
              'aria-label' => (string) $this->t('Number of @bundle bundles', ['@bundle' => $bundle['name']]),
            ],
          ],
        ],
      ];
    }
    return $build;
  }

  /**
   * Keeps booking sections together while preserving ticket order within them.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   * @param array{
   *   groups: array<int, array{name: string, description: string, rank: int}>,
   *   variation_groups: array<int, int>,
   *   cacheability: \Drupal\Core\Cache\CacheableMetadata
   * } $display
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  private function sortVariationsByTicketGroup(array $variations, array $display, ?int $defaultVariationId): array {
    usort($variations, static function (ProductVariationInterface $a, ProductVariationInterface $b) use ($display, $defaultVariationId): int {
      $a_group = $display['variation_groups'][(int) $a->id()] ?? NULL;
      $b_group = $display['variation_groups'][(int) $b->id()] ?? NULL;
      $a_rank = $a_group !== NULL ? $display['groups'][$a_group]['rank'] : PHP_INT_MAX;
      $b_rank = $b_group !== NULL ? $display['groups'][$b_group]['rank'] : PHP_INT_MAX;
      if ($a_rank !== $b_rank) {
        return $a_rank <=> $b_rank;
      }
      $a_default = $defaultVariationId !== NULL && (int) $a->id() === $defaultVariationId;
      $b_default = $defaultVariationId !== NULL && (int) $b->id() === $defaultVariationId;
      return $a_default === $b_default ? 0 : ($a_default ? -1 : 1);
    });
    return $variations;
  }

  /**
   * Builds an accessible heading at the start of one booking section.
   *
   * @param array{name: string, description: string} $group
   */
  private function buildTicketGroupHeading(string $groupKey, array $group): array {
    $heading_id = Html::getUniqueId('mel-ticket-group-' . $groupKey);
    $build = [
      '#type' => 'container',
      '#prefix' => '<section class="mel-ticket-booking-group" aria-labelledby="' . $heading_id . '">',
      '#attributes' => ['class' => ['mel-ticket-booking-group__header']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => Html::escape($group['name']),
        '#attributes' => [
          'class' => ['mel-ticket-booking-group__title'],
          'id' => $heading_id,
        ],
      ],
    ];
    if ($group['description'] !== '') {
      $build['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => Html::escape($group['description']),
        '#attributes' => ['class' => ['mel-ticket-booking-group__description', 'mel-text--muted']],
      ];
    }
    return $build;
  }

  private function defaultSelectedQuantity(TicketTypeInterface $tier): int {
    if (!$tier->hasField('group_sale_mode')) {
      return 1;
    }
    $mode = (string) ($tier->get('group_sale_mode')->value ?? 'none');
    if ($mode === 'fixed_bundle' || $mode === 'reserved_block') {
      return max(1, (int) ($tier->get('group_bundle_size')->value ?? 0));
    }
    if ($mode === 'minimum_group_size') {
      return max(1, (int) ($tier->get('group_min_size')->value ?? 0));
    }
    return 1;
  }

  private function groupRuleMessage(TicketTypeInterface $tier): string {
    if (!$tier->hasField('group_sale_mode')) {
      return '';
    }
    $mode = (string) ($tier->get('group_sale_mode')->value ?? 'none');
    return match ($mode) {
      'fixed_bundle' => $this->t('Must be bought in groups of @n.', [
        '@n' => (string) (int) ($tier->get('group_bundle_size')->value ?? 0),
      ]),
      'minimum_group_size' => $this->t('Minimum purchase: @n tickets.', [
        '@n' => (string) (int) ($tier->get('group_min_size')->value ?? 0),
      ]),
      'reserved_block' => $this->t('Reserved allocation — purchase in multiples of @n.', [
        '@n' => (string) (int) ($tier->get('group_bundle_size')->value ?? 0),
      ]),
      default => '',
    };
  }

  /**
   * @return array{min: int, step: int}
   */
  private function quantityWidgetRules(TicketTypeInterface $tier): array {
    $min = 0;
    $step = 1;
    if (!$tier->hasField('group_sale_mode')) {
      return ['min' => $min, 'step' => $step];
    }
    $mode = (string) ($tier->get('group_sale_mode')->value ?? 'none');
    if ($mode === 'fixed_bundle' || $mode === 'reserved_block') {
      $b = (int) ($tier->get('group_bundle_size')->value ?? 0);
      if ($b >= 2) {
        $step = $b;
      }
    }
    elseif ($mode === 'minimum_group_size') {
      // Keep #min at 0 so buyers can enter 0 for "not this tier"; server validates when qty > 0.
    }
    return ['min' => $min, 'step' => $step];
  }

  private function formatOfferCountdownDisplay(int $seconds): string {
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
  }

  /**
   * Stable capacity reservation key for ticket selection / cart flows.
   */
  private function eventCapacityReservationKey(NodeInterface $event_node, ProductInterface $product): string {
    $stores = $product->getStores();
    if ($stores !== []) {
      $store = reset($stores);
      $cart = $this->cartProvider->getCart('default', $store);
      if ($cart !== NULL) {
        return 'cart:' . $cart->id() . ':event:' . $event_node->id();
      }
    }
    return 'ticket-select:event:' . $event_node->id() . ':session:' . $this->getRequest()->getSession()->getId();
  }

  /**
   * Existing cart-tier hold excluded while validating a quantity increase.
   */
  private function tierCapacityReservationKey(
    NodeInterface $event,
    ProductInterface $product,
    ProductVariationInterface $variation,
  ): ?string {
    $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
    if (!$tier instanceof TicketTypeInterface) {
      return NULL;
    }
    $stores = $product->getStores();
    if ($stores === []) {
      return NULL;
    }
    $store = reset($stores);
    $cart = $this->cartProvider->getCart('default', $store);
    if (!$cart instanceof OrderInterface) {
      return NULL;
    }
    return CartTicketTierHoldStore::reservationKey(
      (int) $cart->id(),
      (int) $event->id(),
      (int) $tier->id(),
    );
  }

  /**
   * Releases a validation-only session hold without removing an existing cart.
   */
  private function releaseProvisionalSelectionReservation(string $reservationKey): void {
    if (str_starts_with($reservationKey, 'ticket-select:')) {
      $this->capacityService?->releaseReservation($reservationKey);
    }
  }

  /**
   * Restores cart quantities if the authoritative hold cannot be created.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   Active Commerce cart.
   * @param array<int, \Drupal\commerce_order\Entity\OrderItemInterface> $items
   *   Touched order items keyed by variation ID.
   * @param array<int, int> $originalQuantities
   *   Pre-submit cart quantity keyed by variation ID.
   */
  private function restoreCartTicketQuantities(
    OrderInterface $cart,
    array $items,
    array $originalQuantities,
  ): void {
    foreach ($items as $variationId => $item) {
      $originalQuantity = (int) ($originalQuantities[$variationId] ?? 0);
      if ($originalQuantity < 1) {
        $this->cartManager->removeOrderItem($cart, $item);
        continue;
      }
      $item->setQuantity((string) $originalQuantity);
      $this->cartManager->updateOrderItem($cart, $item);
    }
  }

  /**
   * Removes newly added bundle components if the cart hold cannot be created.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   Active Commerce cart.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $items
   *   Bundle component lines created during this submission.
   */
  private function removeAddedBundleItems(OrderInterface $cart, array $items): void {
    foreach ($items as $item) {
      $this->cartManager->removeOrderItem($cart, $item);
    }
  }

  /**
   * Ticket quantities already in the active cart for this event (same store as product).
   *
   * @return array{event_total: int, variation: array<int, int>}
   */
  private function getPendingCartTicketTotals(NodeInterface $event_node, ProductInterface $product): array {
    $stores = $product->getStores();
    if ($stores === []) {
      return ['event_total' => 0, 'variation' => []];
    }
    $store = reset($stores);
    $cart = $this->cartProvider->getCart('default', $store);
    if ($cart === NULL) {
      return ['event_total' => 0, 'variation' => []];
    }
    $eid = (int) $event_node->id();
    $by_variation = $this->orderInspector->extractEventVariationQuantities($cart);
    $event_totals = $this->orderInspector->extractEventQuantities($cart);
    return [
      'event_total' => (int) ($event_totals[$eid] ?? 0),
      'variation' => $by_variation[$eid] ?? [],
    ];
  }

  /**
   * Buyer-facing availability line (tier capacity vs sold / waitlist holds).
   *
   * Uses the same pool model as purchasability checks for non-waitlist-offer
   * buyers; waitlist-offer limits may differ and are enforced at validation.
   */
  private function buyerFacingAvailabilityMessage(
    NodeInterface $event,
    ?TicketTypeInterface $tier,
    int $variationId,
  ): string {
    if ($this->customerTicketTierDisplay instanceof CustomerTicketTierDisplayBuilder) {
      return $this->customerTicketTierDisplay->buyerFacingAvailabilityMessage($event, $tier, $variationId);
    }
    $pool = $this->ticketAvailability->getPublicPoolRemaining(
      $event,
      $tier,
      $variationId,
    );
    if ($pool === NULL) {
      return (string) $this->t('Available');
    }
    if ($pool < 1) {
      return (string) $this->t('Limited availability');
    }
    if ($pool === 1) {
      return (string) $this->t('Only 1 left');
    }
    if ($pool <= 10) {
      return (string) $this->t('Only @count left', ['@count' => (string) $pool]);
    }
    return (string) $this->t('Available');
  }

  private function appendMelDonationField(array &$form, NodeInterface $node, float $estimated_total): void {
    if (!$this->eventDonationsEnabled($node)) {
      return;
    }

    $donation_default = 5;
    if ($estimated_total > 50) {
      $donation_default = 10;
    }
    if ($estimated_total > 100) {
      $donation_default = 20;
    }

    $form['mel_donation_presets'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-donation-presets']],
      '#weight' => 11,
    ];
    foreach ([5, 10, 20] as $amount) {
      $form['mel_donation_presets']["preset_$amount"] = [
        '#type' => 'button',
        '#value' => '$' . $amount,
        '#attributes' => [
          'class' => ['mel-donation-chip'],
          'data-amount' => $amount,
        ],
      ];
    }

    $form['mel_donation'] = [
      '#type' => 'number',
      '#title' => $this->t('Support the organiser 💖'),
      '#description' => $this->t('Help make this event possible — your contribution goes directly to the organiser.'),
      '#min' => 0,
      '#step' => 1,
      '#default_value' => $donation_default,
      '#attributes' => [
        'class' => ['mel-booking-donation__input'],
        'data-mel-booking-donation' => '1',
      ],
      '#weight' => 12,
    ];
  }

  private function eventDonationsEnabled(NodeInterface $node): bool {
    return $node->hasField('field_enable_donations')
      && !$node->get('field_enable_donations')->isEmpty()
      && (bool) $node->get('field_enable_donations')->value;
  }

  /**
   * @param array<int, int> $perVariation
   */
  private function resolveDonationCurrency(OrderInterface $order, array $perVariation, EntityStorageInterface $variationStorage): ?string {
    $total = $order->getTotalPrice();
    if ($total instanceof Price) {
      return $total->getCurrencyCode();
    }

    foreach (array_keys($perVariation) as $variation_id) {
      $variation = $variationStorage->load($variation_id);
      if ($variation instanceof ProductVariationInterface && $variation->getPrice() instanceof Price) {
        return $variation->getPrice()->getCurrencyCode();
      }
    }

    foreach ($order->getItems() as $item) {
      $price = $item->getTotalPrice();
      if ($price instanceof Price) {
        return $price->getCurrencyCode();
      }
    }

    return NULL;
  }

  private function applyMelDonationToOrder(OrderInterface $order, float $donation, string $currency): void {
    if ($this->hasMelContributionAdjustment($order)) {
      return;
    }

    $amount = number_format($donation, 2, '.', '');
    $order->set('field_mel_donation', $amount);
    $order->addAdjustment(new Adjustment([
      'type' => 'custom',
      'label' => 'Contribution',
      'amount' => new Price($amount, $currency),
      'included' => FALSE,
      'locked' => TRUE,
      'source_id' => 'myeventlane_order_donation',
    ]));

    $order->recalculateTotalPrice();
    $order->save();
  }

  private function hasMelContributionAdjustment(OrderInterface $order): bool {
    foreach ($order->getAdjustments() as $existing) {
      if ((string) $existing->getLabel() === 'Contribution') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
