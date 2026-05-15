<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionManager;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionManager
 *
 * @group myeventlane_tickets
 */
final class OperationalFulfillmentExecutionManagerTest extends UnitTestCase {

  private function manager(): OperationalFulfillmentExecutionManager {
    $registry = new EntitlementCapabilityRegistry();
    $lifecycle = new FulfillmentLifecycleManager($registry);
    return new OperationalFulfillmentExecutionManager($lifecycle, $this->getStringTranslationStub());
  }

  public function testComposeExecutionBundleContractAndSanitization(): void {
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
    $out = $this->manager()->composeExecutionBundleFromMerged($merged, 1000);
    $this->assertTrue($out[OperationalFulfillmentExecutionManager::CONTRACT_FLAG]);
    $this->assertFalse($out['empty']);
    $this->assertNotEmpty($out['executions']);
    $first = $out['executions'][0];
    $this->assertArrayHasKey('execution_id', $first);
    $this->assertArrayHasKey('execution_type', $first);
    $this->assertArrayNotHasKey('qr_payload', $first);
  }

  public function testSanitizeExecutionDocumentStripsForbiddenKeys(): void {
    $dirty = [
      'mel_operational_fulfillment_execution' => TRUE,
      'qr_payload' => 'x',
      'nested' => ['replay_token' => 'y', 'ok' => 'z'],
    ];
    $clean = $this->manager()->sanitizeExecutionDocument($dirty);
    $this->assertArrayNotHasKey('qr_payload', $clean);
    $this->assertArrayNotHasKey('replay_token', $clean['nested']);
    $this->assertSame('z', $clean['nested']['ok']);
  }

  public function testExecutionBundleOmitsScannerActionTokens(): void {
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
    $out = $this->manager()->composeExecutionBundleFromMerged($merged, 500);
    $first = $out['executions'][0];
    $this->assertStringNotContainsString('scanner_action', (string) ($first['operational_summary'] ?? ''));
    $this->assertStringNotContainsString('scanner_action', (string) ($first['redemption_state'] ?? ''));
  }

}
