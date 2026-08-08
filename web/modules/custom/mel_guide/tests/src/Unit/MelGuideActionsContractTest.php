<?php

declare(strict_types=1);

namespace Drupal\Tests\mel_guide\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Protects the MEL Guide route, accessibility, and presentation contracts.
 */
#[Group('mel_guide')]
final class MelGuideActionsContractTest extends UnitTestCase {

  /**
   * Actions use existing routes and fail closed when help is unavailable.
   */
  public function testActionsAreRouteBackedAndAccessChecked(): void {
    $module = dirname(__DIR__, 3);
    $context = file_get_contents($module . '/src/Service/MelGuideContext.php');
    $services = file_get_contents($module . '/mel_guide.services.yml');

    self::assertIsString($context);
    self::assertIsString($services);
    self::assertStringContainsString("Url::fromRoute('view.upcoming_events.page_events')", $context);
    self::assertStringContainsString("moduleExists('myeventlane_help_centre')", $context);
    self::assertStringContainsString("moduleExists('myeventlane_help_assistant')", $context);
    self::assertStringContainsString("get('myeventlane_help_assistant.settings')->get('enabled')", $context);
    self::assertStringContainsString("Url::fromRoute('myeventlane_help_centre.home'", $context);
    self::assertStringContainsString("'fragment' => 'mel-help-assistant'", $context);
    self::assertSame(2, substr_count($context, "->access(\$this->currentUser)"));
    self::assertStringContainsString("'@module_handler'", $services);
    self::assertStringContainsString("'@current_user'", $services);
  }

  /**
   * The guide offers keyboard-sized links without becoming another chatbot.
   */
  public function testActionsAreAccessibleAndNonConversational(): void {
    $module = dirname(__DIR__, 3);
    $root = dirname(__DIR__, 7);
    $template = file_get_contents($module . '/templates/mel-guide.html.twig');
    $styles = file_get_contents($module . '/css/mel-guide.css');
    $sync = file_get_contents($root . '/config/sync/mel_guide.settings.yml');

    self::assertIsString($template);
    self::assertIsString($styles);
    self::assertIsString($sync);
    self::assertStringContainsString("aria-label=\"{{ 'MEL guide actions'|t }}\"", $template);
    self::assertStringContainsString("{{ 'Browse events'|t }}", $template);
    self::assertStringContainsString("{{ 'Ask MEL for help'|t }}", $template);
    self::assertStringContainsString("{{ 'Hide MEL'|t }}", $template);
    self::assertStringNotContainsString('<input', $template);
    self::assertStringNotContainsString('<textarea', $template);
    self::assertStringContainsString('min-height: 44px', $styles);
    self::assertStringContainsString("welcome: \"Hi, I'm MEL. I can help you find your next event.\"", $sync);
    self::assertStringNotContainsString('Looking for something fun?', $sync);
  }

}
