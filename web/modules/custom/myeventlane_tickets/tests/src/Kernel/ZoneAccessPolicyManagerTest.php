<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\ZoneAccessPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\ZoneAccessPolicyManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class ZoneAccessPolicyManagerTest extends KernelTestBase {

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

  public function testNormalizesMelOperationalZonesContainer(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_zones' => [
          'zones' => ['north', 'south'],
          'allowed_zones' => ['north'],
          'denied_zones' => ['service'],
          'progression_order' => ['north', 'south'],
          'gate_groups' => [
            'gate_a' => 'north',
            'gate_b' => ['south'],
          ],
          'topology_hints' => ['vip_wing'],
          'reentry_allowed' => FALSE,
        ],
      ],
    ]);
    $norm = $this->zones()->normalizeOperationalZonesMetadata($ticket);
    $this->assertSame('mel_operational_zones', $norm['source']);
    $this->assertSame(['north'], $norm['allowed_zones']);
    $this->assertSame(['service'], $norm['denied_zones']);
    $this->assertSame(['north', 'south'], $norm['progression_order']);
    $this->assertSame(['vip_wing'], $norm['topology_hints']);
    $this->assertFalse($norm['reentry_allowed_default']);
    $this->assertSame('north', $norm['gate_groups']['gate_a']);
  }

  public function testOperationalZonesAlias(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'operational_zones' => [
          'allowed_zones' => ['hall'],
        ],
      ],
    ]);
    $norm = $this->zones()->normalizeOperationalZonesMetadata($ticket);
    $this->assertSame('operational_zones', $norm['source']);
    $this->assertSame(['hall'], $norm['allowed_zones']);
  }

  public function testDeniedZoneBlocksWhenGateSpecified(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 3,
      'metadata_json' => [
        'mel_operational_zones' => [
          'denied_zones' => ['backstage'],
        ],
      ],
    ]);
    $now = time();
    $timed = $this->container->get('myeventlane_tickets.timed_entry_policy_manager')->evaluate($ticket, $now, NULL);
    $session = $this->container->get('myeventlane_tickets.session_entitlement_policy_manager')
      ->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $eval = $this->zones()->evaluateZoneAccessForComposition($ticket, 'backstage', $timed, $session);
    $this->assertFalse($eval['allow']);
    $this->assertSame('invalid', $eval['result_token']);
    $this->assertArrayHasKey('denial', $eval['policy']);
  }

  public function testProgressionEnforcesOrder(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 5,
      'redemption_count' => 0,
      'metadata_json' => [
        'mel_operational_zones' => [
          'progression_order' => ['z1', 'z2'],
        ],
      ],
    ]);
    $now = time();
    $timed = $this->container->get('myeventlane_tickets.timed_entry_policy_manager')->evaluate($ticket, $now, NULL);
    $session = $this->container->get('myeventlane_tickets.session_entitlement_policy_manager')
      ->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $wrong = $this->zones()->evaluateZoneAccessForComposition($ticket, 'z2', $timed, $session);
    $this->assertFalse($wrong['allow']);
    $this->assertSame('invalid', $wrong['result_token']);

    $ok = $this->zones()->evaluateZoneAccessForComposition($ticket, 'z1', $timed, $session);
    $this->assertTrue($ok['allow']);
  }

  public function testStructuralOverlapConflict(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_zones' => [
          'allowed_zones' => ['dup'],
          'denied_zones' => ['dup'],
        ],
      ],
    ]);
    $conflicts = $this->zones()->detectStructuralZoneConflicts($ticket);
    $this->assertContains('zone_allowed_denied_overlap', $conflicts);
  }

  public function testSummarizeZoneInspectionOmitsReplaySecrets(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_zones' => [
          'allowed_zones' => ['main'],
        ],
      ],
    ]);
    $summary = $this->zones()->summarizeZoneInspection($ticket);
    $encoded = json_encode($summary) ?: '';
    $this->assertStringNotContainsString('replay_token', $encoded);
    $this->assertStringNotContainsString('hmac', strtolower($encoded));
    $this->assertSame('zone_allowed_list', $summary['conflict_policy']);
  }

  private function zones(): ZoneAccessPolicyManager {
    return $this->container->get('myeventlane_tickets.zone_access_policy_manager');
  }

  /**
   * @param array<string, mixed> $overrides
   */
  private function createTicket(array $overrides = []): Ticket {
    $user = User::create([
      'name' => 'zone_policy_' . uniqid('', TRUE),
      'mail' => uniqid('u_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Zone policy event',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $event->save();

    $values = array_replace([
      'ticket_code' => 'MEL-ZONE-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

}
