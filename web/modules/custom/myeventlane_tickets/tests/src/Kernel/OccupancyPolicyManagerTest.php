<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OccupancyPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OccupancyPolicyManager
 *
 * @group myeventlane_tickets
 * @group occupancy
 */
#[RunTestsInSeparateProcesses]
final class OccupancyPolicyManagerTest extends KernelTestBase {

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

  public function testNormalizeOccupancyPoliciesSupportsLegacyKey(): void {
    $norm = $this->manager()->normalizeOccupancyPolicies([
      'operational_occupancy' => [
        'occupancy_mode' => 'policy_only',
        'anti_passback_mode' => 'soft',
        'reentry_policy' => 'session_governed',
        'directional_mode' => 'entry',
        'entry_zone' => 'gate_a',
        'balancing_strategy' => 'entry_exit_balance',
      ],
    ]);
    $this->assertSame('policy_only', $norm['occupancy_mode']);
    $this->assertSame('soft', $norm['anti_passback_mode']);
    $this->assertSame('session_governed', $norm['reentry_policy']);
    $this->assertSame('entry', $norm['directional_mode']);
    $this->assertSame('gate_a', $norm['entry_zone']);
    $this->assertSame('entry_exit_balance', $norm['balancing_strategy']);
  }

  public function testDirectionalEntryMismatchDenies(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_occupancy' => [
          'directional_mode' => 'entry',
          'entry_zone' => 'main_gate',
        ],
      ],
    ]);
    $now = time();
    $timed = $this->container->get('myeventlane_tickets.timed_entry_policy_manager')->evaluate($ticket, $now, NULL);
    $session = $this->container->get('myeventlane_tickets.session_entitlement_policy_manager')
      ->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $policy = [
      'timed_entry' => $timed,
      'session_entitlement' => $session,
      'zone_access' => [],
    ];
    $out = $this->manager()->evaluateOccupancyForScan(
      $ticket,
      $now,
      NULL,
      [],
      'lobby',
      $policy,
    );
    $this->assertFalse($out['allow']);
    $this->assertSame('invalid', $out['result_token']);
  }

  public function testDirectionalEntryMatchAllows(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_occupancy' => [
          'directional_mode' => 'entry',
          'entry_zone' => 'main_gate',
        ],
      ],
    ]);
    $now = time();
    $timed = $this->container->get('myeventlane_tickets.timed_entry_policy_manager')->evaluate($ticket, $now, NULL);
    $session = $this->container->get('myeventlane_tickets.session_entitlement_policy_manager')
      ->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $policy = [
      'timed_entry' => $timed,
      'session_entitlement' => $session,
      'zone_access' => [],
    ];
    $out = $this->manager()->evaluateOccupancyForScan(
      $ticket,
      $now,
      NULL,
      [],
      'main_gate',
      $policy,
    );
    $this->assertTrue($out['allow']);
    $this->assertSame('', $out['result_token']);
  }

  public function testCustomerSummaryOmitsBaselineMetadata(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_occupancy' => [
          'occupancy_mode' => 'inherit_venue',
        ],
      ],
    ]);
    $this->assertSame([], $this->manager()->buildCustomerSafeOccupancySummary($ticket));
  }

  public function testCustomerSummaryExposesSafeFields(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_occupancy' => [
          'occupancy_mode' => 'live_estimate',
          'reentry_policy' => 'allow',
          'directional_mode' => 'bidirectional',
        ],
      ],
    ]);
    $s = $this->manager()->buildCustomerSafeOccupancySummary($ticket);
    $this->assertSame('live_estimate', $s['occupancy_mode']);
    $this->assertSame('allow', $s['reentry_policy']);
    $this->assertSame('bidirectional', $s['directional_mode']);
    $enc = json_encode($s) ?: '';
    $this->assertStringNotContainsString('anti_passback', $enc);
  }

  private function manager(): OccupancyPolicyManager {
    return $this->container->get('myeventlane_tickets.occupancy_policy_manager');
  }

  /**
   * @param array<string, mixed> $overrides
   */
  private function createTicket(array $overrides = []): Ticket {
    $user = User::create([
      'name' => 'occ_' . uniqid('', TRUE),
      'mail' => uniqid('o_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Occ event',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $event->save();

    $values = array_replace([
      'ticket_code' => 'MEL-OCC-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 10),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'redemption_limit' => 1,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

}
