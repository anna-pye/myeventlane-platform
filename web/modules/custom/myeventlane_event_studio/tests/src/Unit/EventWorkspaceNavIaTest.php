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
   * Confirms primary section plugins match the approved groups and labels.
   */
  public function testPrimarySectionsMatchApprovedStudioGroups(): void {
    $dir = dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection';
    $expected = [
      'overview' => ['Home', 'Set up', 0],
      'information' => ['Details', 'Set up', 10],
      'schedule' => ['Schedule', 'Set up', 20],
      'venue' => ['Venue/Location', 'Set up', 30],
      'branding' => ['Branding', 'Set up', 40],
      'content' => ['Content', 'Set up', 50],
      'publishing' => ['Publishing', 'Set up', 60],
      'tickets' => ['Ticketing', 'Sales', 10],
      'extras' => ['Merch & add-ons', 'Sales', 20],
      'fulfilment' => ['Collection', 'Sales', 30],
      'orders' => ['Orders', 'Sales', 40],
      'attendees' => ['Attendees', 'Run the event', 10],
      'messaging' => ['Messages', 'Run the event', 20],
      'marketing' => ['Marketing', 'Run the event', 30],
      'analytics' => ['Analytics', 'Run the event', 40],
      'settings' => ['Settings', 'Run the event', 50],
    ];

    foreach ($expected as $id => [$title, $group, $weight]) {
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
      $this->assertStringContainsString("group: '$group'", $contents);
      $this->assertStringNotContainsString('navigationVisible: FALSE', $contents);
    }
  }

  /**
   * Confirms duplicate and advanced sales routes stay off the primary nav.
   */
  public function testAdvancedSectionsHiddenFromPrimaryNav(): void {
    $files = [
      'QuestionsSection.php',
      'CapacitySection.php',
      'MerchandiseSection.php',
      'AddonsSection.php',
    ];
    foreach ($files as $file) {
      $contents = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection/' . $file);
      $this->assertIsString($contents);
      $this->assertStringContainsString('navigationVisible: FALSE', $contents);
    }
  }

  /**
   * Confirms the shell orders approved navigation groups consistently.
   */
  public function testApprovedGroupOrderIsExplicit(): void {
    $base = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection/EventStudioSectionBase.php');
    $this->assertIsString($base);
    $this->assertStringContainsString("'Set up' => 0", $base);
    $this->assertStringContainsString("'Sales' => 100", $base);
    $this->assertStringContainsString("'Run the event' => 200", $base);
  }

  /**
   * Confirms sales tools remain routes for the selected event's Studio.
   */
  public function testSalesRoutesRemainInsideSelectedEventStudio(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.routing.yml');
    $this->assertIsString($routing);

    foreach (['tickets', 'extras', 'fulfilment', 'orders'] as $section) {
      $this->assertStringContainsString("myeventlane_event_studio.workspace_$section:", $routing);
      $this->assertStringContainsString("path: '/vendor/events/{node}/studio/$section'", $routing);
      $this->assertStringContainsString("section: '$section'", $routing);
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
    $this->assertStringContainsString("'View'|t", $topbar);
    $this->assertStringContainsString('Event Workspace', $sidebar);
    $this->assertStringContainsString('has_group_heading', $sidebar);
    $this->assertStringContainsString('Event Workspace', $controller);
    $this->assertStringNotContainsString("'Event Studio'|t", $topbar);
  }

  /**
   * Confirms shared information routes land on their matching field groups.
   */
  public function testSharedInformationRoutesUseStableFieldGroupBookmarks(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $manager = file_get_contents($moduleRoot . '/src/EventStudioSectionManager.php');
    $form = file_get_contents($moduleRoot . '/src/Form/EventInformationForm.php');
    $legacyForm = file_get_contents($moduleRoot . '/src/Form/EventStudioForm.php');
    $css = file_get_contents($moduleRoot . '/css/mel-event-studio-shell.css');

    $this->assertIsString($manager);
    $this->assertStringContainsString("'information' => 'mel-es-details'", $manager);
    $this->assertStringContainsString("'schedule' => 'mel-es-schedule'", $manager);
    $this->assertStringContainsString("'venue' => 'mel-es-venue-location'", $manager);
    $this->assertStringContainsString("\$url_options['fragment']", $manager);

    $this->assertIsString($form);
    $this->assertStringContainsString('id="mel-es-details"', $form);
    $this->assertStringContainsString('id="mel-es-schedule"', $form);
    $this->assertStringContainsString('id="mel-es-venue-location"', $form);
    $this->assertStringContainsString("'fragment' => \$this->resolveStayFragment(\$stay_route)", $form);
    $this->assertStringContainsString("\$this->t('Venue/Location')", $form);

    $this->assertIsString($legacyForm);
    $this->assertStringContainsString("\$this->t('Venue/Location')", $legacyForm);

    $this->assertIsString($css);
    $this->assertStringContainsString(
      ':is(#mel-es-details, #mel-es-schedule, #mel-es-venue-location)',
      $css,
    );
    $this->assertStringContainsString('scroll-margin-top: 8rem;', $css);
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
