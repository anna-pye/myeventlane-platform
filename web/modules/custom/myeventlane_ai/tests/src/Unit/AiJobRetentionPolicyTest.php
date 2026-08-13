<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_ai\Entity\AiJob;
use Drupal\myeventlane_ai\Service\AiJobRetentionPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests AI job retention expiry calculation.
 */
#[Group('myeventlane_ai')]
final class AiJobRetentionPolicyTest extends TestCase {

  private const CREATED = 1_700_000_000;

  /**
   * Tests the configured expiry for successful and failed jobs.
   */
  public function testTerminalStatusExpiry(): void {
    $policy = $this->policy(7, 14);

    $this->assertSame(self::CREATED + (7 * 86400), $policy->expiryFor(AiJob::STATUS_DONE, self::CREATED));
    $this->assertSame(self::CREATED + (14 * 86400), $policy->expiryFor(AiJob::STATUS_ERROR, self::CREATED));
  }

  /**
   * Tests that active jobs never receive an expiry timestamp.
   */
  public function testActiveStatusHasNoExpiry(): void {
    $policy = $this->policy(7, 14);

    $this->assertSame(0, $policy->expiryFor(AiJob::STATUS_QUEUED, self::CREATED));
    $this->assertSame(0, $policy->expiryFor(AiJob::STATUS_RUNNING, self::CREATED));
  }

  /**
   * Tests that zero explicitly disables expiry for a terminal state.
   */
  public function testZeroRetentionHasNoExpiry(): void {
    $policy = $this->policy(0, 0);

    $this->assertSame(0, $policy->expiryFor(AiJob::STATUS_DONE, self::CREATED));
    $this->assertSame(0, $policy->expiryFor(AiJob::STATUS_ERROR, self::CREATED));
  }

  /**
   * Builds the policy with controlled retention configuration.
   */
  private function policy(int $done_days, int $error_days): AiJobRetentionPolicy {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['ai_job_retention_days', $done_days],
      ['ai_job_error_retention_days', $error_days],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_ai.settings')->willReturn($config);

    return new AiJobRetentionPolicy($factory);
  }

}
