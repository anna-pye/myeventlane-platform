<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for QR signing secret resolution.
 *
 * @coversDefaultClass \Drupal\myeventlane_tickets\Ticket\TicketQrPayload
 * @group myeventlane_tickets
 */
final class TicketQrPayloadSecretTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    // Reset Settings between tests.
    new Settings([]);
  }

  /**
   * @covers ::resolveSecretSource
   * @covers ::isSecretConfigured
   */
  public function testResolveSecretSourceFromSettings(): void {
    new Settings(['myeventlane_qr_secret' => 'from-settings']);
    $payload = $this->payload('signed');
    $this->assertTrue($payload->isSecretConfigured());
    $this->assertSame('settings:myeventlane_qr_secret', $payload->resolveSecretSource());
  }

  /**
   * @covers ::resolveSecretSource
   */
  public function testResolveSecretSourceFromEnv(): void {
    new Settings([]);
    putenv('MEL_QR_SECRET=from-env');
    $payload = $this->payload('signed');
    $this->assertTrue($payload->isSecretConfigured());
    $this->assertSame('env:MEL_QR_SECRET', $payload->resolveSecretSource());
    putenv('MEL_QR_SECRET');
  }

  /**
   * @covers ::resolveSecretSource
   * @covers ::isSecretConfigured
   */
  public function testMissingSecretReportsNullSource(): void {
    new Settings([]);
    putenv('MEL_QR_SECRET');
    $payload = $this->payload('signed');
    $this->assertFalse($payload->isSecretConfigured());
    $this->assertNull($payload->resolveSecretSource());
  }

  /**
   * @covers ::requiresSigningSecret
   * @covers ::isSigningConfigurationHealthy
   */
  public function testCodeOnlyModeDoesNotRequireSecret(): void {
    new Settings([]);
    putenv('MEL_QR_SECRET');
    $payload = $this->payload('code_only');
    $this->assertFalse($payload->requiresSigningSecret());
    $this->assertTrue($payload->isSigningConfigurationHealthy());
  }

  /**
   * @covers ::isSigningConfigurationHealthy
   */
  public function testSignedModeUnhealthyWithoutSecret(): void {
    new Settings([]);
    putenv('MEL_QR_SECRET');
    $payload = $this->payload('signed');
    $this->assertTrue($payload->requiresSigningSecret());
    $this->assertFalse($payload->isSigningConfigurationHealthy());
  }

  /**
   * @covers ::isSigningConfigurationHealthy
   */
  public function testSignedModeHealthyWithSecret(): void {
    new Settings(['myeventlane_qr_secret' => 'present']);
    $payload = $this->payload('signed');
    $this->assertTrue($payload->isSigningConfigurationHealthy());
  }

  private function payload(string $mode): TicketQrPayload {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($mode) {
      return $key === 'qr_payload_mode' ? $mode : NULL;
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_tickets.settings')->willReturn($config);
    $logger = $this->createMock(LoggerInterface::class);
    return new TicketQrPayload($factory, $logger);
  }

}
