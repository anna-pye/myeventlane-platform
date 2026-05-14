<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\DeviceOperationIdentityManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\DeviceOperationIdentityManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class DeviceOperationIdentityManagerTest extends KernelTestBase {

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

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('myeventlane_ticket');
    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
  }

  public function testClassifyTrustLevelNormalizesSynonyms(): void {
    $m = $this->manager();
    $this->assertSame('elevated', $m->classifyTrustLevel('Trusted'));
    $this->assertSame('standard', $m->classifyTrustLevel('normal'));
    $this->assertSame('limited', $m->classifyTrustLevel('kiosk'));
    $this->assertSame('unknown', $m->classifyTrustLevel(''));
    $this->assertSame('unknown', $m->classifyTrustLevel('mystery'));
  }

  public function testMergePrefersMelOperationalDeviceOverLegacyKey(): void {
    $m = $this->manager();
    $merged = $m->mergeOperationalContextLayers(
      ['operational_device' => ['checkpoint_id' => 'legacy_cp', 'device_id' => 'a']],
      ['mel_operational_device' => ['checkpoint_id' => 'canon_cp', 'gate_id' => 'g1']],
    );
    $norm = $m->normalizeOperationalIdentity($merged, 'fallback');
    $this->assertSame('canon_cp', $norm['checkpoint_id']);
    $this->assertSame('g1', $norm['gate_id']);
    $this->assertSame('a', $norm['device_id']);
  }

  public function testNormalizeOfflineModeAndReconciliationGroup(): void {
    $m = $this->manager();
    $n = $m->normalizeOperationalIdentity([
      'mel_operational_device' => [
        'offline_mode' => 'offline',
        'reconciliation_group' => 'batch-99',
      ],
    ], 'd1');
    $this->assertTrue($n['offline_mode']);
    $this->assertSame('batch-99', $n['reconciliation_group']);
  }

  public function testFingerprintDeterministic(): void {
    $m = $this->manager();
    $a = $m->normalizeOperationalIdentity(['mel_operational_device' => [
      'device_id' => 'd',
      'gate_id' => 'g',
      'checkpoint_id' => 'c',
      'reconciliation_group' => 'r',
      'venue_id' => 'v',
    ]], '');
    $b = $m->normalizeOperationalIdentity(['operational_device' => [
      'device_id' => 'd',
      'gate_id' => 'g',
      'checkpoint_id' => 'c',
      'reconciliation_group' => 'r',
      'venue_id' => 'v',
    ]], '');
    $this->assertSame($m->buildReplaySafeDeviceFingerprint($a), $m->buildReplaySafeDeviceFingerprint($b));
  }

  public function testPublicSummaryOmitsOperatorAndMatchesTrustCategory(): void {
    $m = $this->manager();
    $norm = $m->normalizeOperationalIdentity([
      'mel_operational_device' => [
        'device_id' => 'dev',
        'operator_id' => 'staff-42',
        'operator_role' => 'door',
        'trust_level' => 'high',
        'checkpoint_id' => 'north-gate',
        'scan_mode' => 'admit',
      ],
    ], '');
    $pub = $m->buildPublicOperationalIdentitySummary($norm);
    $encoded = json_encode($pub) ?: '';
    $this->assertStringNotContainsString('staff-42', $encoded);
    $this->assertStringNotContainsString('door', $encoded);
    $this->assertSame('verified_partner', $pub['trust_category']);
    $this->assertSame('north-gate', $pub['checkpoint']);
  }

  public function testCustomerVisibleSliceFromTicketOmitsOperator(): void {
    $user = User::create(['name' => 'u1', 'mail' => 'u1@example.test', 'status' => 1]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'E',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $event->save();
    $ticket = Ticket::create([
      'ticket_code' => 'MEL-DEV-ID-1',
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'metadata_json' => [
        'mel_operational_device' => [
          'checkpoint_id' => 'vip-lane',
          'trust_level' => 'standard',
          'operator_id' => 'secret-op',
        ],
      ],
    ]);
    $ticket->save();
    $slice = $this->manager()->buildCustomerVisibleOperationalIdentityFromTicket($ticket);
    $this->assertArrayNotHasKey('operator_id', $slice);
    $encoded = json_encode($slice) ?: '';
    $this->assertStringNotContainsString('secret-op', $encoded);
  }

  private function manager(): DeviceOperationIdentityManager {
    return $this->container->get('myeventlane_tickets.device_operation_identity_manager');
  }

}
