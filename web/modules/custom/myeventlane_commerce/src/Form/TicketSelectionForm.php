<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_commerce\Service\TicketAccessCodeService;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_commerce\Service\TicketBookingSessionService;
use Drupal\myeventlane_commerce\Service\TicketStatusEvaluator;
use Drupal\myeventlane_commerce\Service\TicketTierWaitlistService;
use Drupal\myeventlane_commerce\Service\TicketVariationSoldService;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
    protected TicketAccessCodeService $accessCodeService,
    protected TicketBookingSessionService $bookingSession,
    protected TicketTierWaitlistService $tierWaitlist,
    protected TicketVariationSoldService $variationSold,
    protected TimeInterface $time,
    protected CapacityOrderInspector $orderInspector,
    protected ?EventCapacityServiceInterface $capacityService = NULL,
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
      $container->get('myeventlane_commerce.ticket_access_code'),
      $container->get('myeventlane_commerce.ticket_booking_session'),
      $container->get('myeventlane_commerce.ticket_tier_waitlist'),
      $container->get('myeventlane_commerce.ticket_variation_sold'),
      $container->get('datetime.time'),
      $container->get('myeventlane_capacity.order_inspector'),
      $container->has('myeventlane_capacity.service')
        ? $container->get('myeventlane_capacity.service')
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
      '#title' => $this->t('Access code'),
      '#description' => $this->t('If you have an organiser code, enter it here to reveal hidden or invite-only tickets for this browser session.'),
      '#attributes' => ['class' => ['mel-ticket-booking-access']],
      '#weight' => 0,
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
      if ($node->hasField('field_ticket_types') && !$node->get('field_ticket_types')->isEmpty()) {
        foreach ($node->get('field_ticket_types')->referencedEntities() as $ticket) {
          if ($ticket instanceof TicketTypeInterface && $ticket->isArchived()) {
            continue;
          }
          if ($ticket instanceof TicketType && !$ticket->get('commerce_variation')->isEmpty()) {
            $variation_entity = $ticket->get('commerce_variation')->entity;
            if ($variation_entity) {
              $ticket_type_labels[$variation_entity->uuid()] = $ticket->label();
            }
          }
        }
      }

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

        $tier = $this->ticketAvailability->resolveTierForVariation($node, $variation);
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
        if ($buyer_desc !== '') {
          $label_cell['description'] = [
            '#markup' => '<div class="mel-ticket-description mel-text--muted">' . nl2br(Html::escape($buyer_desc), FALSE) . '</div>',
          ];
        }

        $form['tickets'][$variation_id] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['mel-ticket-row'],
            'data-variation-id' => $variation_id,
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
      }

      $form['actions'] = [
        '#type' => 'actions',
        '#weight' => 20,
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Add to cart'),
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

    $form['#attached']['library'][] = 'myeventlane_theme/ticket_matrix';

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
          $per_variation[(int) $key] = $quantity;
        }
      }
      elseif (is_numeric($value) && (int) $value > 0) {
        $has_quantity = TRUE;
        $total_quantity += (int) $value;
        $per_variation[(int) $key] = (int) $value;
      }
    }

    if (!$has_quantity) {
      $form_state->setError($form['actions']['submit'], $this->t('Please select at least one ticket.'));
      return;
    }

    $node = $form['#node'];
    $product = $form['#product'];

    $pending = $this->getPendingCartTicketTotals($node, $product);
    $combined_event_total = $pending['event_total'] + $total_quantity;

    if ($node) {
      try {
        $this->ticketAvailability->assertEventTotalBookable($node, $combined_event_total);
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
        $this->ticketAvailability->assertPaidVariationLineConstraints($node, $product, $variation, $combined_line);
      }
      catch (CapacityExceededException $e) {
        $form_state->setError($form['actions']['submit'], $e->getMessage());
        return;
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
        $per_variation[(int) $key] = $quantity;
      }
    }

    $pending = $this->getPendingCartTicketTotals($node, $product);
    $combined_event_total = $pending['event_total'] + $total_quantity;

    try {
      $this->ticketAvailability->assertEventTotalBookable($node, $combined_event_total);
    }
    catch (CapacityExceededException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }

    foreach ($per_variation as $variation_id => $qty) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $variation_storage->load($variation_id);
      if (!$variation instanceof ProductVariationInterface) {
        $this->messenger()->addError($this->t('An invalid ticket was selected.'));
        return;
      }
      $existing_line = (int) ($pending['variation'][$variation_id] ?? 0);
      $combined_line = $existing_line + $qty;
      try {
        $this->ticketAvailability->assertPaidVariationLineConstraints($node, $product, $variation, $combined_line);
      }
      catch (CapacityExceededException $e) {
        $this->messenger()->addError($e->getMessage());
        return;
      }
    }

    $stores = $product->getStores();
    if (empty($stores)) {
      $this->messenger()->addError($this->t('No store available for this product.'));
      return;
    }
    $store = reset($stores);

    $cart = $this->cartProvider->getCart('default', $store)
      ?: $this->cartProvider->createCart('default', $store);

    $added = FALSE;
    foreach ($per_variation as $variation_id => $quantity) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
      $variation = $variation_storage->load($variation_id);
      if ($variation && $variation->isPublished()) {
        $order_item = $this->cartManager->addEntity($cart, $variation, $quantity, TRUE);
        if ($order_item && $order_item->hasField('field_target_event')) {
          $order_item->set('field_target_event', ['target_id' => $node->id()]);
          $order_item->save();
        }
        $added = TRUE;
      }
    }

    if ($added) {
      $this->messenger()->addStatus($this->t('Tickets added to cart.'));
      $form_state->setRedirect('commerce_cart.page');
    }
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

}
