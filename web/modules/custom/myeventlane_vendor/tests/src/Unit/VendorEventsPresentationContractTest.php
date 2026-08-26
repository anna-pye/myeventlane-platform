<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the organiser event-index presentation hierarchy.
 *
 * @group myeventlane_vendor
 */
final class VendorEventsPresentationContractTest extends TestCase {

  public function testEventCardsLeadToWorkspaceAndDiscloseSecondaryActions(): void {
    $webRoot = dirname(__DIR__, 6);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig',
    );

    self::assertStringContainsString("{{ 'Open workspace'|t }}", $template);
    self::assertStringNotContainsString("{{ 'Manage'|t }}", $template);
    self::assertStringContainsString('<details class="mel-vendor-events-v2__more-actions">', $template);
    self::assertStringContainsString("{{ 'More actions'|t }}", $template);
    self::assertStringContainsString("ev.status|default('') in ['ended', 'cancelled', 'archived']", $template);

    foreach (['links.edit', 'links.tickets', 'links.rsvps', 'links.orders', 'links.attendees', 'links.analytics'] as $link) {
      self::assertStringContainsString($link, $template);
    }
    self::assertStringContainsString('mel-event-card-removal-dialog.html.twig', $template);
  }

  public function testEventGridUsesTwoColumnsWithoutAThreeColumnOverride(): void {
    $webRoot = dirname(__DIR__, 6);
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/pages/_vendor-events.scss',
    );

    self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $styles);
    self::assertStringNotContainsString('repeat(3, 1fr)', $styles);
    self::assertStringContainsString('@media (max-width: 480px)', $styles);
    self::assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $styles);
  }

  public function testEventIndexDefaultsToNewestCreated(): void {
    $webRoot = dirname(__DIR__, 6);
    $controller = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php',
    );
    $builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php',
    );

    self::assertStringContainsString("\$request->query->get('sort') ?? 'created'", $controller);
    self::assertStringContainsString("'_created' => \$node->getCreatedTime()", $builder);
    self::assertStringContainsString("(int) (\$b['_created'] ?? 0) <=> (int) (\$a['_created'] ?? 0)", $builder);
    self::assertStringContainsString("'Newest created'", $builder);
  }

  /**
   * Ensures the canonical page cannot regain the legacy bulk form.
   */
  public function testCanonicalEventIndexDoesNotRenderLegacyBulkManagement(): void {
    $webRoot = dirname(__DIR__, 6);
    $controller = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php',
    );
    $services = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml',
    );

    $serviceStart = strpos($services, '  myeventlane_vendor.controller.vendor_events:');
    $serviceEnd = strpos($services, "\n  myeventlane_vendor.controller.vendor_event_create:", $serviceStart);
    self::assertNotFalse($serviceStart);
    self::assertNotFalse($serviceEnd);
    $serviceDefinition = substr($services, $serviceStart, $serviceEnd - $serviceStart);

    self::assertStringContainsString("'#theme' => 'myeventlane_vendor_events_grid'", $controller);
    self::assertStringNotContainsString('VendorEventsBulkActionsForm', $controller);
    self::assertStringNotContainsString('EntityTypeManagerInterface', $controller);
    self::assertStringNotContainsString('FormBuilderInterface', $controller);
    self::assertStringNotContainsString("'bulk' =>", $controller);
    self::assertStringContainsString("'@myeventlane_vendor.event_index_view_model_builder'", $serviceDefinition);
    self::assertStringNotContainsString("'@entity_type.manager'", $serviceDefinition);
    self::assertStringNotContainsString("'@form_builder'", $serviceDefinition);
  }

  /**
   * Ensures the event index consumes and presents canonical lifecycle states.
   */
  public function testEventIndexUsesCanonicalLifecycleStates(): void {
    $webRoot = dirname(__DIR__, 6);
    $builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php',
    );
    $services = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml',
    );
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig',
    );

    $serviceStart = strpos($services, '  myeventlane_vendor.event_index_view_model_builder:');
    $serviceEnd = strpos($services, "\n  myeventlane_vendor.dashboard_view_model_builder:", $serviceStart);
    self::assertNotFalse($serviceStart);
    self::assertNotFalse($serviceEnd);
    $serviceDefinition = substr($services, $serviceStart, $serviceEnd - $serviceStart);

    self::assertStringContainsString('LifecycleStateResolverInterface $lifecycleStateResolver', $builder);
    self::assertStringContainsString('$this->lifecycleStateResolver->resolveState($node)', $builder);
    self::assertStringNotContainsString('resolvePublicationStatus', $builder);
    self::assertStringContainsString("'@myeventlane_core.event_state_resolver'", $serviceDefinition);
    self::assertStringContainsString("'@myeventlane_event_state.resolver'", $serviceDefinition);
    self::assertStringNotContainsString("'@datetime.time'", $serviceDefinition);

    foreach (['Draft', 'Upcoming', 'Live', 'Sold out', 'Completed', 'Cancelled', 'Archived'] as $label) {
      self::assertStringContainsString("\$this->t('{$label}')", $builder);
    }

    self::assertStringContainsString("'published' => 'current'", $builder);
    self::assertStringContainsString("'past' => 'ended'", $builder);
    self::assertStringContainsString("['scheduled', 'live', 'sold_out']", $builder);
    self::assertStringContainsString("\$isTerminal = in_array(\$status, ['ended', 'cancelled', 'archived'], TRUE)", $builder);
    self::assertStringContainsString('$needsAttention = !$isTerminal', $builder);
    self::assertStringContainsString("summary.current|default(0)", $template);
    self::assertStringNotContainsString("summary.published|default(0)", $template);
  }

}
