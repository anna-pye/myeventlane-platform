<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Controller\EventStudioGovernanceComponentController;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceComponentBuilder;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Controller\EventStudioGovernanceComponentController
 *
 * @group myeventlane_event_studio
 */
final class EventStudioGovernanceComponentControllerTest extends TestCase {

  /**
   * @covers ::render
   */
  public function testInvalidComponentFailsBeforeRendering(): void {
    $controller = $this->controller(FALSE);
    $node = $this->eventNode();

    $response = $controller->render($node, 'observability', Request::create('/test', 'POST'));
    $data = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(FALSE, $data['ok']);
  }

  /**
   * @covers ::render
   */
  public function testMissingSurfaceServicesReturnDisabledGovernance(): void {
    $controller = $this->controller(FALSE, authenticated: TRUE);
    $node = $this->eventNode();

    $response = $controller->render($node, 'state', Request::create('/test', 'POST'));
    $data = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($data['ok']);
    $this->assertFalse($data['governance_enabled']);
    $this->assertSame('', $data['html']);
  }

  /**
   * @covers ::render
   */
  public function testAnonymousComponentRefreshIsDenied(): void {
    $controller = $this->controller(TRUE);
    $node = $this->eventNode();

    $this->expectException(AccessDeniedHttpException::class);
    $controller->render($node, 'state', Request::create('/test', 'POST'));
  }

  private function controller(bool $anonymous, bool $authenticated = FALSE): EventStudioGovernanceComponentController {
    $translator = $this->translator();
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);
    if ($authenticated) {
      $account->method('hasPermission')->with('administer nodes')->willReturn(TRUE);
      $account->method('id')->willReturn('9');
    }

    $governance_builder = new EventStudioGovernanceBuilder(
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
      $translator,
    );

    return new EventStudioGovernanceComponentController(
      $governance_builder,
      new EventStudioGovernanceComponentBuilder($translator, new MelReadinessHelper($translator)),
      $this->createMock(RendererInterface::class),
      $account,
      new EventVendorAccessChecker(),
      $this->createMock(LoggerInterface::class),
    );
  }

  private function eventNode(): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('event');
    $node->method('id')->willReturn(12);
    return $node;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
