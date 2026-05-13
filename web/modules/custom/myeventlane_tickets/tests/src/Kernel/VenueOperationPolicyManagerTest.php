<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\VenueOperationPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\VenueOperationPolicyManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class VenueOperationPolicyManagerTest extends KernelTestBase {

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
  }

  public function testCanonicalVocabularyIncludesRequiredTokens(): void {
    $vocab = $this->policy()->canonicalOperationVocabulary();
    foreach ([
      'admit',
      'redeem',
      'collect',
      'validate',
      'verify',
      'revalidate',
      'expired',
      'duplicate',
      'already_used',
      'deny',
    ] as $token) {
      $this->assertContains($token, $vocab);
    }
  }

  public function testNormalizeScannerResultToken(): void {
    $p = $this->policy();
    $this->assertSame('admit', $p->normalizeScannerResultToken('admitted'));
    $this->assertSame('redeem', $p->normalizeScannerResultToken('redeemed'));
    $this->assertSame('collect', $p->normalizeScannerResultToken('collected'));
    $this->assertSame('validate', $p->normalizeScannerResultToken('validated'));
    $this->assertSame('verify', $p->normalizeScannerResultToken('verified'));
    $this->assertSame('expired', $p->normalizeScannerResultToken('expired'));
    $this->assertSame('already_used', $p->normalizeScannerResultToken('already_checked_in'));
    $this->assertSame('already_used', $p->normalizeScannerResultToken('redemption_limit_reached'));
    $this->assertSame('deny', $p->normalizeScannerResultToken('invalid'));
  }

  public function testDuplicateReplayDetectionSemantics(): void {
    $p = $this->policy();
    $this->assertTrue($p->isProbableDuplicateReplay('abc', 'abc'));
    $this->assertFalse($p->isProbableDuplicateReplay('abc', 'def'));
    $this->assertFalse($p->isProbableDuplicateReplay('', 'abc'));
  }

  public function testOfflineEligibilityDescriptor(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_DRINK);
    $descriptor = $this->policy()->buildOperationDescriptor($ticket, 'offline');
    $this->assertTrue($descriptor['offline_eligible']);
    $this->assertFalse($descriptor['requires_online_validation']);
    $this->assertTrue($descriptor['replay_protected']);
  }

  public function testGateSemanticsMerchParkingDrinkVip(): void {
    $p = $this->policy();
    $merch = $p->describeEntitlementGateSemantics(Ticket::ENTITLEMENT_MERCH);
    $this->assertSame('merch_collection', $merch['gate_family']);
    $this->assertSame('collect', $merch['gate_action']);

    $parking = $p->describeEntitlementGateSemantics(Ticket::ENTITLEMENT_PARKING);
    $this->assertSame('parking_validation', $parking['gate_family']);
    $this->assertTrue($parking['idempotent_validate_family']);
    $this->assertSame('revalidate', $parking['repeat_scan_semantic']);

    $drink = $p->describeEntitlementGateSemantics(Ticket::ENTITLEMENT_DRINK);
    $this->assertSame('consumption_redemption', $drink['gate_family']);
    $this->assertTrue($drink['multi_use']);

    $vip = $p->describeEntitlementGateSemantics(Ticket::ENTITLEMENT_VIP);
    $this->assertSame('vip_verification', $vip['gate_family']);
    $this->assertSame('verify', $vip['gate_action']);
  }

  public function testMultiUseDescriptorMatchesRegistry(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_FOOD);
    $descriptor = $this->policy()->buildOperationDescriptor($ticket, 'online');
    $this->assertTrue($descriptor['multi_use']);
    $this->assertSame('allow_multi_use_until_limit', $descriptor['conflict_policy']);
  }

  public function testAdmissionConflictPolicy(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_TICKET);
    $descriptor = $this->policy()->buildOperationDescriptor($ticket, 'online');
    $this->assertSame('reject_duplicate', $descriptor['conflict_policy']);
    $this->assertSame('admit', $descriptor['operation_type']);
  }

  public function testVipVerifyConflictPolicyAllowsIdempotentRescan(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_VIP);
    $descriptor = $this->policy()->buildOperationDescriptor($ticket, 'online');
    $this->assertSame('allow_idempotent_rescan', $descriptor['conflict_policy']);
    $this->assertSame('verify', $descriptor['operation_type']);
  }

  public function testIntegrityEnvelopeDeterministicIds(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_MERCH);
    $envelope = $this->policy()->buildIntegrityEnvelope(
      $ticket,
      'collect',
      TRUE,
      'collected',
      'device-a',
      1700000000,
      0,
      0,
      hash('sha256', 'payload'),
    );
    $this->assertStringStartsWith('op_', (string) $envelope['operation_id']);
    $this->assertSame(64, strlen((string) $envelope['replay_token']));
    $this->assertSame('collect', (string) $envelope['normalized_result']);

    $envelope2 = $this->policy()->buildIntegrityEnvelope(
      $ticket,
      'collect',
      TRUE,
      'collected',
      'device-a',
      1700000000,
      0,
      0,
      hash('sha256', 'payload'),
    );
    $this->assertSame($envelope['operation_id'], $envelope2['operation_id']);
  }

  public function testInterpretDenialAdmissionReplay(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_TICKET);
    $denial = $this->policy()->interpretDenial($ticket, 'already_checked_in');
    $this->assertSame('already_used', $denial['normalized_operation']);
    $this->assertSame('admission_replay', $denial['conflict']);
  }

  public function testResolveGateActionDelegatesToRegistry(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_PARKING);
    $this->assertSame('validate', $this->policy()->resolveGateAction($ticket));
  }

  public function testEvaluateTimedEntryForScanAllowsLegacyAdmission(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_TICKET);
    $gate = $this->policy()->evaluateTimedEntryForScan($ticket, time(), NULL);
    $this->assertTrue($gate['allow']);
    $this->assertArrayHasKey('policy', $gate);
    $this->assertArrayHasKey('timed_entry', $gate['policy']);
    $this->assertArrayHasKey('session_entitlement', $gate['policy']);
  }

  private function policy(): VenueOperationPolicyManager {
    return $this->container->get('myeventlane_tickets.venue_operation_policy_manager');
  }

  private function createTicket(string $entitlement_type): Ticket {
    $user = User::create([
      'name' => 'venue_policy_' . uniqid('', TRUE),
      'mail' => uniqid('u_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Venue policy event',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $event->save();

    $ticket = Ticket::create([
      'ticket_code' => 'MEL-VENUE-POLICY-1',
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => $entitlement_type,
      'redemption_limit' => 5,
    ]);
    $ticket->save();
    return $ticket;
  }

}
