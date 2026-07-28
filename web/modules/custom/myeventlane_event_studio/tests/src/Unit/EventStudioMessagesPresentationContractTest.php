<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Event Studio Messages presentation hierarchy.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioMessagesPresentationContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Messages remains an operational page without a redundant form submit.
   */
  public function testMessagesFormRemovesWizardActions(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/MessagingForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString('mel-event-studio-wizard-form--messages', $form);
    $this->assertStringContainsString("unset(\$form['actions']);", $form);
  }

  /**
   * The template keeps the approved compact desktop hierarchy.
   */
  public function testMessagesTemplatePresentationContract(): void {
    $template = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/event-messages-panel.html.twig');
    $this->assertIsString($template);

    $this->assertStringContainsString('mel-messages-hub__command', $template);
    $this->assertStringContainsString('mel-messages-hub__activity-grid', $template);
    $this->assertStringContainsString('mel-messages-hub__danger-link', $template);
    $this->assertStringContainsString('Automatic event reminders continue separately.', $template);
  }

  /**
   * Desktop density and mobile stacking remain explicit in theme source.
   */
  public function testMessagesStylesKeepResponsiveContract(): void {
    $styles = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_messages-hub.scss');
    $this->assertIsString($styles);

    $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr));', $styles);
    $this->assertStringContainsString('.mel-event-studio-wizard-form--messages', $styles);
    $this->assertStringContainsString('.mel-messages-hub__activity-grid', $styles);
    $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
  }

}
