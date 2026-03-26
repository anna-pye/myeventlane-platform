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
    'myeventlane_help_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests /help/assistant redirects to the canonical /help hub when Help Centre is present.
   */
  public function testAssistantRouteRedirectsToHelpHub(): void {
    $user = $this->drupalCreateUser([
      'access content',
      'access myeventlane help assistant',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/help/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/help');
    $this->assertSession()->pageTextContains('MyEventLane Help Centre');
  }

}
