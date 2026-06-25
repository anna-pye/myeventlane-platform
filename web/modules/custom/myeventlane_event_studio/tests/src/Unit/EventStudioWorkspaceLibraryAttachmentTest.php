<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workspace vs legacy Event Studio library attachment contracts.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioWorkspaceLibraryAttachmentTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    parent::setUp();
    $this->moduleRoot = dirname(__DIR__, 3);
  }

  public function testWorkspaceRouteHelperExistsAndListsCoreSections(): void {
    $module = file_get_contents($this->moduleRoot . '/myeventlane_event_studio.module');
    $this->assertIsString($module);
    $this->assertStringContainsString('function _myeventlane_event_studio_is_workspace_route', $module);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_information'", $module);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_tickets'", $module);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_settings'", $module);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_add_ons'", $module);
  }

  public function testBaseFormSkipsWizardNavOnWorkspaceRoutes(): void {
    $baseForm = file_get_contents($this->moduleRoot . '/src/Form/EventStudioBaseForm.php');
    $this->assertIsString($baseForm);
    $this->assertStringContainsString('_myeventlane_event_studio_is_workspace_route()', $baseForm);
    $this->assertMatchesRegularExpression(
      '/if\s*\(!_myeventlane_event_studio_is_workspace_route\(\)\)\s*\{[\s\S]*mel_event_studio_wizard_nav/s',
      $baseForm,
    );
  }

  public function testCheckoutQuestionsFormDoesNotRenderWizardNav(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/EventCheckoutQuestionsForm.php');
    $this->assertIsString($form);
    $this->assertStringNotContainsString('mel_event_studio_wizard_nav', $form);
    $this->assertStringNotContainsString('EventStudioBaseForm', $form);
  }

  public function testBaseFormSkipsLegacyBundleOnWorkspaceRoutes(): void {
    $baseForm = file_get_contents($this->moduleRoot . '/src/Form/EventStudioBaseForm.php');
    $this->assertIsString($baseForm);
    $this->assertStringContainsString('_myeventlane_event_studio_is_workspace_route()', $baseForm);
    $this->assertStringContainsString('myeventlane_event_studio/mel_event_studio_ai_assist', $baseForm);
    $this->assertStringContainsString('_myeventlane_event_studio_workspace_ai_assist_section_ids()', $baseForm);
    $this->assertMatchesRegularExpression(
      '/if\s*\(_myeventlane_event_studio_is_workspace_route\(\)\)\s*\{[\s\S]*else\s*\{[\s\S]*myeventlane_event_studio\/mel_event_studio/s',
      $baseForm,
    );
  }

  public function testCheckoutQuestionsFormSkipsLegacyBundleOnWorkspace(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/EventCheckoutQuestionsForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString('_myeventlane_event_studio_is_workspace_route()', $form);
    $this->assertStringContainsString('if (!_myeventlane_event_studio_is_workspace_route())', $form);
  }

  public function testWorkspaceControllerStillAttachesShellOnly(): void {
    $controller = file_get_contents($this->moduleRoot . '/src/Controller/EventStudioController.php');
    $this->assertIsString($controller);
    $this->assertStringContainsString('myeventlane_event_studio/mel_event_studio_shell_only', $controller);
    $this->assertDoesNotMatchRegularExpression(
      "/['\"]library['\"]\s*=>\s*\[['\"]myeventlane_event_studio\/mel_event_studio['\"]\]/",
      $controller,
    );
  }

  public function testLegacyEditRoutesRedirectToWorkspaceSections(): void {
    $routing = file_get_contents($this->moduleRoot . '/myeventlane_event_studio.routing.yml');
    $controller = file_get_contents($this->moduleRoot . '/src/Controller/EventStudioController.php');
    $this->assertIsString($routing);
    $this->assertIsString($controller);
    $this->assertStringContainsString('redirectLegacyEditToInformation', $routing);
    $this->assertStringContainsString('redirectLegacyEditToTickets', $routing);
    $this->assertStringContainsString('redirectLegacyEditToContent', $routing);
    $this->assertStringContainsString('redirectLegacyEditToSettings', $routing);
    $this->assertStringNotContainsString('EventStudioBasicForm', $routing);
    $this->assertStringNotContainsString('EventStudioPreviewController', $routing);
    $this->assertStringContainsString('public function redirectLegacyEditToSettings(', $controller);
  }

  public function testAiAssistLibraryIsStandalone(): void {
    $libraries = file_get_contents($this->moduleRoot . '/myeventlane_event_studio.libraries.yml');
    $this->assertIsString($libraries);
    $this->assertStringContainsString('mel_event_studio_ai_assist:', $libraries);
    $this->assertStringContainsString('js/mel-event-studio-ai-assist.js', $libraries);
    $this->assertStringContainsString('myeventlane_event_studio/mel_event_studio_ai_assist', $libraries);
    $this->assertFileExists($this->moduleRoot . '/js/mel-event-studio-ai-assist.js');
  }

  public function testInformationWorkspaceLocationLibraryIsDefined(): void {
    $libraries = file_get_contents($this->moduleRoot . '/myeventlane_event_studio.libraries.yml');
    $information = file_get_contents($this->moduleRoot . '/src/Form/EventInformationForm.php');
    $this->assertIsString($libraries);
    $this->assertIsString($information);
    $this->assertStringContainsString('mel_event_studio_workspace_location:', $libraries);
    $this->assertStringContainsString('js/mel-event-studio-workspace-location.js', $libraries);
    $this->assertStringContainsString('myeventlane_location/address_autocomplete', $libraries);
    $this->assertStringContainsString('myeventlane_event_studio/mel_event_studio_workspace_location', $information);
    $this->assertFileExists($this->moduleRoot . '/js/mel-event-studio-workspace-location.js');
  }

  public function testBrandingAndTicketPreviewLibrariesUnchanged(): void {
    $branding = file_get_contents($this->moduleRoot . '/src/Form/EventBrandingForm.php');
    $preview = file_get_contents($this->moduleRoot . '/src/Service/EventTicketPreviewBuilder.php');
    $this->assertIsString($branding);
    $this->assertIsString($preview);
    $this->assertStringContainsString('myeventlane_event_studio/mel_branding_workspace', $branding);
    $this->assertStringContainsString('myeventlane_event_studio/mel_branding_hero_tools', $branding);
    $this->assertStringNotContainsString('myeventlane_event_studio/mel_event_studio', $branding);
    $this->assertStringContainsString('myeventlane_event_studio/mel_ticket_preview', $preview);

    $libraries = file_get_contents($this->moduleRoot . '/myeventlane_event_studio.libraries.yml');
    $this->assertIsString($libraries);
    $this->assertStringContainsString('mel_branding_workspace:', $libraries);
    $this->assertStringContainsString('css/mel-event-studio-branding.css', $libraries);
    $this->assertFileExists($this->moduleRoot . '/css/mel-event-studio-branding.css');
  }

}
