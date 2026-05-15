<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\myeventlane_event_studio\Unit\OperationalCapabilityStudioTestFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Contract tests for commerce linkage metadata on operational capabilities.
 *
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
final class EventStudioOperationalCommerceCapabilityKernelTest extends KernelTestBase {

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

  public function testNormalizedDocumentIncludesCommerceLinkageContract(): void {
    $manager = OperationalCapabilityStudioTestFactory::buildManager();
    $event = Node::create(['type' => 'event', 'title' => 'Commerce contract']);
    $event->save();

    $doc = $manager->emptyDocument();
    $normalized = $manager->normalizeDocument($doc, $event);
    $row = $normalized['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP];
    $this->assertArrayHasKey('commerce_linkage', $row);
    $this->assertArrayHasKey('product_id', $row['commerce_linkage']);
    $this->assertArrayHasKey('variation_ids', $row['commerce_linkage']);
    $this->assertArrayHasKey('store_id', $row['commerce_linkage']);
    $this->assertArrayHasKey('linkage_mode', $row['commerce_linkage']);
    $this->assertArrayHasKey('fulfillment_reference', $row['commerce_linkage']);
    $this->assertArrayHasKey('reservation_reference', $row['commerce_linkage']);
    $this->assertArrayHasKey('readiness_projection', $row['commerce_linkage']);
    $this->assertArrayHasKey('customer_visibility', $row['commerce_linkage']);
    $this->assertArrayHasKey('operational_preview', $row['commerce_linkage']);
    $this->assertArrayHasKey('capability_binding_state', $row['commerce_linkage']);
  }

}
