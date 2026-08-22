<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\DirectChargeOperationalEventHandler;
use PHPUnit\Framework\TestCase;
use Stripe\Event;

require_once dirname(__DIR__, 3) . '/src/Service/DirectChargeOperationalEventHandler.php';

/**
 * Protects critical Connect event classification and restriction transitions.
 *
 * @group myeventlane_commerce
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\DirectChargeOperationalEventHandler
 */
final class DirectChargeOperationalEventHandlerTest extends TestCase {

  /**
   * @covers ::templateForEventType
   */
  public function testApprovedEventTypesMapToDedicatedTemplates(): void {
    self::assertSame(
      'stripe_dispute_created_vendor',
      DirectChargeOperationalEventHandler::templateForEventType(Event::CHARGE_DISPUTE_CREATED),
    );
    self::assertSame(
      'stripe_account_restricted_vendor',
      DirectChargeOperationalEventHandler::templateForEventType(Event::ACCOUNT_UPDATED),
    );
    self::assertSame(
      'stripe_payout_failed_vendor',
      DirectChargeOperationalEventHandler::templateForEventType(Event::PAYOUT_FAILED),
    );
    self::assertNotContains(Event::PAYOUT_PAID, DirectChargeOperationalEventHandler::SUPPORTED_EVENTS);
  }

  /**
   * @covers ::requireQueuedMessageId
   */
  public function testAcceptedMessageIdIsReturned(): void {
    self::assertSame(
      'message-uuid',
      DirectChargeOperationalEventHandler::requireQueuedMessageId('message-uuid', 'evt_test'),
    );
  }

  /**
   * @covers ::requireQueuedMessageId
   */
  public function testQueueFailureThrowsSoTheWebhookCanRetry(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('could not be queued for event evt_retry');
    DirectChargeOperationalEventHandler::requireQueuedMessageId(NULL, 'evt_retry');
  }

  /**
   * @covers ::accountBecameRestricted
   * @dataProvider restrictionTransitions
   */
  public function testRestrictionTransitionDetection(array $object, array $previous, bool $expected): void {
    self::assertSame(
      $expected,
      DirectChargeOperationalEventHandler::accountBecameRestricted($this->accountUpdatedEvent($object, $previous)),
    );
  }

  /**
   * @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}>
   */
  public static function restrictionTransitions(): iterable {
    yield 'charges disabled after being enabled' => [
      ['charges_enabled' => FALSE],
      ['charges_enabled' => TRUE],
      TRUE,
    ];
    yield 'payouts disabled after being enabled' => [
      ['payouts_enabled' => FALSE],
      ['payouts_enabled' => TRUE],
      TRUE,
    ];
    yield 'card capability became inactive' => [
      ['capabilities' => ['card_payments' => 'inactive']],
      ['capabilities' => ['card_payments' => 'active']],
      TRUE,
    ];
    yield 'new disabled reason' => [
      ['requirements' => ['disabled_reason' => 'requirements.past_due']],
      ['requirements' => ['disabled_reason' => NULL]],
      TRUE,
    ];
    yield 'ordinary incomplete onboarding update' => [
      ['charges_enabled' => FALSE, 'details_submitted' => FALSE],
      ['details_submitted' => TRUE],
      FALSE,
    ];
    yield 'existing restriction with unrelated update' => [
      ['requirements' => ['disabled_reason' => 'requirements.past_due']],
      ['business_profile' => ['name' => 'Earlier name']],
      FALSE,
    ];
    yield 'capability remains inactive' => [
      ['capabilities' => ['card_payments' => 'inactive']],
      ['capabilities' => ['card_payments' => 'inactive']],
      FALSE,
    ];
  }

  /**
   * Creates a Connect account.updated event fixture.
   */
  private function accountUpdatedEvent(array $object, array $previous): Event {
    return Event::constructFrom([
      'id' => 'evt_account_updated_test',
      'account' => 'acct_connected_test',
      'type' => Event::ACCOUNT_UPDATED,
      'data' => [
        'object' => ['id' => 'acct_connected_test'] + $object,
        'previous_attributes' => $previous,
      ],
    ]);
  }

}
