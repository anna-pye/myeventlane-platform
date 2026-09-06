<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\myeventlane_core\Controller\ErrorController;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies recovery responses and theme isolation across subrequests.
 *
 * @group myeventlane_core
 */
final class ErrorControllerTest extends UnitTestCase {

  public function testErrorResponsesRestoreAnAlreadyActiveTheme(): void {
    foreach (['accessDenied' => 403, 'pageNotFound' => 404] as $method => $status) {
      $previous = new ActiveTheme(['name' => 'myeventlane_vendor_theme']);
      $recovery = new ActiveTheme(['name' => 'mel_maintenance']);
      $manager = $this->createMock(ThemeManagerInterface::class);
      $manager->method('getActiveTheme')->willReturn($previous);
      $selected = [];
      $manager->expects($this->exactly(2))->method('setActiveTheme')->willReturnCallback(function ($theme) use (&$selected) { $selected[] = $theme; });
      $initialization = $this->createMock(ThemeInitializationInterface::class);
      $initialization->expects($this->once())->method('initTheme')->with('mel_maintenance')->willReturn($recovery);
      $renderer = $this->createMock(BareHtmlPageRendererInterface::class);
      $renderer->expects($this->once())->method('renderBarePage')->with([], $this->anything(), 'page__' . $status, ['#show_messages' => FALSE])->willReturn(new HtmlResponse('Recovery'));
      $controller = new ErrorController($renderer, $manager, $initialization);
      $controller->setStringTranslation($this->getStringTranslationStub());
      $response = $controller->$method();
      self::assertSame($status, $response->getStatusCode());
      self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
      self::assertSame([$recovery, $previous], $selected);
    }
  }

}
