<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Controller\EventStudioGovernanceRefreshController;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Controller\EventStudioGovernanceRefreshController
 *
 * @group myeventlane_event_studio
 */
final class EventStudioGovernanceRefreshControllerTest extends TestCase {

  /**
   * @covers ::refresh
   */
  public function testMissingSurfaceServicesReturnDisabledGovernance(): void {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('hasPermission')->with('administer nodes')->willReturn(TRUE);
    $account->method('id')->willReturn('9');

    $controller = new EventStudioGovernanceRefreshController(
      new EventStudioGovernanceBuilder(
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        $account,
        NULL,
        NULL,
        NULL,
        $this->createMock(LoggerInterface::class),
        $this->translator(),
      ),
      $account,
      new EventVendorAccessChecker(),
      $this->createMock(LoggerInterface::class),
    );

    $response = $controller->refresh($this->eventNode(), Request::create('/test', 'POST'));
    $data = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($data['ok']);
    $this->assertFalse($data['governance_enabled']);
  }

  private function eventNode(): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('event');
    $node->method('id')->willReturn(12);
    return $node;
  }

  private function translator(): \Drupal\Core\StringTranslation\TranslationInterface {
    $translator = $this->createMock(\Drupal\Core\StringTranslation\TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (\Drupal\Core\StringTranslation\TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
