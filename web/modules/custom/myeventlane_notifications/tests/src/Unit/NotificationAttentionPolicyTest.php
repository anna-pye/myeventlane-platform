<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_notifications\Unit;

use Drupal\myeventlane_notifications\NotificationAttentionPolicy;
use Drupal\myeventlane_notifications\NotificationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/src/NotificationContext.php';
require_once dirname(__DIR__, 3) . '/src/NotificationAttentionPolicy.php';

/**
 * Tests the explicit organiser attention policy.
 */
#[CoversClass(NotificationAttentionPolicy::class)]
final class NotificationAttentionPolicyTest extends TestCase {

  /**
   * Ensures importance alone does not create an organiser task.
   */
  public function testOnlyNamedBusinessActionsRequireAttention(): void {
    foreach (['refund_requested', 'capacity_reached', 'low_stock', 'boost_expiring'] as $actionContext) {
      self::assertTrue(NotificationAttentionPolicy::requiresAction(NotificationContext::BUSINESS, $actionContext));
    }

    self::assertFalse(NotificationAttentionPolicy::requiresAction(NotificationContext::BUSINESS, 'vendor_sale'));
    self::assertFalse(NotificationAttentionPolicy::requiresAction(NotificationContext::BUSINESS, 'event_approved'));
    self::assertFalse(NotificationAttentionPolicy::requiresAction(NotificationContext::PERSONAL, 'refund_requested'));
    self::assertFalse(NotificationAttentionPolicy::requiresAction(NotificationContext::PLATFORM, 'capacity_reached'));
  }

}
