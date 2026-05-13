<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\TimedEntryPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\TimedEntryPolicyManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TimedEntryPolicyManagerTest extends KernelTestBase {

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
    $this->createEventFields();
  }

  public function testLegacyUnrestrictedWhenNoWindows(): void {
    $ticket = $this->createTicket([]);
    $policy = $this->timed()->evaluate($ticket, time(), NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
    $this->assertSame('allowed', $policy['scanner']['state']);
    $this->assertSame('legacy_unrestricted', $policy['scanner']['reason']);
  }

  public function testAbsoluteWindowBlocksBeforeOpen(): void {
    $opens = time() + 7200;
    $closes = time() + 14400;
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => FALSE,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, time(), NULL);
    $this->assertFalse($policy['scanner']['allowed_now']);
    $this->assertSame('not_started', $policy['scanner']['state']);
  }

  public function testDetectTimingConflictsForInvertedBounds(): void {
    $ticket = $this->createTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => time() + 5000,
          'entry_closes_at' => time() + 1000,
        ],
      ],
    ]);
    $conflicts = $this->timed()->detectTimingConflicts($ticket, time(), NULL);
    $this->assertContains('conflicting_entry_bounds', $conflicts);
  }

  private function timed(): TimedEntryPolicyManager {
    return $this->container->get('myeventlane_tickets.timed_entry_policy_manager');
  }

  /**
   * @param array<string, mixed> $overrides
   */
  private function createTicket(array $overrides): Ticket {
    $user = User::create([
      'name' => 'timed_' . uniqid('', TRUE),
      'mail' => uniqid('u_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Timed entry event',
      'uid' => $user->id(),
      'status' => 1,
      'field_event_start' => gmdate('Y-m-d\TH:i:s', time() - 3600),
      'field_event_end' => gmdate('Y-m-d\TH:i:s', time() + 7200),
    ]);
    $event->save();

    $values = array_replace([
      'ticket_code' => 'MEL-TIMED-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  private function createEventFields(): void {
    foreach ([
      'field_event_start' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
      'field_event_end' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
    ] as $field_name => $definition) {
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
