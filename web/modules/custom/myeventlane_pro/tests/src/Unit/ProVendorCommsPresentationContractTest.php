<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\myeventlane_pro\Service\VendorCommsPlaceholderRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Guards the organiser-facing Pro automatic-email wording workflow.
 *
 * @group myeventlane_pro
 */
final class ProVendorCommsPresentationContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  public function testFormExplainsScopeAndStandardFallback(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProVendorCommsForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString('Make automatic emails sound like you', $form);
    $this->assertStringContainsString('Standard MyEventLane wording remains in place everywhere else.', $form);
    $this->assertStringContainsString('Leave a section blank to keep the standard email.', $form);
    $this->assertStringContainsString('myeventlane_vendor.console.messages', $form);
    $this->assertStringContainsString('myeventlane_vendor.console.messaging_brand', $form);
  }

  public function testPreviewUsesSelectedEmailTypeSafely(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProVendorCommsForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString("'preview_type'", $form);
    $this->assertStringContainsString('$allowedPreviewTypes', $form);
    $this->assertStringContainsString('$placeholderRenderer = $this->placeholderRenderer();', $form);
    $this->assertStringContainsString('$placeholderRenderer->render(', $form);
    $this->assertStringContainsString('Previewing does not save or send anything.', $form);
  }

  public function testPreviewAndDeliveryRendererReplacesAndEscapesValues(): void {
    require_once dirname(__DIR__, 3) . '/src/Service/VendorCommsPlaceholderRenderer.php';
    $renderer = new VendorCommsPlaceholderRenderer();
    $rendered = $renderer->render(
      '<p>Hi [customer:first_name], [event:title] costs [order:total].</p>',
      [
        'first_name' => 'Alex',
        'event_title' => '<script>Bad</script> Sample Event',
        'order_total' => '$49.00',
      ],
    );

    $this->assertStringContainsString('Hi Alex', $rendered);
    $this->assertStringContainsString('Sample Event', $rendered);
    $this->assertStringContainsString('$49.00', $rendered);
    $this->assertStringNotContainsString('<script>', $rendered);
  }

  public function testResolverCoversSevenDayReminder(): void {
    $resolver = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorCommsResolver.php');
    $this->assertIsString($resolver);
    $this->assertStringContainsString("'event_reminder_7d'", $resolver);
    $this->assertStringContainsString('$this->placeholderRenderer->render(', $resolver);
    $this->assertStringNotContainsString('$this->token->replace(', $resolver);
  }

  public function testThemeKeepsResponsiveGuidedPresentation(): void {
    $styles = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_pro-email-wording.scss');
    $this->assertIsString($styles);
    $this->assertStringContainsString('.mel-pro-email-wording__intro', $styles);
    $this->assertStringContainsString('.mel-pro-email-wording__tokens', $styles);
    $this->assertStringContainsString('.mel-pro-email-wording__email', $styles);
    $this->assertStringContainsString('.mel-pro-email-wording__workspace', $styles);
    $this->assertStringContainsString('.mel-pro-email-wording__preview-rail', $styles);
    $this->assertStringContainsString('@media (max-width: 767px)', $styles);
    $this->assertStringContainsString('min-height: 48px', $styles);
  }

}
