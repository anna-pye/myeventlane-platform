<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\myeventlane_event_studio\Unit\OperationalCapabilityStudioTestFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\myeventlane_event_studio\Unit\TestLoggerChannel;

/**
 * Kernel tests for operational capability authoring storage and autosave safety.
 *
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
#[RunTestsInSeparateProcesses]
final class EventStudioOperationalCapabilityKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_mel_op_capabilities',
      'entity_type' => 'node',
      'type' => 'string_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_mel_op_capabilities',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Operational capabilities',
    ])->save();
  }

  public function testPersistAndExtractRoundTrip(): void {
    $manager = OperationalCapabilityStudioTestFactory::buildManager();
    $event = Node::create(['type' => 'event', 'title' => 'Ops cap']);
    $event->save();

    $document = $manager->emptyDocument();
    $document['capabilities'][OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION]['enabled'] = TRUE;
    $document['capabilities'][OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION]['customer_visibility'] = 'visible';
    $manager->persistToEvent($event, $document);
    $event->save();

    $reloaded = Node::load($event->id());
    $this->assertInstanceOf(Node::class, $reloaded);
    $stored = $manager->extractFromEvent($reloaded);
    $this->assertTrue($stored['capabilities'][OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION]['enabled']);
    $this->assertStringNotContainsString('replay_token', (string) $reloaded->get('field_mel_op_capabilities')->value);
  }

  public function testAutosaveDraftDoesNotWriteLiveField(): void {
    $manager = OperationalCapabilityStudioTestFactory::buildManager();
    $event = Node::create(['type' => 'event', 'title' => 'Draft safety']);
    $event->save();

    $live = $manager->emptyDocument();
    $live['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP]['enabled'] = TRUE;
    $manager->persistToEvent($event, $live);
    $event->save();

    $autosave = new EventStudioAutosaveService(
      $this->container->get('tempstore.private'),
      new TestLoggerChannel(),
    );
    $autosave->storeDraft($event, 'fulfilment', [
      'operational_capabilities' => [
        'items_state' => json_encode([
          'capabilities' => [
            OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => ['enabled' => FALSE],
          ],
        ], JSON_THROW_ON_ERROR),
      ],
    ], 1.0, (int) $event->getChangedTime(), (int) $event->getRevisionId());

    $reloaded = Node::load($event->id());
    $this->assertInstanceOf(Node::class, $reloaded);
    $stored = $manager->extractFromEvent($reloaded);
    $this->assertTrue($stored['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP]['enabled']);
  }

}
