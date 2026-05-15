<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\Component\Datetime\Time;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionManager;
use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionProjectionBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionProjectionBuilder
 *
 * @group myeventlane_tickets
 */
final class OperationalFulfillmentExecutionProjectionBuilderTest extends UnitTestCase {

  public function testWorkspaceSectionsContainCanonicalIds(): void {
    $registry = new EntitlementCapabilityRegistry();
    $lifecycle = new FulfillmentLifecycleManager($registry);
    $manager = new OperationalFulfillmentExecutionManager($lifecycle, $this->getStringTranslationStub());
    $gov = new OperationalFulfillmentExecutionGovernanceManager($this->getStringTranslationStub());
    $time = new Time();
    $builder = new OperationalFulfillmentExecutionProjectionBuilder(
      NULL,
      $manager,
      $gov,
      $time,
      $this->getStringTranslationStub(),
    );
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
    $sections = $builder->buildWorkspaceExecutionGovernanceSections($merged, 500);
    $ids = array_column($sections, 'id');
    $this->assertContains('execution_governance_summary', $ids);
    $this->assertContains('fulfillment_execution_cards', $ids);
    $this->assertContains('operational_collection_visibility', $ids);
    $this->assertContains('execution_readiness_visibility', $ids);
  }

}
