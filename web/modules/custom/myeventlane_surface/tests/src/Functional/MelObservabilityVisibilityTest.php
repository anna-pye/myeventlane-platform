<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * HTTP-level observability attachments (drupalSettings + libraries).
 *
 * @group myeventlane_surface
 */
final class MelObservabilityVisibilityTest extends BrowserTestBase {

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

  /**
   * Staff-capable account with diagnostics permission should expose client hints when traces exist.
   */
  public function testStaffUserWithDiagnosticsPermissionMayExposeMelObservabilitySetting(): void {
    $account = $this->drupalCreateUser([
      'access mel observability diagnostics',
      'access administration pages',
      'view the administration theme',
    ]);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/config/system/site-information');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('melObservability');
  }

  /**
   * Same admin navigation entrypoint without diagnostics permission must not attach melObservability.
   */
  public function testStaffUserWithoutDiagnosticsPermissionOmitsMelObservabilitySetting(): void {
    $account = $this->drupalCreateUser([
      'access administration pages',
      'view the administration theme',
    ]);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/config/system/site-information');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('melObservability');
  }

}
