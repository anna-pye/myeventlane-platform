<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_checkout_flow\Service\MyTicketsOrderViewModelBuilder;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests My Tickets order models use canonical ticket view models.
 *
 * @group myeventlane_checkout_flow
 */
#[RunTestsInSeparateProcesses]
final class MyTicketsOrderViewModelBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_number_pattern',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'commerce_store',
    'entity_reference_revisions',
    'paragraphs',
    'profile',
    'state_machine',
    'mel_ticket',
    'myeventlane_core',
    'myeventlane_vendor',
    'myeventlane_tickets',
  ];

  private Node $event;

  private User $customer;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    foreach (array_keys($container->getDefinitions()) as $service_id) {
      if (str_starts_with($service_id, 'myeventlane_vendor.') && $service_id !== 'myeventlane_vendor.event_access_checker') {
        $container->removeDefinition($service_id);
      }
    }

    $container->register('myeventlane_vendor.event_access_checker', EventVendorAccessChecker::class);
    $container->register('myeventlane_onboarding.manager', \stdClass::class);
    $container->register('myeventlane_core.vendor_follow', \stdClass::class);
    $container->register('myeventlane_event_studio.save', \stdClass::class);
    $container->register('myeventlane_legal.gatekeeper', \stdClass::class);
    $container->register('myeventlane_core.domain_detector', \stdClass::class);
    $container->register('myeventlane_analytics.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_commerce.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
    $container->register('myeventlane_commerce.ticket_backed_order_item_classifier', \stdClass::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('myeventlane_ticket');

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
    $this->createEventFields();

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'purchasableEntityType' => 'commerce_product_variation',
        'orderType' => 'default',
      ])->save();
    }
    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }
    if (!Currency::load('AUD')) {
      Currency::create([
        'currencyCode' => 'AUD',
        'name' => 'Australian Dollar',
        'numericCode' => '036',
        'symbol' => '$',
        'fractionDigits' => 2,
      ])->save();
    }

    $this->customer = User::create([
      'name' => 'customer',
      'mail' => 'customer@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    // Keep fixture start in the future so ACE readiness temporal overlays
    // (and has_upcoming_events) stay deterministic without re-saving nodes.
    $start = time() + (30 * 86400);
    $this->event = Node::create([
      'type' => 'event',
      'title' => 'My Tickets Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => gmdate('Y-m-d\TH:i:s', $start),
      'field_event_end' => gmdate('Y-m-d\TH:i:s', $start + 7200),
      'field_venue_name' => 'Gate A',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $this->event->save();

    $this->setSetting('myeventlane_qr_secret', 'kernel-test-qr-secret');
  }

  /**
   * Issued admission tickets render from canonical ticket view models.
   */
  public function testBuildsCanonicalAdmissionTicketModelForMyTickets(): void {
    $order = $this->createOrder();
    $ticket = $this->createTicket($order, [
      'ticket_code' => 'MEL-MYTICKETS-ADMIT',
      'holder_name' => 'Taylor Customer',
      'holder_email' => 'taylor@example.test',
      'status' => Ticket::STATUS_ASSIGNED,
    ]);

    $model = $this->builder()->build($order, TRUE);

    $this->assertSame($order->id(), $model['order_id']);
    $this->assertCount(1, $model['ticket_models']);
    $this->assertSame((int) $ticket->id(), $model['ticket_models'][0]['ticket']['id']);
    $this->assertSame(Ticket::ENTITLEMENT_TICKET, $model['ticket_models'][0]['ticket']['entitlement_type']);
    $this->assertSame('Assigned', $model['ticket_models'][0]['ticket']['status_label']);
    $this->assertStringStartsWith('mel:v1:', $model['ticket_models'][0]['qr']['payload']);
    $this->assertStringStartsWith('data:image/png;base64,', $model['ticket_models'][0]['qr']['data_uri']);
    $this->assertStringContainsString('/ticket/MEL-MYTICKETS-ADMIT/pdf', $model['ticket_models'][0]['actions']['pdf']['download']['url']);
    $this->assertSame([], $model['ticket_items']);
  }

  /**
   * Merch entitlement rendering preserves fulfilment and collection metadata.
   */
  public function testBuildsCanonicalMerchEntitlementForMyTickets(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-MYTICKETS-MERCH',
      'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
      'fulfilment_status' => Ticket::FULFILMENT_READY,
      'collect_location' => 'Merch tent',
      'collect_window' => [
        'value' => '2026-06-01T08:00:00',
        'end_value' => '2026-06-01T12:00:00',
      ],
    ]);

    $ticket = $this->builder()->build($order, TRUE)['ticket_models'][0];

    $this->assertSame(Ticket::ENTITLEMENT_MERCH, $ticket['ticket']['entitlement_type']);
    $this->assertSame('Merch pickup', $ticket['ticket']['entitlement_label']);
    $this->assertSame(Ticket::FULFILMENT_READY, $ticket['fulfilment']['status']);
    $this->assertSame('Ready', $ticket['fulfilment']['status_label']);
    $this->assertSame('Merch tent', $ticket['fulfilment']['collect_location']);
    $this->assertGreaterThan(0, $ticket['fulfilment']['collect_window']['start_timestamp']);
  }

  /**
   * Parking entitlement rendering preserves vehicle and redemption metadata.
   */
  public function testBuildsCanonicalParkingEntitlementForMyTickets(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-MYTICKETS-PARK',
      'entitlement_type' => Ticket::ENTITLEMENT_PARKING,
      'vehicle_registration' => 'MEL123',
      'redemption_limit' => 1,
      'redemption_count' => 0,
    ]);

    $ticket = $this->builder()->build($order, TRUE)['ticket_models'][0];

    $this->assertSame(Ticket::ENTITLEMENT_PARKING, $ticket['ticket']['entitlement_type']);
    $this->assertSame('Parking', $ticket['ticket']['entitlement_label']);
    $this->assertSame('MEL123', $ticket['fulfilment']['vehicle_registration']);
    $this->assertSame(1, $ticket['redemption']['limit']);
    $this->assertSame(1, $ticket['redemption']['remaining']);
  }

  /**
   * Expiry and fulfilment states remain canonical in My Tickets models.
   */
  public function testBuildsExpiredAndFulfilledCanonicalEntitlementsForMyTickets(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-MYTICKETS-EXPIRED',
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'expires_at' => gmdate('Y-m-d\TH:i:s', time() - 3600),
      'fulfilment_status' => Ticket::FULFILMENT_EXPIRED,
    ]);
    $this->createTicket($order, [
      'ticket_code' => 'MEL-MYTICKETS-COLLECTED',
      'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
      'fulfilment_status' => Ticket::FULFILMENT_COLLECTED,
    ]);

    $tickets = $this->builder()->build($order, TRUE)['ticket_models'];

    $this->assertTrue($tickets[0]['expiry']['expired']);
    $this->assertSame('expired', $tickets[0]['scanner']['status']);
    $this->assertSame(Ticket::FULFILMENT_EXPIRED, $tickets[0]['fulfilment']['status']);
    $this->assertSame(Ticket::FULFILMENT_COLLECTED, $tickets[1]['fulfilment']['status']);
    $this->assertSame('Collected', $tickets[1]['fulfilment']['status_label']);
  }

  /**
   * Legacy order-item rendering remains available when no issued tickets exist.
   */
  public function testPreservesLegacyTicketItemFallback(): void {
    $order = $this->createOrder();
    $item = OrderItem::create([
      'type' => 'default',
      'title' => 'Legacy Admission',
      'quantity' => '2',
      'unit_price' => new Price('12.50', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();

    $model = $this->builder()->build($order, TRUE);

    $this->assertSame([], $model['ticket_models']);
    $this->assertCount(1, $model['ticket_items']);
    $this->assertSame('Legacy Admission', $model['ticket_items'][0]['title']);
    $this->assertSame(2, $model['ticket_items'][0]['quantity']);
  }


  /**
   * Overview models skip QR generation (includeQr defaults FALSE).
   */
  public function testOverviewSkipsQrGeneration(): void {
    $order = $this->createOrder();
    $this->createTicket($order, ['ticket_code' => 'MEL-OVERVIEW-NO-QR']);
    $model = $this->builder()->buildMultiple([$order])[0];
    $this->assertCount(1, $model['ticket_models']);
    $qr = $model['ticket_models'][0]['qr'];
    $this->assertFalse($qr['included']);
    $this->assertSame('', $qr['payload']);
    $this->assertFalse($qr['unavailable']);
  }

  /**
   * Detail models include QR when the signing secret is present.
   */
  public function testDetailIncludesQrWhenSecretConfigured(): void {
    $order = $this->createOrder();
    $this->createTicket($order, ['ticket_code' => 'MEL-DETAIL-QR']);
    $model = $this->builder()->build($order, TRUE);
    $qr = $model['ticket_models'][0]['qr'];
    $this->assertTrue($qr['included']);
    $this->assertStringStartsWith('mel:v1:', $qr['payload']);
    $this->assertFalse($qr['unavailable']);
  }

  /**
   * Digital pass presentation reuses ACE labels without changing QR payload.
   */
  public function testEnrichesDigitalPassPresentation(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-PASS-READY',
      'status' => Ticket::STATUS_ASSIGNED,
      'holder_name' => 'Alex Attendee',
    ]);
    $model = $this->builder()->build($order, TRUE);
    $ticket = $model['ticket_models'][0];
    $this->assertArrayHasKey('pass', $ticket);
    $this->assertSame('ticket_ready', $ticket['pass']['status_key']);
    $this->assertSame('Ticket ready', $ticket['pass']['status_label']);
    $this->assertNotSame('', $ticket['pass']['next_step']);
    $this->assertSame('My Tickets Event', $ticket['pass']['event']['title']);
    $this->assertStringStartsWith('mel:v1:', $ticket['qr']['payload']);
    $this->assertArrayHasKey('refund_url', $model['help']);
    $this->assertArrayHasKey('help_centre_url', $model['help']);
  }

  /**
   * Event Readiness panel projects existing booking data with one primary action.
   */
  public function testBuildsEventReadinessPanel(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-READY-PANEL',
      'status' => Ticket::STATUS_ASSIGNED,
    ]);
    $model = $this->builder()->build($order, TRUE);
    $this->assertArrayHasKey('readiness', $model);
    $readiness = $model['readiness'];
    $this->assertSame('Event readiness', $readiness['heading']);
    $this->assertSame('ticket_ready', $readiness['state_key']);
    $this->assertSame('Ticket ready', $readiness['state_label']);
    $this->assertNotEmpty($readiness['items']);
    $keys = array_column($readiness['items'], 'key');
    $this->assertContains('booking_confirmed', $keys);
    $this->assertContains('ticket_ready', $keys);
    $this->assertContains('date_time', $keys);
    $this->assertContains('venue', $keys);
    $this->assertIsArray($readiness['primary_action']);
    $this->assertSame('view_ticket', $readiness['primary_action']['key']);
    $this->assertSame('#mel-pass-entry', $readiness['primary_action']['url']);
    $this->assertStringContainsString('7 days', $readiness['reminder_note']);
    $this->assertStringContainsString('24 hours', $readiness['reminder_note']);
    $this->assertSame('Need more information?', $readiness['summary']);
    $this->assertNotEmpty($readiness['detail_items']);
    $detailKeys = array_column($readiness['detail_items'], 'key');
    $this->assertNotContains('booking_confirmed', $detailKeys);
    $this->assertNotContains('ticket_ready', $detailKeys);
    $this->assertContains('venue', $detailKeys);
    $this->assertSame($readiness, $model['ticket_models'][0]['readiness']);
  }

  /**
   * Multi-event bookings attach readiness to each pass's own event.
   */
  public function testEventReadinessMatchesEachDigitalPassEvent(): void {
    $order = $this->createOrder();
    $secondStart = time() + (60 * 86400);
    $secondEvent = Node::create([
      'type' => 'event',
      'title' => 'Second Pass Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => gmdate('Y-m-d\TH:i:s', $secondStart),
      'field_event_end' => gmdate('Y-m-d\TH:i:s', $secondStart + 7200),
      'field_venue_name' => 'Gate B',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $secondEvent->save();

    $this->createTicket($order, [
      'ticket_code' => 'MEL-READY-A',
      'status' => Ticket::STATUS_ASSIGNED,
      'event_id' => $this->event->id(),
      'order_item_id' => 1,
    ]);
    $this->createTicket($order, [
      'ticket_code' => 'MEL-READY-B',
      'status' => Ticket::STATUS_ASSIGNED,
      'event_id' => $secondEvent->id(),
      'order_item_id' => 2,
    ]);

    $model = $this->builder()->build($order, TRUE);
    $this->assertCount(2, $model['ticket_models']);

    $first = $model['ticket_models'][0];
    $second = $model['ticket_models'][1];
    $this->assertArrayHasKey('readiness', $first);
    $this->assertArrayHasKey('readiness', $second);
    $this->assertSame('Gate A', $first['pass']['event']['location']);
    $this->assertSame('Gate B', $second['pass']['event']['location']);
    $this->assertSame('Gate A', $this->readinessVenueDetail($first['readiness']));
    $this->assertSame('Gate B', $this->readinessVenueDetail($second['readiness']));
    $this->assertSame('#mel-pass-entry', $first['readiness']['primary_action']['url']);
    $this->assertSame('#mel-pass-entry-2', $second['readiness']['primary_action']['url']);
    $this->assertSame($first['readiness'], $model['readiness']);
  }

  /**
   * Same-event multi-pass bookings keep readiness on every digital pass.
   */
  public function testSameEventPassesEachGetReadiness(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-SAME-A',
      'status' => Ticket::STATUS_ASSIGNED,
      'event_id' => $this->event->id(),
      'order_item_id' => 1,
    ]);
    $this->createTicket($order, [
      'ticket_code' => 'MEL-SAME-B',
      'status' => Ticket::STATUS_ASSIGNED,
      'event_id' => $this->event->id(),
      'order_item_id' => 2,
    ]);

    $model = $this->builder()->build($order, TRUE);
    $this->assertCount(2, $model['ticket_models']);
    $first = $model['ticket_models'][0];
    $second = $model['ticket_models'][1];

    $this->assertArrayHasKey('readiness', $first);
    $this->assertArrayHasKey('readiness', $second);
    $this->assertSame('Gate A', $this->readinessVenueDetail($first['readiness']));
    $this->assertSame('Gate A', $this->readinessVenueDetail($second['readiness']));
    $this->assertSame('#mel-pass-entry', $first['readiness']['primary_action']['url']);
    $this->assertSame('#mel-pass-entry-2', $second['readiness']['primary_action']['url']);
    $this->assertSame('MEL-SAME-A', $this->readinessTicketCode($first['readiness']));
    $this->assertSame('MEL-SAME-B', $this->readinessTicketCode($second['readiness']));
  }

  /**
   * @param array<string, mixed> $readiness
   */
  private function readinessVenueDetail(array $readiness): string {
    foreach ($readiness['detail_items'] ?? [] as $item) {
      if (($item['key'] ?? '') === 'venue') {
        return (string) ($item['detail'] ?? '');
      }
    }
    return '';
  }

  /**
   * @param array<string, mixed> $readiness
   */
  private function readinessTicketCode(array $readiness): string {
    foreach ($readiness['items'] ?? [] as $item) {
      if (($item['key'] ?? '') === 'ticket_ready') {
        return (string) ($item['detail'] ?? '');
      }
    }
    return '';
  }

  /**
   * Checked-in tickets surface ACE "Checked in" on the digital pass.
   */
  public function testDigitalPassCheckedInStatus(): void {
    $order = $this->createOrder();
    $this->createTicket($order, [
      'ticket_code' => 'MEL-PASS-IN',
      'status' => Ticket::STATUS_CHECKED_IN,
    ]);
    $pass = $this->builder()->build($order, TRUE)['ticket_models'][0]['pass'];
    $this->assertSame('checked_in', $pass['status_key']);
    $this->assertSame('Checked in', $pass['status_label']);
  }

  /**
   * Detail models degrade QR when the signing secret is missing.
   */
  public function testDetailDegradesQrWhenSecretMissing(): void {
    $this->setSetting('myeventlane_qr_secret', '');
    putenv('MEL_QR_SECRET');
    $order = $this->createOrder();
    $this->createTicket($order, ['ticket_code' => 'MEL-DETAIL-NO-SECRET']);
    $model = $this->builder()->build($order, TRUE);
    $qr = $model['ticket_models'][0]['qr'];
    $this->assertTrue($qr['included']);
    $this->assertTrue($qr['unavailable']);
    $this->assertSame('', $qr['payload']);
  }

  /**
   * Returns the My Tickets order view model builder.
   */
  private function builder(): object {
    return new MyTicketsOrderViewModelBuilder(
      $this->container->get('entity_type.manager'),
      $this->container->get('myeventlane_tickets.universal_ticket_view_model_builder'),
      $this->container->get('myeventlane_core.ticket_label_resolver'),
      $this->container->get('string_translation'),
      $this->container->has('myeventlane_commerce.operational_order_item_display_builder')
        ? $this->container->get('myeventlane_commerce.operational_order_item_display_builder')
        : NULL,
      $this->container->has('myeventlane_surface.state_readiness_helper')
        ? $this->container->get('myeventlane_surface.state_readiness_helper')
        : NULL,
      $this->container->has('myeventlane_legal.settings')
        ? $this->container->get('myeventlane_legal.settings')
        : NULL,
    );
  }

  /**
   * Creates a completed order fixture.
   */
  private function createOrder(array $overrides = []): Order {
    $values = array_replace([
      'type' => 'default',
      'state' => 'completed',
      'uid' => $this->customer->id(),
      'mail' => 'customer@example.test',
      'placed' => time(),
    ], $overrides);

    $order = Order::create($values);
    $order->save();
    return $order;
  }

  /**
   * Creates one issued ticket linked to an order.
   */
  private function createTicket(Order $order, array $overrides = []): Ticket {
    $values = array_replace([
      'ticket_code' => 'MEL-MYTICKETS-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => $this->event->id(),
      'order_id' => $order->id(),
      'purchaser_uid' => $this->customer->id(),
      'status' => Ticket::STATUS_ASSIGNED,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  /**
   * Creates the event fields needed by the canonical builder fixtures.
   */
  private function createEventFields(): void {
    $fields = [
      'field_event_start' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_event_end' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_venue_name' => [
        'type' => 'string',
        'settings' => ['max_length' => 255],
      ],
      'field_event_vendor' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
      ],
    ];

    foreach ($fields as $field_name => $definition) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $definition['type'],
        'settings' => $definition['settings'],
      ])->save();

      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => $field_name,
      ])->save();
    }
  }

}
