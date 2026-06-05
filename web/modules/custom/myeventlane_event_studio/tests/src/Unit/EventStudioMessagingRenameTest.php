<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the promotions -> messaging section rename (MEL-UX-042).
 *
 * Keeps the canonical messaging route, the legacy redirect, the section
 * plugin id, and the form wiring aligned so the rename cannot silently drift.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioMessagingRenameTest extends TestCase {

  private function moduleDir(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The canonical messaging route and the legacy promotions redirect coexist.
   */
  public function testRoutingDefinesMessagingRouteAndLegacyRedirect(): void {
    $routing = file_get_contents($this->moduleDir() . '/myeventlane_event_studio.routing.yml');
    $this->assertIsString($routing);

    // Canonical route renamed to messaging.
    $this->assertStringContainsString('myeventlane_event_studio.workspace_messaging:', $routing);
    $this->assertStringContainsString("path: '/vendor/events/{node}/studio/messaging'", $routing);
    $this->assertStringContainsString("section: 'messaging'", $routing);

    // Legacy promotions path is preserved as a redirect, not the live workspace.
    $this->assertStringContainsString('myeventlane_event_studio.workspace_promotions:', $routing);
    $this->assertStringContainsString("path: '/vendor/events/{node}/studio/promotions'", $routing);
    $this->assertStringContainsString('EventStudioController::redirectToMessagingWorkspace', $routing);

    // The old live wiring must be gone.
    $this->assertStringNotContainsString("section: 'promotions'", $routing);
    $this->assertStringNotContainsString("path: '/vendor/events/{node}/studio/promotions'\n  defaults:\n    _controller: '\\Drupal\\myeventlane_event_studio\\Controller\\EventStudioController::workspace'", $routing);
  }

  /**
   * The redirect controller target exists and points at the messaging route.
   */
  public function testRedirectControllerTargetsMessaging(): void {
    $controller = file_get_contents($this->moduleDir() . '/src/Controller/EventStudioController.php');
    $this->assertIsString($controller);
    $this->assertStringContainsString('public function redirectToMessagingWorkspace(', $controller);
    $this->assertStringContainsString("Url::fromRoute('myeventlane_event_studio.workspace_messaging'", $controller);
  }

  /**
   * The section plugin is renamed to messaging and wired to the new route/form.
   */
  public function testMessagingSectionPluginContract(): void {
    $section = $this->moduleDir() . '/src/Plugin/EventStudioSection/MessagingSection.php';
    $this->assertFileExists($section);
    $this->assertFileDoesNotExist($this->moduleDir() . '/src/Plugin/EventStudioSection/PromotionsSection.php');

    $contents = file_get_contents($section);
    $this->assertIsString($contents);
    $this->assertStringContainsString('final class MessagingSection', $contents);
    $this->assertStringContainsString("id: 'messaging'", $contents);
    $this->assertStringContainsString("routeName: 'myeventlane_event_studio.workspace_messaging'", $contents);
    $this->assertStringContainsString('form:Drupal\myeventlane_event_studio\Form\MessagingForm', $contents);
  }

  /**
   * The section form is renamed and redirects back to the messaging route.
   */
  public function testMessagingFormWiring(): void {
    $form = $this->moduleDir() . '/src/Form/MessagingForm.php';
    $this->assertFileExists($form);
    $this->assertFileDoesNotExist($this->moduleDir() . '/src/Form/EventPromotionForm.php');

    $contents = file_get_contents($form);
    $this->assertIsString($contents);
    $this->assertStringContainsString('final class MessagingForm', $contents);
    $this->assertStringContainsString("return 'myeventlane_event_studio.workspace_messaging';", $contents);
    $this->assertStringContainsString("return 'messaging';", $contents);
  }

}
