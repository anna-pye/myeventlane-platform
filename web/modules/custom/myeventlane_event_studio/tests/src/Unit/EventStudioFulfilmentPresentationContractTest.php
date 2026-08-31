<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser-facing Collection workspace contract.
 */
final class EventStudioFulfilmentPresentationContractTest extends TestCase {

  public function testCollectionWorkflowUsesPlainEnglishRoutesAndHelp(): void {
    $root = dirname(__DIR__, 7);
    $form = (string) file_get_contents($root . '/web/modules/custom/myeventlane_event_studio/src/Form/EventStudioOperationalCapabilityForm.php');

    self::assertStringContainsString('Plan collection and redemption', $form);
    self::assertStringContainsString('This page does not mark an item as collected', $form);
    self::assertStringContainsString("'myeventlane_event_studio.workspace_extras'", $form);
    self::assertStringContainsString("'myeventlane_vendor.console.event_operational_addon_orders'", $form);
    self::assertStringContainsString('/help/organisers/setting-up-and-managing-event-collection', $form);
    self::assertStringContainsString("'#type' => 'hidden'", $form);
    self::assertStringNotContainsString("'#title' => \$this->t('Commerce product ID')", $form);
  }

  public function testOnlyTheSelectedCapabilityEditorIsShown(): void {
    $root = dirname(__DIR__, 7);
    $css = (string) file_get_contents($root . '/web/modules/custom/myeventlane_event_studio/css/mel-operational-capability-studio.css');
    $js = (string) file_get_contents($root . '/web/modules/custom/myeventlane_event_studio/js/mel-operational-capability-studio.js');

    self::assertMatchesRegularExpression('/\.mel-operational-capability-editor\s*\{[^}]*display:\s*none;/s', $css);
    self::assertMatchesRegularExpression('/\.mel-operational-capability-editor\.is-active\s*\{[^}]*display:\s*block;/s', $css);
    self::assertStringContainsString("el.setAttribute('aria-hidden', active ? 'false' : 'true')", $js);
    self::assertStringContainsString("candidate.setAttribute('aria-expanded'", $js);
  }

  public function testEveryCapabilityCardCanShowPurposeHelp(): void {
    $root = dirname(__DIR__, 7);
    $manager = (string) file_get_contents($root . '/web/modules/custom/myeventlane_event_studio/src/Service/OperationalCapabilityStudioManager.php');
    $template = (string) file_get_contents($root . '/web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-operational-capabilities.html.twig');

    self::assertStringContainsString("'description' => \$descriptions[\$type]", $manager);
    self::assertStringContainsString('card.description', $template);
    self::assertStringContainsString('Set up @label', $template);
  }

}
