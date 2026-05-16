<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel smoke test for fulfillment execution orchestration services.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalFulfillmentExecutionKernelTest extends KernelTestBase {

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
    $this->installConfig(['user']);
  }

  public function testExecutionManagerServiceBuildsContract(): void {
    /** @var \Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionManager $manager */
    $manager = $this->container->get('myeventlane_tickets.operational_fulfillment_execution_manager');
    $this->assertInstanceOf(OperationalFulfillmentExecutionManager::class, $manager);
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
      'artifacts' => ['canonical_pdf_readiness' => 'valid'],
      'fulfillment_signals' => [
        'by_ticket_id' => [
          '10' => [
            'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
            'fulfilment_status' => Ticket::FULFILMENT_PENDING,
            'redemption_count' => 0,
            'redemption_limit' => 1,
            'ticket_status' => Ticket::STATUS_ASSIGNED,
            'admission_checked_in' => FALSE,
          ],
        ],
      ],
    ];
    $bundle = $manager->composeExecutionBundleFromMerged($merged, 100);
    $this->assertTrue($bundle[OperationalFulfillmentExecutionManager::CONTRACT_FLAG]);
    $this->assertNotEmpty($bundle['executions']);
  }

}
