<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_event_studio\Service\CustomerOperationalCommerceExperienceBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommercePreviewBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\myeventlane_event_studio\Unit\OperationalCapabilityStudioTestFactory;
use Drupal\Tests\myeventlane_event_studio\Unit\TestLoggerChannel;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel coverage for customer operational experience against stored event nodes.
 *
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
final class CustomerOperationalCommerceKernelTest extends KernelTestBase {

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

  public function testBuildForPersistedEventUsesEntityTypeManagerReload(): void {
    $studio = OperationalCapabilityStudioTestFactory::buildManager();
    $event = Node::create(['type' => 'event', 'title' => 'Customer ops kernel']);
    $event->save();

    $doc = $studio->emptyDocument();
    $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP] = array_merge(
      $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP],
      [
        'enabled' => TRUE,
        'customer_visibility' => 'after_purchase',
        'readiness_state' => \Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager::READINESS_CONFIGURED,
      ],
    );
    $studio->persistToEvent($event, $doc);
    $event->save();

    $registry = new EntitlementCapabilityRegistry();
    $logger = new TestLoggerChannel();
    $reservation = new InventoryReservationGovernanceManager($registry, $logger);
    $oecm = new OperationalEntitlementCapabilityManager($registry, $reservation, $logger);
    $fulfillment = new FulfillmentLifecycleManager($registry);
    $etm = $this->container->get('entity_type.manager');
    $currentUser = $this->container->get('current_user');
    $link = new OperationalCapabilityCommerceLinkManager($etm, $currentUser, $registry, $oecm, $fulfillment, $reservation);
    $commercePreview = new OperationalCapabilityCommercePreviewBuilder($link, $etm, $this->container->get('string_translation'));
    $preview = new OperationalCapabilityPreviewBuilder($studio, $commercePreview, $this->container->get('string_translation'));

    $builder = new CustomerOperationalCommerceExperienceBuilder(
      $link,
      $preview,
      $studio,
      $registry,
      $fulfillment,
      $reservation,
      $etm,
      $this->container->get('string_translation'),
    );

    $reloaded = Node::load($event->id());
    $this->assertInstanceOf(Node::class, $reloaded);
    $out = $builder->buildCustomerOperationalExperienceForEvent($reloaded);
    $this->assertTrue($out['customer_operational_experience']);
    $this->assertFalse($out['empty']);
    $this->assertNotEmpty($out['items']);
  }

}
