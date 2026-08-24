<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\myeventlane_messaging\Service\MelEmailLayoutService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests shared plain-text email formatting.
 *
 * @group myeventlane_messaging
 */
final class MelEmailLayoutServiceTest extends TestCase {

  /**
   * Plain URLs become clickable without losing surrounding punctuation.
   */
  public function testPlainTextUrlsBecomeSafeLinks(): void {
    require_once dirname(__DIR__, 3) . '/src/Service/MelEmailLayoutService.php';
    $reflection = new ReflectionClass(MelEmailLayoutService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'htmlOrPlainToInnerHtml');

    $html = (string) $method->invoke(
      $service,
      'Set up: https://example.com/user/reset/1/token/login?mel_flow=account_setup.',
    );

    self::assertStringContainsString(
      'href="https://example.com/user/reset/1/token/login?mel_flow=account_setup"',
      $html,
    );
    self::assertStringContainsString('</a>.', $html);
  }

}
