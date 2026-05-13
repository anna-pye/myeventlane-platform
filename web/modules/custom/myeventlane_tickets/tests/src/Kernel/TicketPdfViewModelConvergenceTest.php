<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\TicketPdfTemplateBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests PDF rendering convergence on canonical ticket view models.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TicketPdfViewModelConvergenceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'entity_reference_revisions',
    'paragraphs',
    'mel_ticket',
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
    $this->installEntitySchema('myeventlane_ticket');

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
    $this->createEventFields();

    $this->customer = User::create([
      'name' => 'pdf_customer',
      'mail' => 'pdf@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'PDF Convergence Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => '2026-07-15T18:00:00',
      'field_event_end' => '2026-07-15T23:00:00',
      'field_venue_name' => 'Convergence Arena',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $this->event->save();
  }

  /**
   * Standard admission ticket produces a canonical PDF model.
   */
  public function testAdmissionTicketPdfModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-ADM-001',
      'holder_name' => 'Alice Admission',
      'holder_email' => 'alice@example.test',
      'status' => Ticket::STATUS_ASSIGNED,
    ]);

    $variables = $this->preprocessTicket($ticket);

    $this->assertTrue($variables['is_canonical']);
    $this->assertSame('ticket', $variables['entitlement_type']);
    $this->assertSame('Ticket', $variables['entitlement_label']);
    $this->assertSame(Ticket::STATUS_ASSIGNED, $variables['ticket_status']);
    $this->assertNotEmpty($variables['qr_data_uri']);
    $this->assertFalse($variables['expiry']['expired']);
    $this->assertSame('pending', $variables['fulfilment']['status']);
    $this->assertNotEmpty($variables['badges']);

    $this->assertSame('MEL-PDF-ADM-001', $variables['code']);
    $this->assertSame('Alice Admission', $variables['holder']);
    $this->assertSame('PDF Convergence Event', $variables['event_title']);
    $this->assertSame('Convergence Arena', $variables['location']);
  }

  /**
   * Merch entitlement renders fulfilment and collect metadata.
   */
  public function testMerchEntitlementPdfModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-MERCH-001',
      'holder_name' => 'Bob Merch',
      'holder_email' => 'bob@example.test',
      'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
      'fulfilment_status' => Ticket::FULFILMENT_READY,
      'collect_location' => 'Merch tent, Gate B',
      'metadata_json' => ['sku' => 'tshirt-xl', 'color' => 'black'],
    ]);

    $variables = $this->preprocessTicket($ticket);

    $this->assertTrue($variables['is_canonical']);
    $this->assertSame('merch', $variables['entitlement_type']);
    $this->assertSame('Merch pickup', $variables['entitlement_label']);
    $this->assertSame(Ticket::FULFILMENT_READY, $variables['fulfilment']['status']);
    $this->assertSame('Ready', $variables['fulfilment']['status_label']);
    $this->assertSame('Merch tent, Gate B', $variables['fulfilment']['collect_location']);
    $this->assertSame('tshirt-xl', $variables['fulfilment']['metadata']['sku']);
  }

  /**
   * Parking entitlement renders vehicle registration metadata.
   */
  public function testParkingEntitlementPdfModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-PARK-001',
      'holder_name' => 'Carol Parker',
      'holder_email' => 'carol@example.test',
      'entitlement_type' => Ticket::ENTITLEMENT_PARKING,
      'vehicle_registration' => 'ABC-123',
    ]);

    $variables = $this->preprocessTicket($ticket);

    $this->assertTrue($variables['is_canonical']);
    $this->assertSame('parking', $variables['entitlement_type']);
    $this->assertSame('Parking', $variables['entitlement_label']);
    $this->assertSame('ABC-123', $variables['fulfilment']['vehicle_registration']);
  }

  /**
   * Expired entitlements include expiry badge and state.
   */
  public function testExpiredEntitlementPdfModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-EXP-001',
      'holder_name' => 'Eve Expired',
      'holder_email' => 'eve@example.test',
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'expires_at' => gmdate('Y-m-d\TH:i:s', time() - 7200),
    ]);

    $variables = $this->preprocessTicket($ticket);

    $this->assertTrue($variables['is_canonical']);
    $this->assertTrue($variables['expiry']['expired']);

    $badgeTypes = array_column($variables['badges'], 'type');
    $this->assertContains('expiry', $badgeTypes);
  }

  /**
   * Multi-use redemption metadata is passed to the template.
   */
  public function testMultiUseRedemptionPdfModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-MULTI-001',
      'holder_name' => 'Frank Multi',
      'holder_email' => 'frank@example.test',
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 5,
      'redemption_count' => 2,
    ]);

    $variables = $this->preprocessTicket($ticket);

    $this->assertTrue($variables['is_canonical']);
    $this->assertSame(5, $variables['redemption']['limit']);
    $this->assertSame(2, $variables['redemption']['count']);
    $this->assertSame(3, $variables['redemption']['remaining']);
    $this->assertTrue($variables['redemption']['multi_use']);
  }

  /**
   * QR payload is identical between view model and legacy preprocess.
   */
  public function testQrPayloadContinuity(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-QR-001',
      'holder_name' => 'Grace QR',
      'holder_email' => 'grace@example.test',
      'status' => Ticket::STATUS_ASSIGNED,
    ]);

    $builder = $this->container->get('myeventlane_tickets.universal_ticket_view_model_builder');
    $model = $builder->build($ticket);

    $variables = $this->preprocessTicket($ticket);

    $this->assertSame($model['qr']['data_uri'], $variables['qr_data_uri']);
  }

  /**
   * Canonical model and legacy template variables coexist.
   */
  public function testBackwardsCompatibility(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-PDF-COMPAT-001',
      'holder_name' => 'Hannah Compat',
      'holder_email' => 'hannah@example.test',
      'status' => Ticket::STATUS_ASSIGNED,
    ]);

    $variables = $this->preprocessTicket($ticket);

    $this->assertArrayHasKey('event_title', $variables);
    $this->assertArrayHasKey('event_start', $variables);
    $this->assertArrayHasKey('location', $variables);
    $this->assertArrayHasKey('holder', $variables);
    $this->assertArrayHasKey('code', $variables);
    $this->assertArrayHasKey('legacy', $variables);
    $this->assertArrayHasKey('include_qr_code', $variables);
    $this->assertArrayHasKey('show_ticket_code', $variables);
    $this->assertArrayHasKey('brand_logo_url', $variables);
    $this->assertArrayHasKey('brand_accent_color', $variables);
    $this->assertArrayHasKey('is_pro_branded', $variables);

    $this->assertArrayHasKey('is_canonical', $variables);
    $this->assertArrayHasKey('view_model', $variables);
    $this->assertArrayHasKey('entitlement_type', $variables);
    $this->assertArrayHasKey('fulfilment', $variables);
    $this->assertArrayHasKey('expiry', $variables);
    $this->assertArrayHasKey('badges', $variables);
  }

  /**
   * RSVP virtual ticket PDFs still render with frozen MIME and filename prefix.
   */
  public function testLegacyRsvpPdfStillRenders(): void {
    /** @var \Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator $pdfGenerator */
    $pdfGenerator = $this->container->get('myeventlane_tickets.ticket_pdf_generator');
    $pdf = $pdfGenerator->getPdfContentForRsvp([
      'id' => 4242,
      'name' => 'RSVP Kernel Guest',
      'email' => 'rsvp-kernel@example.test',
    ], $this->event);

    $this->assertSame('application/pdf', $pdf['mime'] ?? '');
    $this->assertStringStartsWith('rsvp-ticket-' . $this->event->id() . '-', (string) ($pdf['filename'] ?? ''));
    $this->assertStringEndsWith('.pdf', (string) ($pdf['filename'] ?? ''));
    $this->assertNotEmpty($pdf['content'] ?? '');
  }

  /**
   * Non-ticket path (no ticket entity) receives legacy preprocessing only.
   */
  public function testLegacyPathSkipsCanonicalModel(): void {
    $variables = [
      'ticket' => NULL,
      'code' => 'LEGACY-CODE-001',
      'event_title' => 'Legacy Event',
      'event_start' => '2026-01-01T10:00:00',
      'location' => 'Legacy Venue',
      'holder' => 'Legacy Holder',
      'legacy' => TRUE,
      'is_rsvp' => FALSE,
      'is_attendee' => FALSE,
      'order_item' => NULL,
      'brand_logo_url' => NULL,
      'brand_vendor_name' => NULL,
      'brand_accent_color' => '#f26d5b',
      'brand_footer_text' => NULL,
      'is_pro_branded' => FALSE,
      'include_qr_code' => TRUE,
      'show_ticket_code' => TRUE,
      'qr_data_uri' => NULL,
      'is_canonical' => FALSE,
      'view_model' => NULL,
      'entitlement_type' => NULL,
      'entitlement_label' => NULL,
      'ticket_status' => NULL,
      'ticket_status_label' => NULL,
      'redemption' => NULL,
      'expiry' => NULL,
      'fulfilment' => NULL,
      'badges' => [],
      'vendor_display' => NULL,
    ];

    /** @var \Drupal\myeventlane_tickets\Service\TicketPdfTemplateBuilder $builder */
    $builder = $this->container->get('myeventlane_tickets.ticket_pdf_template_builder');
    $builder->preprocess($variables);

    $this->assertFalse($variables['is_canonical']);
    $this->assertNull($variables['view_model']);
    $this->assertNull($variables['entitlement_type']);
    $this->assertNotEmpty($variables['qr_data_uri']);
  }

  /**
   * Preprocesses a ticket through the TicketPdfTemplateBuilder.
   *
   * Simulates the same variable structure that hook_theme + preprocess
   * produces, then applies the builder.
   *
   * @return array<string, mixed>
   *   The preprocessed template variables.
   */
  private function preprocessTicket(Ticket $ticket): array {
    $builder = $this->container->get('myeventlane_tickets.universal_ticket_view_model_builder');
    $model = $builder->build($ticket);

    $variables = [
      'ticket' => $ticket,
      'event_title' => $model['event']['label'],
      'event_start' => $model['event']['start']['raw'] ?: NULL,
      'location' => $model['event']['location'],
      'holder' => $model['holder']['name'],
      'code' => $model['ticket']['code'],
      'legacy' => FALSE,
      'is_rsvp' => FALSE,
      'is_attendee' => FALSE,
      'order_item' => NULL,
      'brand_logo_url' => NULL,
      'brand_vendor_name' => NULL,
      'brand_accent_color' => '#f26d5b',
      'brand_footer_text' => NULL,
      'is_pro_branded' => FALSE,
      'include_qr_code' => TRUE,
      'show_ticket_code' => TRUE,
      'qr_data_uri' => NULL,
      'is_canonical' => FALSE,
      'view_model' => NULL,
      'entitlement_type' => NULL,
      'entitlement_label' => NULL,
      'ticket_status' => NULL,
      'ticket_status_label' => NULL,
      'redemption' => NULL,
      'expiry' => NULL,
      'fulfilment' => NULL,
      'badges' => [],
      'vendor_display' => NULL,
    ];

    /** @var \Drupal\myeventlane_tickets\Service\TicketPdfTemplateBuilder $pdfBuilder */
    $pdfBuilder = $this->container->get('myeventlane_tickets.ticket_pdf_template_builder');
    $pdfBuilder->preprocess($variables);

    return $variables;
  }

  /**
   * Creates one issued ticket fixture.
   */
  private function createTicket(array $overrides = []): Ticket {
    $values = array_replace([
      'ticket_code' => 'MEL-PDF-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => $this->event->id(),
      'purchaser_uid' => $this->customer->id(),
      'status' => Ticket::STATUS_ASSIGNED,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  /**
   * Creates the event fields needed by the builder fixtures.
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
