<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the public event description rendering contract.
 *
 * @group myeventlane_event
 */
final class PublicEventBodyTemplateContractTest extends TestCase {

  public function testBodyIsRenderedOnceAndReusedForOutput(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig',
    );

    self::assertSame(1, substr_count($template, 'content.body|render'));
    self::assertStringContainsString('{{ body_render }}', $template);
    self::assertStringNotContainsString('{{ content.body }}', $template);
  }

}
