<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Unit;

use Drupal\myeventlane_account\Service\CustomerHubDataBuilder;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_account\Service\CustomerHubDataBuilder
 *
 * @group myeventlane_account
 */
final class CustomerHubBookingPresentationTest extends UnitTestCase {

  private function builder(): CustomerHubDataBuilder {
    $entityTypeManager = $this->createMock('Drupal\Core\Entity\EntityTypeManagerInterface');
    $readiness = new MelReadinessHelper($this->getStringTranslationStub());
    return new CustomerHubDataBuilder($entityTypeManager, $readiness);
  }

  /**
   * @covers ::enrichBookingPresentation
   */
  public function testEnrichAddsTodayStatusAndBookingCtas(): void {
    $now = strtotime('2026-07-13 12:00:00');
    $row = [
      'id' => 42,
      'title' => 'Test event',
      'url' => '/event/42',
      'ics_url' => '/event/42/ics',
      'start_timestamp' => strtotime('2026-07-13 18:00:00'),
      'end_timestamp' => strtotime('2026-07-13 20:00:00'),
      'source' => 'ticket',
      'has_ticket_code' => TRUE,
      'pdf_available' => TRUE,
      'ticket_url' => '/my-tickets/order/9',
      'pdf_url' => '/ticket/ABC/pdf',
    ];

    $enriched = $this->builder()->enrichBookingPresentation($row, 'upcoming', $now, 7);
    $this->assertSame('today', $enriched['status_key']);
    $this->assertSame('Today', $enriched['status_label']);
    $this->assertSame('View booking', $enriched['primary_cta']['label']);
    $this->assertSame('/my-tickets/order/9', $enriched['primary_cta']['url']);
    $this->assertSame('View ticket', $enriched['secondary_cta']['label']);
  }

  /**
   * @covers ::enrichBookingPresentation
   */
  public function testPastBookingUsesCompletedStatus(): void {
    $now = time();
    $row = [
      'id' => 1,
      'url' => '/event/1',
      'source' => 'ticket',
      'start_timestamp' => $now - 86400,
      'end_timestamp' => $now - 3600,
    ];
    $enriched = $this->builder()->enrichBookingPresentation($row, 'past', $now);
    $this->assertSame('completed', $enriched['status_key']);
    $this->assertSame('Completed', $enriched['status_label']);
    $this->assertSame('View event', $enriched['primary_cta']['label']);
  }

}
