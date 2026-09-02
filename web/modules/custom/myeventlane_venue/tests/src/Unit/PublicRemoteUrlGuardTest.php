<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use Drupal\myeventlane_venue\Exception\UnsafeRemoteUrlException;
use Drupal\myeventlane_venue\Service\PublicRemoteUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the deterministic IP safety boundary for remote venue metadata.
 */
#[Group('myeventlane_venue')]
final class PublicRemoteUrlGuardTest extends TestCase {

  /**
   * Loads the worktree implementation when using the repository autoloader.
   */
  public static function setUpBeforeClass(): void {
    require_once dirname(__DIR__, 3) . '/src/Exception/UnsafeRemoteUrlException.php';
    require_once dirname(__DIR__, 3) . '/src/Service/PublicRemoteUrlGuard.php';
  }

  /**
   * Tests public and non-public address classification.
   */
  #[DataProvider('ipProvider')]
  public function testPublicAddressClassification(string $ip, bool $expected): void {
    self::assertSame($expected, (new PublicRemoteUrlGuard())->isPublicIp($ip));
  }

  /**
   * Tests URL rules and the resolved-address check without using live DNS.
   */
  public function testValidPublicWebsite(): void {
    $guard = new PublicRemoteUrlGuard(static fn(string $host): array => ['93.184.216.34']);
    self::assertSame([
      'url' => 'https://example.com/venue',
      'host' => 'example.com',
      'ips' => ['93.184.216.34'],
    ], $guard->validate('https://example.com/venue'));
  }

  /**
   * Tests that a domain resolving to a private address is refused.
   */
  public function testPrivateDnsTargetIsRefused(): void {
    $guard = new PublicRemoteUrlGuard(static fn(string $host): array => ['169.254.169.254']);
    $this->expectException(UnsafeRemoteUrlException::class);
    $guard->validate('https://example.com/venue');
  }

  /**
   * Tests malformed and non-HTTPS targets are refused before DNS lookup.
   */
  #[DataProvider('unsafeUrlProvider')]
  public function testUnsafeUrlIsRefused(string $url): void {
    $guard = new PublicRemoteUrlGuard(static fn(string $host): array => ['93.184.216.34']);
    $this->expectException(UnsafeRemoteUrlException::class);
    $guard->validate($url);
  }

  /**
   * Provides representative public, private and reserved IP addresses.
   *
   * @return array<string, array{string, bool}>
   *   Test cases keyed by their intent.
   */
  public static function ipProvider(): array {
    return [
      'public example' => ['93.184.216.34', TRUE],
      'loopback' => ['127.0.0.1', FALSE],
      'private ten' => ['10.0.0.1', FALSE],
      'private one-seven-two' => ['172.16.0.1', FALSE],
      'private one-nine-two' => ['192.168.1.1', FALSE],
      'link local' => ['169.254.169.254', FALSE],
      'reserved documentation' => ['192.0.2.1', FALSE],
      'invalid' => ['not-an-ip', FALSE],
    ];
  }

  /**
   * Provides URLs that must never reach the remote client.
   *
   * @return array<string, array{string}>
   *   Unsafe URLs keyed by their intent.
   */
  public static function unsafeUrlProvider(): array {
    return [
      'plain HTTP' => ['http://example.com/venue'],
      'credentials' => ['https://user:password@example.com/venue'],
      'custom port' => ['https://example.com:8443/venue'],
      'IP literal' => ['https://127.0.0.1/venue'],
      'localhost' => ['https://localhost/venue'],
      'missing host' => ['https:///venue'],
    ];
  }

}
