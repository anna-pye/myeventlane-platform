<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Service\OrderConfirmationAttachmentResolver;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Canonical issuance, idempotency, and PDF-from-ticket continuity.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class IssuancePipelineConvergenceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'link',
    'path',
    'path_alias',
    'views',
    'address',
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
    'myeventlane_vendor',
    'myeventlane_tickets',
    'myeventlane_wallet',
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
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('myeventlane_ticket');
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order']);

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
    $this->createEventFields();
    $this->ensureProductEventField();

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

    $store_type_storage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$store_type_storage->load('online')) {
      $store_type_storage->create(['id' => 'online', 'label' => 'Online'])->save();
    }
    $store = Store::create([
      'type' => 'online',
      'name' => 'Issuance Test Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $this->customer = User::create([
      'name' => 'issuance_customer',
      'mail' => 'issuance@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Issuance Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall A',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Issuance Ticket Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'ISSUANCE-SKU-1',
      'title' => 'GA',
      'product_id' => $product->id(),
      'price' => new Price('25.00', 'AUD'),
      'status' => 1,
    ]);
    $variation->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $store->id(),
      'state' => 'completed',
      'uid' => $this->customer->id(),
      'mail' => 'issuance@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 2,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();
  }

  /**
   * First issuance creates one ticket row per unit quantity.
   */
  public function testIssueForOrderCreatesTicketPerQuantity(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $this->assertSame(2, $this->countTicketsForOrder((int) $order->id()));
  }

  /**
   * ORDER_PAID replay (second issueForOrder) must not create duplicate tickets.
   */
  public function testIssueForOrderIdempotentReplay(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $this->assertSame(2, $this->countTicketsForOrder((int) $order->id()));

    $this->issuer()->issueForOrder($order);
    $this->assertSame(2, $this->countTicketsForOrder((int) $order->id()));
  }

  /**
   * Legacy Commerce order-item PDF path still produces attachment-shaped output.
   */
  public function testLegacyOrderItemPdfStillRenders(): void {
    $order = $this->loadOrder();
    $items = array_values($order->getItems());
    $this->assertNotEmpty($items);
    $item = $items[0];

    $pdfGenerator = $this->container->get('myeventlane_tickets.ticket_pdf_generator');
    $pdf = $pdfGenerator->getPdfContentForOrderItem($item);

    $this->assertSame('application/pdf', $pdf['mime'] ?? '');
    $this->assertSame('tickets-order-item-' . $item->id() . '.pdf', $pdf['filename'] ?? '');
    $this->assertNotEmpty($pdf['content'] ?? '');
  }

  /**
   * PDF bytes for attachments derive from issued ticket entities (not order items).
   */
  public function testPdfContentDerivesFromIssuedTicketEntity(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);

    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertCount(2, $tickets);
    foreach ($tickets as $ticket) {
      $ticket->set('holder_name', 'Pat Holder');
      $ticket->set('holder_email', 'pat@example.test');
      $ticket->set('status', Ticket::STATUS_ASSIGNED);
      $ticket->save();
    }

    $pdfGenerator = $this->container->get('myeventlane_tickets.ticket_pdf_generator');
    foreach ($tickets as $ticket) {
      $pdf = $pdfGenerator->getPdfContentForTicket($ticket);
      $this->assertNotEmpty($pdf['content'] ?? '');
      $this->assertNotEmpty($pdf['filename'] ?? '');
      $this->assertSame('application/pdf', $pdf['mime'] ?? '');
    }
  }

  /**
   * Order confirmation merge path: resolver appends one PDF per ticket entity.
   */
  public function testOrderConfirmationAttachmentResolverMergesTicketPdfs(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);

    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertCount(2, $tickets);
    foreach ($tickets as $ticket) {
      $ticket->set('holder_name', 'Alex Resolver');
      $ticket->set('holder_email', 'alex-resolver@example.test');
      $ticket->set('status', Ticket::STATUS_ASSIGNED);
      $ticket->save();
    }

    $resolver = new OrderConfirmationAttachmentResolver(
      $this->container->get('entity_type.manager'),
      new NullLogger(),
      $this->container->get('myeventlane_tickets.ticket_pdf_generator'),
    );

    $queued = [
      [
        'filename' => 'fixture.ics',
        'content' => 'BEGIN:VCALENDAR',
        'mime' => 'text/calendar',
      ],
    ];

    $merged = $resolver->mergeOrderConfirmationAttachments('order_confirmation', [
      'order_id' => (int) $order->id(),
    ], $queued);

    $this->assertGreaterThanOrEqual(3, count($merged));
    $this->assertSame('fixture.ics', $merged[0]['filename']);

    $pdf_count = 0;
    foreach ($merged as $attachment) {
      if (($attachment['mime'] ?? '') === 'application/pdf') {
        $pdf_count++;
        $this->assertNotEmpty($attachment['content'] ?? '');
        $this->assertNotEmpty($attachment['filename'] ?? '');
      }
    }
    $this->assertSame(2, $pdf_count);
  }

  /**
   * Wallet inward resolution maps the Commerce order item route key to issued tickets.
   */
  public function testWalletResolverLinksIssuedTicketToOrderItem(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $items = array_values($order->getItems());
    $this->assertNotEmpty($items);
    $item = $items[0];
    /** @var \Drupal\myeventlane_wallet\Service\WalletTicketResolver $resolver */
    $resolver = $this->container->get('myeventlane_wallet.ticket_resolver');
    $resolved = $resolver->resolvePrimaryTicketForOrderItem($item, $this->customer);
    $this->assertInstanceOf(Ticket::class, $resolved);
    $this->assertSame((int) $item->id(), (int) $resolved->get('order_item_id')->target_id);
  }

  /**
   * Apple Wallet scaffold bytes reuse the universal view model QR (TicketQrPayload).
   */
  public function testPkpassScaffoldUsesSingleQrSource(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertNotEmpty($tickets);
    $ticket = $tickets[0];
    $expected = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);
    /** @var \Drupal\myeventlane_wallet\Service\PkPassBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.pkpass_builder');
    $path = $builder->generate($item, $ticket);
    $payload = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($payload);
    $this->assertSame($expected, $payload['qr_payload']);
    $this->assertArrayHasKey('capabilities', $payload);
    $this->assertSame('admit', $payload['capabilities']['scanner_mode']);
  }

  /**
   * When no issued ticket exists, wallet builders keep the legacy empty placeholder.
   */
  public function testWalletOrderItemWithoutTicketUsesLegacyPkpassPlaceholder(): void {
    $order = $this->loadOrder();
    $item = array_values($order->getItems())[0];
    /** @var \Drupal\myeventlane_wallet\Service\PkPassBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.pkpass_builder');
    $path = $builder->generate($item, NULL);
    $this->assertSame('', (string) file_get_contents($path));
  }

  /**
   * Multiple tickets on one order item resolve by unique holder email when possible.
   */
  public function testWalletResolverPicksHolderWhenUniquelyMatches(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertCount(2, $tickets);
    $tickets[0]->set('holder_email', 'alpha@example.test');
    $tickets[0]->save();
    $tickets[1]->set('holder_email', 'beta@example.test');
    $tickets[1]->save();

    $beta = User::create([
      'name' => 'beta_viewer',
      'mail' => 'beta@example.test',
      'status' => 1,
    ]);
    $beta->save();

    /** @var \Drupal\myeventlane_wallet\Service\WalletTicketResolver $resolver */
    $resolver = $this->container->get('myeventlane_wallet.ticket_resolver');
    $picked = $resolver->resolvePrimaryTicketForOrderItem($item, $beta);
    $this->assertSame((int) $tickets[1]->id(), (int) $picked->id());
  }

  /**
   * Merch entitlements keep structured mel:v1 JSON QR contracts in wallet scaffolds.
   */
  public function testMerchEntitlementWalletScaffoldPreservesStructuredQr(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $ticket = $tickets[0];
    $ticket->set('entitlement_type', Ticket::ENTITLEMENT_MERCH);
    $ticket->save();

    /** @var \Drupal\myeventlane_wallet\Service\PkPassBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.pkpass_builder');
    $path = $builder->generate($item, $ticket);
    $payload = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($payload);
    $this->assertStringStartsWith('mel:v1:json:', (string) $payload['qr_payload']);
    $this->assertSame('collect', $payload['capabilities']['scanner_mode']);
  }
  public function testParkingEntitlementWalletScaffoldPreservesStructuredQr(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $ticket = $tickets[0];
    $ticket->set('entitlement_type', Ticket::ENTITLEMENT_PARKING);
    $ticket->save();

    /** @var \Drupal\myeventlane_wallet\Service\PkPassBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.pkpass_builder');
    $path = $builder->generate($item, $ticket);
    $payload = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($payload);
    $this->assertStringStartsWith('mel:v1:json:', (string) $payload['qr_payload']);
    $this->assertSame('validate', $payload['capabilities']['scanner_mode']);
  }
  public function testMultiUseEntitlementWalletScaffoldUsesStructuredQr(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $ticket = $tickets[0];
    $ticket->set('entitlement_type', Ticket::ENTITLEMENT_DRINK);
    $ticket->set('redemption_limit', 3);
    $ticket->save();

    /** @var \Drupal\myeventlane_wallet\Service\PkPassBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.pkpass_builder');
    $path = $builder->generate($item, $ticket);
    $payload = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($payload);
    $this->assertStringStartsWith('mel:v1:json:', (string) $payload['qr_payload']);
    $this->assertSame('redeem', $payload['capabilities']['scanner_mode']);
  }

  /**
   * Wallet download access follows purchaser ownership for issued tickets.
   */
  public function testWalletAccessCheckerAllowsPurchaser(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $ticket = $this->loadTicketsForOrder((int) $order->id())[0];
    /** @var \Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker $access */
    $access = $this->container->get('myeventlane_wallet.download_access');
    $access->assertAuthorized($item, $ticket, $this->customer);
    $this->addToAssertionCount(1);
  }

  /**
   * Wallet download access denies cross-customer ticket use.
   */
  public function testWalletAccessCheckerDeniesOtherCustomer(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $ticket = $this->loadTicketsForOrder((int) $order->id())[0];
    $stranger = User::create([
      'name' => 'stranger_wallet',
      'mail' => 'stranger-wallet@example.test',
      'status' => 1,
    ]);
    $stranger->save();
    /** @var \Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker $access */
    $access = $this->container->get('myeventlane_wallet.download_access');
    $this->expectException(AccessDeniedHttpException::class);
    $access->assertAuthorized($item, $ticket, $stranger);
  }

  private function issuer(): TicketIssuer {
    return $this->container->get('myeventlane_tickets.ticket_issuer');
  }

  private function loadOrder(): Order {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('order_id')->execute();
    $this->assertNotEmpty($ids);
    $order = $storage->load(reset($ids));
    $this->assertInstanceOf(Order::class, $order);
    return $order;
  }

  /**
   * @return array<int, \Drupal\myeventlane_tickets\Entity\Ticket>
   */
  private function loadTicketsForOrder(int $order_id): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order_id)
      ->sort('id')
      ->execute();
    if (!$ids) {
      return [];
    }
    $loaded = $storage->loadMultiple($ids);
    return array_values($loaded);
  }

  private function countTicketsForOrder(int $order_id): int {
    return count($this->loadTicketsForOrder($order_id));
  }

  /**
   * @return array<string, array{type: string, settings: array<string, mixed>}>
   */
  private function eventFieldDefinitions(): array {
    return [
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
  }

  private function createEventFields(): void {
    foreach ($this->eventFieldDefinitions() as $field_name => $definition) {
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

  private function ensureProductEventField(): void {
    if (FieldStorageConfig::loadByName('commerce_product', 'field_event')) {
      return;
    }
    FieldStorageConfig::create([
      'field_name' => 'field_event',
      'entity_type' => 'commerce_product',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_event',
      'entity_type' => 'commerce_product',
      'bundle' => 'default',
      'label' => 'Event',
    ])->save();
  }

}
