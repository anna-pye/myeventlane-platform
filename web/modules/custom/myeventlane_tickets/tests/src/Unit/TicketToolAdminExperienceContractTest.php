<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the shared event-scoped Ticketing tools experience.
 *
 * @group myeventlane_tickets
 */
final class TicketToolAdminExperienceContractTest extends TestCase {

  /**
   * Gets the custom module root path.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Ensures all tool pages load the same responsive presentation library.
   */
  public function testSharedTicketToolsLibraryAndTemplatesExist(): void {
    $libraries = Yaml::parseFile($this->moduleRoot() . '/myeventlane_tickets.libraries.yml');
    $base_controller = file_get_contents($this->moduleRoot() . '/src/Controller/VendorEventTicketsBaseController.php');
    $collection = file_get_contents($this->moduleRoot() . '/templates/mel-event-ticket-tool-collection.html.twig');
    $form = file_get_contents($this->moduleRoot() . '/templates/mel-event-ticket-tool-form.html.twig');
    $css = file_get_contents($this->moduleRoot() . '/css/ticket-tools-admin.css');

    $this->assertArrayHasKey('ticket_tools_admin', $libraries);
    $this->assertSame('css/ticket-tools-admin.css', array_key_first($libraries['ticket_tools_admin']['css']['theme']));
    $this->assertIsString($base_controller);
    $this->assertStringContainsString('myeventlane_tickets/ticket_tools_admin', $base_controller);
    $this->assertIsString($collection);
    $this->assertStringContainsString('mel-ticket-tool-guide', $collection);
    $this->assertIsString($form);
    $this->assertStringContainsString('mel-ticket-tool-form-layout', $form);
    $this->assertIsString($css);
    $this->assertStringContainsString('@media (max-width: 700px)', $css);
    $this->assertStringContainsString('max-width: none', $css);
  }

  /**
   * Ensures add, edit and delete forms remain in their selected event shell.
   */
  public function testToolFormsUseTheSharedWorkspaceWrapper(): void {
    $controller = file_get_contents($this->moduleRoot() . '/src/Controller/EventTicketsController.php');
    $this->assertIsString($controller);

    foreach ([
      'groupsAdd',
      'groupsEdit',
      'groupsDelete',
      'accessCodesAdd',
      'accessCodesEdit',
      'accessCodesDelete',
      'widgetsDelete',
    ] as $method) {
      $this->assertStringContainsString("function {$method}", $controller);
    }
    $this->assertStringContainsString("'#theme' => 'mel_event_ticket_tool_form'", $controller);
    $this->assertStringContainsString('$mel_ticket_group->getEventId() !== (int) $event->id()', $controller);
    $this->assertStringContainsString('$mel_access_code->getEventId() !== (int) $event->id()', $controller);
    $this->assertStringContainsString('$mel_purchase_surface->getEventId() !== (int) $event->id()', $controller);
  }

  /**
   * Ensures delete routes use the workspace controller and event access check.
   */
  public function testDeleteRoutesStayInTicketToolsWorkspace(): void {
    $routes = Yaml::parseFile($this->moduleRoot() . '/myeventlane_tickets.routing.yml');
    $expected = [
      'entity.mel_ticket_group.delete_form' => 'groupsDelete',
      'entity.mel_access_code.delete_form' => 'accessCodesDelete',
      'entity.mel_purchase_surface.delete_form' => 'widgetsDelete',
    ];

    foreach ($expected as $route_name => $method) {
      $route = $routes[$route_name];
      $this->assertStringEndsWith("::{$method}", $route['defaults']['_controller'] ?? '');
      $this->assertSame(
        'myeventlane_tickets.access.event_tickets:access',
        $route['requirements']['_custom_access'] ?? NULL,
      );
      $this->assertArrayNotHasKey('_entity_form', $route['defaults']);
    }
  }

  /**
   * Ensures event-scoped forms cannot be reassigned to another event.
   */
  public function testSelectedEventIsHiddenAndBooleanChoicesRemainOptional(): void {
    $settings = file_get_contents($this->moduleRoot() . '/src/Form/EventTicketSettingsForm.php');
    $access_code = file_get_contents($this->moduleRoot() . '/src/Form/AccessCodeForm.php');
    $group = file_get_contents($this->moduleRoot() . '/src/Form/TicketGroupForm.php');

    $this->assertIsString($settings);
    $this->assertStringContainsString("\$form['event']['#access'] = FALSE", $settings);
    $this->assertIsString($access_code);
    $this->assertStringContainsString("\$form['event']['#access'] = FALSE", $access_code);
    $this->assertIsString($group);
    $this->assertStringContainsString("\$form['status']['widget']['value']['#required'] = FALSE", $group);
  }

}
