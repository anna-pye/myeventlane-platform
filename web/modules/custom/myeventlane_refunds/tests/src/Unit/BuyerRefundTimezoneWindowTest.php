<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService
 * @group myeventlane_refunds
 */
final class BuyerRefundTimezoneWindowTest extends UnitTestCase {

  /**
   * @covers ::withinRefundWindow
   * @dataProvider cutoffProvider
   */
  public function testTwentyFourHourPolicyUsesExactEventLocalStart(int $now, bool $expected): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $inspector = (new \ReflectionClass(RefundOrderInspector::class))->newInstanceWithoutConstructor();
    $service = new BuyerRefundEligibilityService($inspector, $time, $this->eventDateTime());
    $method = (new \ReflectionClass($service))->getMethod('withinRefundWindow');

    $this->assertSame($expected, $method->invoke($service, $this->event()));
  }

  /**
   * Provides instants around the exact refund cutoff.
   *
   * @return array<string, array{int, bool}>
   *   Current timestamps and expected eligibility results.
   */
  public static function cutoffProvider(): array {
    return [
      'one minute before cutoff' => [strtotime('2026-12-09T08:29:00Z'), TRUE],
      'one minute after cutoff' => [strtotime('2026-12-09T08:31:00Z'), FALSE],
    ];
  }

  /**
   * Creates the event date and time resolver under test.
   */
  private function eventDateTime(): EventDateTimeResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('timezone.default')->willReturn('Australia/Sydney');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.date')->willReturn($config);
    return new EventDateTimeResolver($factory);
  }

  /**
   * Creates a Brisbane event mock with a 24-hour policy.
   */
  private function event(): NodeInterface {
    $fields = [
      'field_refund_policy' => $this->valueField('refund_24h'),
      'field_event_start' => $this->valueField('2026-12-10T18:30:00'),
      'field_series_timezone' => $this->valueField('Australia/Brisbane'),
      'field_location' => $this->emptyField(),
    ];
    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $event->method('get')->willReturnCallback(static fn (string $field) => $fields[$field]);
    return $event;
  }

  /**
   * Creates a populated field item list mock.
   */
  private function valueField(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('value')->willReturn($value);
    $field->method('getValue')->willReturn([['value' => $value]]);
    return $field;
  }

  /**
   * Creates an empty field item list mock.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

}
