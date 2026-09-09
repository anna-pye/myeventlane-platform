<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Protects the public organiser profile presentation contract.
 *
 * @group myeventlane_vendor
 */
final class VendorPublicProfilePresentationContractTest extends UnitTestCase {

  /**
   * Confirms the public profile uses the MEL identity and content hierarchy.
   */
  public function testPublicProfileUsesMelPresentationPatterns(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = file_get_contents($webRoot . '/themes/custom/myeventlane_theme/templates/entity--myeventlane-vendor--full.html.twig');
    $styles = file_get_contents($webRoot . '/themes/custom/myeventlane_theme/src/scss/pages/_vendor.scss');
    $controller = file_get_contents($moduleRoot . '/src/Controller/VendorDetailController.php');
    $module = file_get_contents($moduleRoot . '/myeventlane_vendor.module');

    self::assertIsString($template);
    self::assertStringContainsString("'Organiser'|t", $template);
    self::assertStringContainsString("'Meet the organiser'|t", $template);
    self::assertStringContainsString("'About @organiser'|t", $template);
    self::assertStringContainsString("'Get in touch'|t", $template);
    self::assertStringContainsString('for key, item in contact', $template);
    self::assertStringContainsString("'Find your next experience'|t", $template);
    self::assertStringContainsString('events|length', $template);
    self::assertStringContainsString("'--mel-vendor-accent: ' ~ accent_color", $template);

    self::assertIsString($styles);
    self::assertStringContainsString('object-fit: contain', $styles);
    self::assertStringContainsString('.mel-vendor__profile-grid', $styles);
    self::assertStringContainsString('.mel-vendor__contact-label', $styles);
    self::assertStringContainsString('.mel-vendor__event-count', $styles);
    self::assertStringContainsString('repeat(4, minmax(0, 1fr))', $styles);
    self::assertStringContainsString('--mel-vendor-accent: var(--mel-coral)', $styles);
    self::assertStringContainsString('color-mix(in srgb, var(--mel-vendor-accent) 16%', $styles);

    self::assertIsString($controller);
    self::assertStringContainsString("'#accent_color' => \$this->resolveAccentColor(\$myeventlane_vendor)", $controller);
    self::assertStringContainsString("preg_match('/^#[0-9a-f]{6}$/', \$candidate)", $controller);

    self::assertIsString($module);
    self::assertStringContainsString("'accent_color' => NULL", $module);
  }

}
