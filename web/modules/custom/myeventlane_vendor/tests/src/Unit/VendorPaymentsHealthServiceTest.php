<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\VendorPaymentsHealthService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\VendorPaymentsHealthService
 *
 * @group myeventlane_vendor
 */
final class VendorPaymentsHealthServiceTest extends UnitTestCase {

  /**
   * @covers ::buildForCurrentUser
   */
  public function testNotConnectedWithoutVendor(): void {
    $resolver = $this->createMock(CurrentVendorResolverInterface::class);
    $resolver->method('resolveFromCurrentUser')->willReturn(NULL);

    $service = new VendorPaymentsHealthService(
      $resolver,
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->getStringTranslationStub(),
    );

    $health = $service->buildForCurrentUser();
    $this->assertSame('not_connected', $health['state']);
    $this->assertTrue($health['needs_attention']);
    $this->assertStringContainsString('Connect Stripe', $health['headline']);
    $this->assertStringNotContainsString('Gateway', $health['headline']);
    $this->assertStringNotContainsString('Commerce', $health['summary']);
    $this->assertStringNotContainsString('Store', $health['summary']);
  }

}
