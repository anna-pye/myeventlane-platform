<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Vendor dashboard remains permission-governed; anonymous users never see console HTML.
 *
 * Detailed vendor KPI governance is covered in Kernel tests to avoid brittle fixtures here.
 *
 * @group myeventlane_surface
 */
final class MelVendorDashboardGovernanceTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'myeventlane_core',
    'myeventlane_vendor',
    'myeventlane_surface',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testAnonymousVendorDashboardIsNotSuccessfulShell(): void {
    $this->drupalGet('/vendor/dashboard');
    $this->assertSession()->statusCodeIsNot(200);
  }

}
