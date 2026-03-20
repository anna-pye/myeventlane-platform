<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_assistant\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies Help Assistant route rendering.
 *
 * @group myeventlane_help_assistant
 */
final class HelpAssistantRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'taxonomy',
    'text',
    'path_alias',
    'myeventlane_help_centre',
    'myeventlane_help_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests Help Assistant page rendering for authorised users.
   */
  public function testAssistantRouteRenders(): void {
    $user = $this->drupalCreateUser(['access myeventlane help assistant']);
    $this->drupalLogin($user);

    $this->drupalGet('/help/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Help Assistant');
    $this->assertSession()->fieldExists('question');
  }

}
