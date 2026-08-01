<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects Drupal form machine values from display-language rewriting.
 *
 * @group myeventlane_core
 */
final class LanguageStyleFormPreprocessContractTest extends TestCase {

  /**
   * Ensures the generic theme preprocessor excludes form element hooks.
   */
  public function testFormElementHooksAreExcludedFromLanguageRewriting(): void {
    $theme_file = dirname(__DIR__, 6) . '/themes/custom/myeventlane_theme/myeventlane_theme.theme';
    $source = file_get_contents($theme_file);

    self::assertIsString($source);
    self::assertStringContainsString("'form',", $source);
    self::assertStringContainsString("'input',", $source);
    self::assertStringContainsString("'textarea',", $source);
    self::assertStringContainsString("'select',", $source);
    self::assertStringContainsString('$hook === $form_theme_hook', $source);
    self::assertStringContainsString("str_starts_with(\$hook, \$form_theme_hook . '__')", $source);
    self::assertStringContainsString('rewriting the hidden value user_form to member_form', $source);
  }

}
