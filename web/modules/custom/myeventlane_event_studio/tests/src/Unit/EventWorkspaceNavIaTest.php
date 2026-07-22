<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Contract tests for VX2 One Event Workspace navigation IA.
 *
 * @group myeventlane_event_studio
 */
final class EventWorkspaceNavIaTest extends UnitTestCase {

  /**
   * Confirms primary section plugins match Convergence order and labels.
   */
  public function testPrimarySectionsMatchConvergenceOrder(): void {
    $dir = dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection';
    $expected = [
      'overview' => ['Overview', 0, TRUE],
      'information' => ['Details', 10, TRUE],
      'schedule' => ['Schedule', 20, TRUE],
      'venue' => ['Venue', 30, TRUE],
      'branding' => ['Images', 40, TRUE],
      'tickets' => ['Tickets', 50, TRUE],
      'attendees' => ['Attendees', 60, TRUE],
      'messaging' => ['Messages', 70, TRUE],
      'marketing' => ['Marketing', 80, TRUE],
      'orders' => ['Orders', 90, TRUE],
      'analytics' => ['Analytics', 100, TRUE],
      'publishing' => ['Publishing', 110, TRUE],
      'settings' => ['Settings', 120, TRUE],
    ];

    foreach ($expected as $id => [$title, $weight, $visible]) {
      $file = match ($id) {
        'information' => 'InformationSection.php',
        'branding' => 'BrandingSection.php',
        'messaging' => 'MessagingSection.php',
        default => ucfirst($id) . 'Section.php',
      };
      $contents = file_get_contents($dir . '/' . $file);
      $this->assertIsString($contents, $file);
      $this->assertStringContainsString("title: '$title'", $contents);
      $this->assertStringContainsString("weight: $weight", $contents);
      $this->assertStringContainsString("group: 'Workspace'", $contents);
      if ($visible) {
        $this->assertStringNotContainsString('navigationVisible: FALSE', $contents);
      }
    }
  }

  /**
   * Confirms advanced sections stay off the primary Workspace nav.
   */
  public function testAdvancedSectionsHiddenFromPrimaryNav(): void {
    $files = [
      'ContentSection.php',
      'QuestionsSection.php',
      'CapacitySection.php',
      'ExtrasSection.php',
      'FulfilmentSection.php',
    ];
    foreach ($files as $file) {
      $contents = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection/' . $file);
      $this->assertIsString($contents);
      $this->assertStringContainsString('navigationVisible: FALSE', $contents);
    }
  }

  /**
   * Confirms shell copy uses Event Workspace language.
   */
  public function testShellUsesEventWorkspaceLanguage(): void {
    $topbar = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-topbar.html.twig');
    $sidebar = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-sidebar.html.twig');
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php');
    $this->assertIsString($topbar);
    $this->assertIsString($sidebar);
    $this->assertIsString($controller);
    $this->assertStringContainsString('Event Workspace', $topbar);
    $this->assertStringContainsString('Event breadcrumb', $topbar);
    $this->assertStringContainsString('View page', $topbar);
    $this->assertStringContainsString('Event workspace', $sidebar);
    $this->assertStringContainsString('Event Workspace', $controller);
    $this->assertStringNotContainsString("'Event Studio'|t", $topbar);
  }

  /**
   * Confirms Convergence route aliases exist.
   */
  public function testWorkspaceRoutesIncludeConvergenceAliases(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.routing.yml');
    $this->assertIsString($routing);
    $routes = [
      'workspace_schedule',
      'workspace_venue',
      'workspace_marketing',
      'workspace_publishing',
      'workspace_details',
      'workspace_images',
      'workspace_messages',
    ];
    foreach ($routes as $route) {
      $this->assertStringContainsString("myeventlane_event_studio.$route:", $routing);
    }
  }

}
