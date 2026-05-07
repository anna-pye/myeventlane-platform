<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use Drupal\Core\Template\Attribute;
use Drupal\myeventlane_surface\MelComponentAccessibilityHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_surface\MelComponentAccessibilityHelper
 *
 * @group myeventlane_surface
 */
final class MelComponentAccessibilityHelperTest extends UnitTestCase {

  /**
   * @covers ::applyEmptyStateSemantics
   */
  public function testApplyEmptyStateSemanticsAddsStatusRegion(): void {
    $variables = ['attributes' => new Attribute([])];
    (new MelComponentAccessibilityHelper())->applyEmptyStateSemantics($variables);
    /** @var \Drupal\Core\Template\Attribute $attrs */
    $attrs = $variables['attributes'];
    $rendered = (string) $attrs;
    $this->assertStringContainsString('role="status"', $rendered);
    $this->assertStringContainsString('aria-live="polite"', $rendered);
  }

  /**
   * @covers ::applyEmptyStateSemantics
   */
  public function testApplyEmptyStateSemanticsDoesNotOverrideExistingRole(): void {
    $variables = ['attributes' => new Attribute(['role' => 'region'])];
    (new MelComponentAccessibilityHelper())->applyEmptyStateSemantics($variables);
    /** @var \Drupal\Core\Template\Attribute $attrs */
    $attrs = $variables['attributes'];
    $rendered = (string) $attrs;
    $this->assertStringContainsString('role="region"', $rendered);
    $this->assertStringContainsString('aria-live="polite"', $rendered);
  }

}
