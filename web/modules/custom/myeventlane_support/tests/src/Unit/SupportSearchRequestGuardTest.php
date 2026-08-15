<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_support\Unit;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_support\Service\SupportSearchRequestGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\myeventlane_support\Service\SupportSearchRequestGuard
 *
 * @group myeventlane_support
 */
final class SupportSearchRequestGuardTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['hash_salt' => 'support-search-test-salt']);
  }

  /**
   * @covers ::isAllowed
   */
  public function testAllowedRequestRegistersBothLimitsWithHashedIdentifier(): void {
    $flood = $this->createMock(FloodInterface::class);
    $identifiers = [];
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(function (string $event, int $threshold, int $window, string $identifier) use (&$identifiers): bool {
        $identifiers[] = $identifier;
        $this->assertNotSame('203.0.113.4', $identifier);
        return TRUE;
      });
    $flood->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(function (string $event, int $window, string $identifier) use (&$identifiers): void {
        $this->assertSame($identifiers[0], $identifier);
      });

    $guard = new SupportSearchRequestGuard($flood, $this->createMock(LoggerInterface::class));
    $request = Request::create('/support/search-api?q=refund', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.4']);

    $this->assertTrue($guard->isAllowed($request));
    $this->assertCount(2, $identifiers);
    $this->assertSame($identifiers[0], $identifiers[1]);
  }

  /**
   * @covers ::isAllowed
   */
  public function testBurstLimitDenialDoesNotAmplifyLoggingOrRegister(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())->method('isAllowed')->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('info');

    $guard = new SupportSearchRequestGuard($flood, $logger);
    $this->assertFalse($guard->isAllowed(Request::create('/support/search-api?q=refund')));
  }

  /**
   * @covers ::record
   */
  public function testUsageRecordContainsOnlyOutcomeAndResultCount(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('info')
      ->with(
        'Legacy support search API request: {outcome}; results: {result_count}.',
        ['outcome' => 'success', 'result_count' => 3],
      );

    $guard = new SupportSearchRequestGuard($this->createMock(FloodInterface::class), $logger);
    $guard->record('success', 3);
  }

}
