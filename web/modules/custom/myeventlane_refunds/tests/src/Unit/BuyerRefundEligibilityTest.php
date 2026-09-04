<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService;
use Drupal\myeventlane_refunds\Service\RefundOrderInspectorInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards buyer refund eligibility after earlier payment refunds.
 */
#[CoversClass(BuyerRefundEligibilityService::class)]
#[Group('myeventlane_refunds')]
final class BuyerRefundEligibilityTest extends UnitTestCase {

  /**
   * A cancelled/refunded ticket cannot start another request.
   */
  public function testNoActiveTicketValueIsIneligible(): void {
    $service = $this->service([], 38);
    $order = $this->order();
    $event = $this->event();
    $buyer = $this->buyer();

    self::assertFalse($service->isEligible($order, $event, $buyer));
    self::assertSame(
      'There are no active tickets left to refund for this event.',
      $service->getIneligibilityReason($order, $event, $buyer),
    );
  }

  /**
   * A residual fee cannot fund a second full-ticket request.
   */
  public function testTicketMustFitRemainingPaymentBalance(): void {
    $service = $this->service([
      323 => ['attendee' => new \stdClass(), 'amount_cents' => 2273, 'display_name' => 'Buyer'],
    ], 38);

    self::assertFalse($service->isEligible($this->order(), $this->event(), $this->buyer()));
  }

  /**
   * An active ticket within the remaining balance stays eligible.
   */
  public function testActiveTicketWithinBalanceRemainsEligible(): void {
    $service = $this->service([
      323 => ['attendee' => new \stdClass(), 'amount_cents' => 2273, 'display_name' => 'Buyer'],
    ], 2311);

    self::assertTrue($service->isEligible($this->order(), $this->event(), $this->buyer()));
  }

  /**
   * Builds the service with controlled ticket and payment state.
   */
  private function service(array $breakdown, int $availableCents): BuyerRefundEligibilityService {
    $inspector = $this->createMock(RefundOrderInspectorInterface::class);
    $inspector->method('extractItemsForEvent')->willReturn([
      $this->createMock(OrderItemInterface::class),
    ]);
    $inspector->method('getRefundableTicketAttendeeBreakdown')->willReturn($breakdown);
    $inspector->method('calculateRefundableAmountCents')->willReturn($availableCents);

    return new BuyerRefundEligibilityService(
      $inspector,
      $this->createMock(TimeInterface::class),
      $this->eventDateTime(),
    );
  }

  /**
   * Builds an owned, completed order.
   */
  private function order(): OrderInterface {
    $state = new class {

      /**
       * Returns the completed state ID.
       */
      public function getId(): string {
        return 'completed';
      }

    };
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCustomerId')->willReturn(42);
    $order->method('getState')->willReturn($state);
    return $order;
  }

  /**
   * Builds the buyer account.
   */
  private function buyer(): AccountInterface {
    $buyer = $this->createMock(AccountInterface::class);
    $buyer->method('isAnonymous')->willReturn(FALSE);
    $buyer->method('id')->willReturn(42);
    return $buyer;
  }

  /**
   * Builds an event with a case-by-case refund policy.
   */
  private function event(): NodeInterface {
    $policy = $this->createMock(FieldItemListInterface::class);
    $policy->method('isEmpty')->willReturn(FALSE);
    $policy->method('__get')->with('value')->willReturn('case_by_case');

    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn(1615);
    $event->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_refund_policy',
    );
    $event->method('get')->with('field_refund_policy')->willReturn($policy);
    return $event;
  }

  /**
   * Builds the unused date resolver required by the service contract.
   */
  private function eventDateTime(): EventDateTimeResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('timezone.default')->willReturn('Australia/Sydney');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.date')->willReturn($config);
    return new EventDateTimeResolver($factory);
  }

}
