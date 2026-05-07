<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Confirms shell negotiation exposes categorical surface hints on HTML output.
 *
 * @group myeventlane_surface
 */
final class MelSurfaceRouteGovernanceTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'myeventlane_core',
    'myeventlane_surface',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testFrontPageEmitsMelSurfaceDataAttribute(): void {
    $this->drupalGet('/');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('data-mel-surface=');
  }

}
