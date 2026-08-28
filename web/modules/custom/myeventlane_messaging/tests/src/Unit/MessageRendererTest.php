<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/MessageRenderer.php';

use Drupal\Core\Config\Config;
use Drupal\Core\Render\RendererInterface;
use Drupal\myeventlane_messaging\Service\MessageRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Tests channel-safe message rendering.
 *
 * @group myeventlane_messaging
 */
final class MessageRendererTest extends TestCase {

  /**
   * SMS output must be a single plain-text line.
   */
  public function testSmsRenderingProducesSingleLinePlainText(): void {
    $renderer = new MessageRenderer(
      new Environment(new ArrayLoader()),
      $this->createMock(RendererInterface::class),
    );

    $sms = $renderer->renderSmsText(
      $this->createMock(Config::class),
      ['name' => 'Anna'],
      "MyEventLane: <strong>Hello {{ name }}</strong>.\n  Details: https://example.test/event/1",
    );

    self::assertSame(
      'MyEventLane: Hello Anna. Details: https://example.test/event/1',
      $sms,
    );
  }

}
