<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\Core\Session\SessionManagerInterface;
use Drupal\myeventlane_commerce\Service\TicketBookingSessionService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\TicketBookingSessionService
 *
 * @group myeventlane_commerce
 */
final class TicketBookingSessionServiceTest extends UnitTestCase {

  /**
   * @covers ::getUnlockedTierIds
   * @covers ::getWaitlistClaimEntryId
   */
  public function testCommandLineReadsDoNotOpenSession(): void {
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->expects(self::never())->method('getCurrentRequest');

    $sessionManager = $this->createMock(SessionManagerInterface::class);
    $sessionManager->expects(self::never())->method('start');

    $service = new TicketBookingSessionService($requestStack, $sessionManager);

    self::assertSame([], $service->getUnlockedTierIds(42));
    self::assertNull($service->getWaitlistClaimEntryId(42));
  }

  /**
   * @covers ::recordAccessGrant
   */
  public function testCommandLineWritesFailClosedWithoutStartingSession(): void {
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->expects(self::never())->method('getCurrentRequest');

    $sessionManager = $this->createMock(SessionManagerInterface::class);
    $sessionManager->expects(self::never())->method('isStarted');
    $sessionManager->expects(self::never())->method('start');

    $service = new TicketBookingSessionService($requestStack, $sessionManager);
    $service->recordAccessGrant(42, 7, 9, [11, 12]);

    self::assertSame([], $service->getUnlockedTierIds(42));
  }

}
