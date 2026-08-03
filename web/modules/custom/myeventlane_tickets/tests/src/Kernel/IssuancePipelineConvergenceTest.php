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
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Service\OrderConfirmationAttachmentResolver;
use Drupal\Tests\myeventlane_tickets\Kernel\Traits\RegistersTicketBackedClassifierStubTrait;
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
use ZipArchive;

/**
 * Canonical issuance, idempotency, and PDF-from-ticket continuity.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class IssuancePipelineConvergenceTest extends KernelTestBase {

  use RegistersTicketBackedClassifierStubTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
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
    $this->registerTicketBackedClassifierStub($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $settings = Settings::getAll();
    if (empty($settings['myeventlane_qr_secret'])) {
      $settings['myeventlane_qr_secret'] = 'kernel-test-qr-secret';
      new Settings($settings);
    }

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
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order', 'myeventlane_wallet']);

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
   * Apple Wallet .pkpass reuses the universal view model QR (TicketQrPayload).
   */
  public function testPkpassScaffoldUsesSingleQrSource(): void {
    $this->enableEphemeralAppleWalletSigning();
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertNotEmpty($tickets);
    $ticket = $tickets[0];
    $ticket->set('holder_name', 'Pat Holder');
    $ticket->save();
    $expected = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);
    /** @var \Drupal\myeventlane_wallet\Service\PkPassBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.pkpass_builder');
    $path = $builder->generate($item, $ticket);
    $pass = $this->readPkPassJson($path);
    $this->assertSame($expected, $pass['barcode']['message']);
    $this->assertSame('PKBarcodeFormatQR', $pass['barcode']['format']);
    $this->assertSame('Issuance Event', $pass['eventTicket']['primaryFields'][0]['value']);
    $this->assertSame('PKDateStyleMedium', $pass['eventTicket']['secondaryFields'][0]['dateStyle']);
    $this->assertSame('PKDateStyleShort', $pass['eventTicket']['secondaryFields'][1]['timeStyle']);
    $this->assertSame('Hall A', $pass['eventTicket']['auxiliaryFields'][0]['value']);
    $this->assertSame('NAME', $pass['eventTicket']['auxiliaryFields'][1]['label']);
    $this->assertSame('Pat Holder', $pass['eventTicket']['auxiliaryFields'][1]['value']);
    $this->assertNotContains('Admission', array_column($pass['eventTicket']['auxiliaryFields'], 'label'));
    $this->assertNotContains('ADDRESS', array_column($pass['eventTicket']['auxiliaryFields'], 'label'));
    $this->assertContains('admission', array_column($pass['eventTicket']['backFields'], 'key'));
    $this->assertSame('Issuance Event', $pass['semantics']['eventName']);
    $this->assertSame('Hall A', $pass['semantics']['venueName']);
    $this->assertSame('MyEventLane', $pass['organizationName']);
    $this->assertSame('MyEventLane', $pass['logoText']);
    $this->assertArrayHasKey('relevantDate', $pass);
    $this->assertArrayHasKey('expirationDate', $pass);

    $zip = new ZipArchive();
    $this->assertTrue($zip->open($path) === TRUE);
    foreach (['logo.png', 'logo@2x.png', 'icon.png', 'icon@2x.png', 'icon@3x.png'] as $asset) {
      $this->assertNotFalse($zip->locateName($asset), $asset . ' must be bundled in the pass.');
    }
    $this->assertFalse($zip->locateName('strip.png') !== FALSE, 'Event Ticket passes must omit strip.png.');
    $this->assertFalse($zip->locateName('background.png') !== FALSE);
    $this->assertFalse($zip->locateName('thumbnail.png') !== FALSE);
    $zip->close();
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
   * Merch entitlements keep structured mel:v1 JSON QR contracts in wallet passes.
   */
  public function testMerchEntitlementWalletScaffoldPreservesStructuredQr(): void {
    $this->enableEphemeralAppleWalletSigning();
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
    $pass = $this->readPkPassJson($path);
    $this->assertStringStartsWith('mel:v1:json:', (string) $pass['barcode']['message']);
  }

  public function testParkingEntitlementWalletScaffoldPreservesStructuredQr(): void {
    $this->enableEphemeralAppleWalletSigning();
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
    $pass = $this->readPkPassJson($path);
    $this->assertStringStartsWith('mel:v1:json:', (string) $pass['barcode']['message']);
  }

  public function testMultiUseEntitlementWalletScaffoldUsesStructuredQr(): void {
    $this->enableEphemeralAppleWalletSigning();
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
    $pass = $this->readPkPassJson($path);
    $this->assertStringStartsWith('mel:v1:json:', (string) $pass['barcode']['message']);
  }

  /**
   * Google Wallet save links embed the canonical TicketQrPayload in a signed JWT.
   */
  public function testGoogleWalletJwtUsesSingleQrSource(): void {
    $this->enableEphemeralGoogleWalletSigning();
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $ticket = $tickets[0];
    $expected = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    /** @var \Drupal\myeventlane_wallet\Service\GoogleWalletBuilder $builder */
    $builder = $this->container->get('myeventlane_wallet.google_wallet_builder');
    $this->assertTrue($builder->isReady());
    $url = $builder->generateSaveLink($item, $ticket);
    $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);
    $jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));
    $parts = explode('.', $jwt);
    $this->assertCount(3, $parts);
    $payload_json = base64_decode(strtr($parts[1], '-_', '+/'), TRUE);
    $this->assertNotFalse($payload_json);
    $payload = json_decode($payload_json, TRUE);
    $this->assertIsArray($payload);
    $this->assertSame('savetowallet', $payload['typ']);
    $this->assertArrayHasKey('genericClasses', $payload['payload']);
    $this->assertArrayHasKey('genericObjects', $payload['payload']);
    $this->assertSame($expected, $payload['payload']['genericObjects'][0]['barcode']['value']);
  }

  /**
   * Void tickets are not eligible for wallet download.
   */
  public function testWalletAccessCheckerDeniesVoidTicket(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $item = array_values($order->getItems())[0];
    $ticket = $this->loadTicketsForOrder((int) $order->id())[0];
    $ticket->set('status', Ticket::STATUS_VOID);
    $ticket->save();
    $ticket = Ticket::load($ticket->id());
    $this->assertSame(Ticket::STATUS_VOID, $ticket->get('status')->getString());

    /** @var \Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker $access */
    $access = $this->container->get('myeventlane_wallet.download_access');
    $this->expectException(AccessDeniedHttpException::class);
    $access->assertAuthorized($item, $ticket, $this->customer);
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

  /**
   * Writes ephemeral Google service-account material into Settings for kernel tests.
   */
  private function enableEphemeralGoogleWalletSigning(): void {
    $dir = sys_get_temp_dir() . '/mel_wallet_google_' . uniqid('', TRUE);
    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }
    $config = [
      'digest_alg' => 'sha256',
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $key = openssl_pkey_new($config);
    $this->assertNotFalse($key);
    openssl_pkey_export($key, $pem);
    $sa_path = $dir . '/sa.json';
    file_put_contents($sa_path, json_encode([
      'type' => 'service_account',
      'project_id' => 'mel-wallet-test',
      'private_key_id' => 'test',
      'private_key' => $pem,
      'client_email' => 'wallet-test@mel-wallet-test.iam.gserviceaccount.com',
      'client_id' => '123',
    ], JSON_THROW_ON_ERROR));

    $settings = Settings::getAll();
    $wallet = is_array($settings['myeventlane_wallet'] ?? NULL) ? $settings['myeventlane_wallet'] : [];
    $wallet['google_service_account_json_path'] = $sa_path;
    $wallet['google_origins'] = ['https://myeventlane.example.test'];
    $settings['myeventlane_wallet'] = $wallet;
    if (empty($settings['myeventlane_qr_secret'])) {
      $settings['myeventlane_qr_secret'] = 'kernel-test-qr-secret';
    }
    new Settings($settings);

    $this->config('myeventlane_wallet.settings')
      ->set('google_enabled', TRUE)
      ->set('google_issuer_id', '3388000000022145123')
      ->save();
  }

  /**
   * Writes ephemeral Apple signing material into Settings for kernel tests.
   *
   * Credentials are never committed; generated only inside the test process.
   */
  private function enableEphemeralAppleWalletSigning(): void {
    // Use system temp — OpenSSL RNG can fail under some simpletest site paths.
    $dir = sys_get_temp_dir() . '/mel_wallet_apple_' . uniqid('', TRUE);
    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }
    $config = [
      'digest_alg' => 'sha256',
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $passKey = openssl_pkey_new($config);
    $wwdrKey = openssl_pkey_new($config);
    $this->assertNotFalse($passKey);
    $this->assertNotFalse($wwdrKey);
    $passCsr = openssl_csr_new(['CN' => 'Pass Type ID: pass.com.example.mel.test'], $passKey, $config);
    $wwdrCsr = openssl_csr_new(['CN' => 'Apple WWDR Test CA'], $wwdrKey, $config);
    $wwdrCert = openssl_csr_sign($wwdrCsr, NULL, $wwdrKey, 3650, $config, 1);
    $passCert = openssl_csr_sign($passCsr, $wwdrCert, $wwdrKey, 3650, $config, 2);
    openssl_x509_export($passCert, $passPem);
    openssl_x509_export($wwdrCert, $wwdrPem);
    openssl_pkey_export($passKey, $keyPem);
    file_put_contents($dir . '/pass_cert.pem', $passPem);
    file_put_contents($dir . '/pass_key.pem', $keyPem);
    file_put_contents($dir . '/wwdr.pem', $wwdrPem);

    $settings = Settings::getAll();
    $settings['myeventlane_wallet'] = [
      'apple_certificate_path' => $dir . '/pass_cert.pem',
      'apple_private_key_path' => $dir . '/pass_key.pem',
      'apple_wwdr_certificate_path' => $dir . '/wwdr.pem',
    ];
    if (empty($settings['myeventlane_qr_secret'])) {
      $settings['myeventlane_qr_secret'] = 'kernel-test-qr-secret';
    }
    new Settings($settings);

    $this->config('myeventlane_wallet.settings')
      ->set('apple_enabled', TRUE)
      ->set('apple_team_id', 'ABCDE12345')
      ->set('apple_pass_type_id', 'pass.com.example.mel.test')
      ->set('apple_organisation_name', 'MyEventLane')
      ->save();
  }

  /**
   * @return array<string, mixed>
   *   Decoded pass.json from a .pkpass zip.
   */
  private function readPkPassJson(string $pkpassPath): array {
    $this->assertFileExists($pkpassPath);
    $zip = new ZipArchive();
    $this->assertTrue($zip->open($pkpassPath) === TRUE);
    $json = $zip->getFromName('pass.json');
    $this->assertNotFalse($json);
    $manifest_json = $zip->getFromName('manifest.json');
    $this->assertNotFalse($manifest_json);
    $this->assertNotFalse($zip->getFromName('signature'));
    $manifest = json_decode((string) $manifest_json, TRUE);
    $this->assertIsArray($manifest);
    foreach ($manifest as $filename => $hash) {
      $this->assertIsString($filename);
      $this->assertIsString($hash);
      $contents = $zip->getFromName($filename);
      $this->assertNotFalse($contents, $filename . ' must be present in the pass bundle.');
      $this->assertSame($hash, sha1((string) $contents), $filename . ' must match its manifest hash.');
    }
    $zip->close();
    $decoded = json_decode((string) $json, TRUE);
    $this->assertIsArray($decoded);
    return $decoded;
  }

}
