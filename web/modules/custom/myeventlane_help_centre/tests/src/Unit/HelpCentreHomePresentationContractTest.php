<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_centre\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the compact Help Centre information hierarchy.
 *
 * @group myeventlane_help_centre
 */
final class HelpCentreHomePresentationContractTest extends TestCase {

  public function testHomeUsesOneTopicNavigatorAndOneSupportLayer(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/help/help-centre-home.html.twig',
    );
    $controller = (string) file_get_contents(
      $moduleRoot . '/src/Controller/HelpCentreController.php',
    );

    self::assertStringContainsString('mel-help-topics', $template);
    self::assertStringContainsString('{{ mel_support_layer }}', $template);
    self::assertStringNotContainsString("'Start by audience'|t", $template);
    self::assertStringNotContainsString('mel-help-home__section--support-final', $template);
    self::assertStringNotContainsString('href="/help/policies"', $template);
    self::assertStringContainsString("'title' => (string) \$this->t('Policies and trust')", $controller);
  }

}
