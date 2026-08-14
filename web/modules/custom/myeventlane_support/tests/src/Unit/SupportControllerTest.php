<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_support\Unit;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_support\Controller\SupportController;
use Drupal\myeventlane_support\Service\SupportSearchRequestGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\myeventlane_support\Controller\SupportController
 *
 * @group myeventlane_support
 */
final class SupportControllerTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['hash_salt' => 'support-controller-test-salt']);
  }

  /**
   * @covers ::searchApi
   */
  public function testEmptyQueryIsLimitedAndRecordedWithoutSearch(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))->method('isAllowed')->willReturn(TRUE);
    $flood->expects($this->exactly(2))->method('register');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('info')
      ->with(
        'Legacy support search API request: {outcome}; results: {result_count}.',
        ['outcome' => 'empty_query', 'result_count' => 0],
      );

    $response = $this->controller($flood, $logger)->searchApi(
      Request::create('/support/search-api'),
    );

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['results' => []], json_decode((string) $response->getContent(), TRUE));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * @covers ::searchApi
   */
  public function testRateLimitedResponsePreservesJsonContractAndNoStore(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())->method('isAllowed')->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('info');

    $response = $this->controller($flood, $logger)->searchApi(
      Request::create('/support/search-api?q=refund'),
    );

    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('60', $response->headers->get('Retry-After'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame(
      ['results' => [], 'error' => 'rate_limited'],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Builds a controller with isolated flood and logging collaborators.
   */
  private function controller(
    FloodInterface $flood,
    LoggerInterface $logger,
  ): SupportController {
    return new SupportController(
      new RequestStack(),
      $logger,
      new SupportSearchRequestGuard($flood, $logger),
    );
  }

}
