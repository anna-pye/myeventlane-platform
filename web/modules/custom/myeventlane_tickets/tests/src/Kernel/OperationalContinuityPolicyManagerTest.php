<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OperationalContinuityPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalContinuityPolicyManager
 *
 * @group myeventlane_tickets
 */
#[Group('continuity')]
#[RunTestsInSeparateProcesses]
final class OperationalContinuityPolicyManagerTest extends KernelTestBase {

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

  public function testNormalizeReconciliationMetadataSupportsCanonicalAndLegacyKeys(): void {
    $raw = [
      'mel_operational_continuity' => [
        'continuity_epoch' => '3',
        'offline_sequence' => 2,
        'sync_hint' => 'BATCH',
      ],
      'operational_continuity' => [
        'reconciliation_group' => 'grp-a',
        'conflict_strategy' => 'reject_duplicate',
      ],
    ];
    $n = $this->continuity()->normalizeReconciliationMetadata($raw);
    $this->assertSame(3, $n['continuity_epoch']);
    $this->assertSame(2, $n['offline_sequence']);
    $this->assertSame('batch_upload', $n['sync_hint']);
    $this->assertSame('grp-a', $n['reconciliation_group']);
    $this->assertSame('reject_duplicate', $n['conflict_strategy']);
  }

  public function testDeterministicReconciliationFingerprintsStable(): void {
    $c = $this->continuity();
    $norm = $c->normalizeReconciliationMetadata([
      'continuity_epoch' => 1,
      'offline_sequence' => 0,
      'reconciliation_group' => 'g',
    ]);
    $a = $c->buildDeterministicReconciliationFingerprints(99, $norm, 'topo-x', 'g');
    $b = $c->buildDeterministicReconciliationFingerprints(99, $norm, 'topo-x', 'g');
    $this->assertSame($a['reconciliation_fingerprint'], $b['reconciliation_fingerprint']);
    $this->assertStringStartsWith('mel_cont_', (string) $a['continuity_descriptor_token']);
  }

  public function testCustomerProjectionOmitsFingerprints(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_TICKET, [
      'metadata_json' => [
        'mel_operational_continuity' => [
          'sync_hint' => 'batch_upload',
          'continuity_mode' => 'live',
        ],
      ],
    ]);
    $proj = $this->continuity()->buildCustomerSafeContinuityProjection($ticket);
    $json = json_encode($proj) ?: '';
    $this->assertStringNotContainsString('reconciliation_fingerprint', $json);
    $this->assertStringNotContainsString('mel_cont_', $json);
    $this->assertArrayHasKey('offline_capable', $proj);
    $this->assertArrayHasKey('continuity_mode', $proj);
    $this->assertArrayHasKey('reconciliation_hint', $proj);
  }

  public function testNormalizeReplayContinuitySemanticsUsesPolicySnapshot(): void {
    $ticket = $this->createTicket(Ticket::ENTITLEMENT_TICKET);
    $now = time();
    $timed = $this->container->get('myeventlane_tickets.timed_entry_policy_manager')->evaluate($ticket, $now, NULL);
    $session = $this->container->get('myeventlane_tickets.session_entitlement_policy_manager')
      ->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $replay = $this->continuity()->normalizeReplayContinuitySemantics(
      $ticket,
      $now,
      $now + 120,
      ['timed_entry' => $timed, 'session_entitlement' => $session],
    );
    $this->assertIsArray($replay['policy_replay_alignment']);
    $this->assertArrayHasKey('replay_window_seconds_hint', $replay);
  }

  private function continuity(): OperationalContinuityPolicyManager {
    return $this->container->get('myeventlane_tickets.operational_continuity_policy_manager');
  }

  private function createTicket(string $entitlement_type, array $overrides = []): Ticket {
    $user = User::create([
      'name' => 'continuity_' . uniqid('', TRUE),
      'mail' => uniqid('u_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Continuity event',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $event->save();

    $values = array_replace([
      'ticket_code' => 'MEL-OC-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 10),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => $entitlement_type,
      'redemption_limit' => 5,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

}
