<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision matrix for ticket resend ownership + capability checks.
 *
 * Mirrors TicketOperationsAccess::accessResend without AccessResult/container.
 *
 * @group myeventlane_tickets
 */
final class TicketResendAccessDecisionTest extends TestCase {

  /**
   * @dataProvider provider
   */
  public function testDecision(
    array $permissions,
    bool $canManageTickets,
    bool $hasParity,
    bool $expected,
  ): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $p): bool => in_array($p, $permissions, TRUE),
    );
    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn($hasParity);
    $event = $this->createMock(NodeInterface::class);

    $this->assertSame(
      $expected,
      $this->decide($account, $event, $checker, $canManageTickets),
    );
  }

  /**
   * @return iterable<string, array{0: list<string>, 1: bool, 2: bool, 3: bool}>
   */
  public static function provider(): iterable {
    yield 'anonymous' => [[], FALSE, FALSE, FALSE];
    yield 'auth no resend' => [['access vendor console'], FALSE, TRUE, FALSE];
    yield 'owner parity + resend' => [['resend ticket emails', 'access vendor console'], FALSE, TRUE, TRUE];
    yield 'manage tickets + resend' => [['resend ticket emails', 'manage own events tickets'], TRUE, FALSE, TRUE];
    yield 'other organiser' => [['resend ticket emails', 'access vendor console'], FALSE, FALSE, FALSE];
    yield 'admin tickets alone still needs ownership path' => [['administer myeventlane tickets'], FALSE, FALSE, FALSE];
  }

  private function decide(
    AccountInterface $account,
    NodeInterface $event,
    EventVendorAccessCheckerInterface $checker,
    bool $canManageTickets,
  ): bool {
    // Mirrors TicketOperationsAccess::accessResend after the ticket→event resolve.
    if (!$account->hasPermission('resend ticket emails') && !$account->hasPermission('administer myeventlane tickets')) {
      return FALSE;
    }
    if ($canManageTickets) {
      return TRUE;
    }
    return $account->hasPermission('access vendor console')
      && $checker->accountHasWorkspaceParityForEvent($event, $account);
  }

}
