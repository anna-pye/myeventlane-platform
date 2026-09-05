<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the event-scoped ticket-widget journey and its guidance.
 *
 * @group myeventlane_tickets
 */
final class PurchaseSurfaceWidgetExperienceContractTest extends TestCase {

  /**
   * Ensures organisers can reach widget routes without a legacy redirect.
   */
  public function testOrganisersAreNotRedirectedAwayFromWidgetRoutes(): void {
    $subscriber = file_get_contents(
      dirname(__DIR__, 4) . '/myeventlane_event_studio/src/EventSubscriber/VendorLegacyWizardRedirectSubscriber.php',
    );
    $this->assertIsString($subscriber);
    foreach ([
      'myeventlane_tickets.event_tickets_widgets',
      'myeventlane_tickets.event_tickets_widgets_add',
      'myeventlane_tickets.event_tickets_widgets_edit',
    ] as $route) {
      $this->assertStringNotContainsString("'{$route}'", $subscriber);
    }
  }

  /**
   * Ensures the interface explains what widgets do and do not do.
   */
  public function testWidgetPagesExplainTheSecureEventStudioBoundary(): void {
    $list = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-ticket-widgets.html.twig');
    $form = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-ticket-widget-form.html.twig');
    $this->assertIsString($list);
    $this->assertIsString($form);

    $this->assertStringContainsString('Keep booking secure', $list);
    $this->assertStringContainsString('does not create tickets', $list);
    $this->assertStringContainsString('data-mel-copy-target', $list);
    $this->assertStringContainsString('Tickets stay in Event Studio', $form);
    $this->assertStringContainsString('Prices, capacity, ticket sales and checkout', $form);
  }

  /**
   * Ensures the public script is limited to active, published event widgets.
   */
  public function testEmbedScriptOnlyServesActivePublishedEvents(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/PurchaseSurfaceEmbedController.php');
    $this->assertIsString($controller);

    $this->assertStringContainsString("'status' => 1", $controller);
    $this->assertStringContainsString('!$event->isPublished()', $controller);
    $this->assertStringContainsString("'entity.node.canonical'", $controller);
    $this->assertStringContainsString("'Access-Control-Allow-Origin' => '*'", $controller);
    $this->assertStringContainsString('View event and book', $controller);
  }

  /**
   * Ensures the organiser controls are responsive and copyable.
   */
  public function testAdminPresentationIsResponsiveAndCopyable(): void {
    $css = file_get_contents(dirname(__DIR__, 3) . '/css/purchase-surface-admin.css');
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/purchase-surface-admin.js');
    $this->assertIsString($css);
    $this->assertIsString($js);

    $this->assertStringContainsString('@media (max-width: 700px)', $css);
    $this->assertStringContainsString('min-height: 46px', $css);
    $this->assertStringContainsString('var(--mel-button-primary-bg, #c24132)', $css);
    $this->assertStringContainsString('var(--mel-button-primary-hover, #9f3126)', $css);
    $this->assertStringContainsString('navigator.clipboard.writeText', $js);
    $this->assertStringContainsString("Drupal.t('Copied')", $js);
  }

  /**
   * Ensures every ticket tool uses one complete, full-width navigation.
   */
  public function testTicketToolsShareTheSameWorkspaceNavigation(): void {
    $module_root = dirname(__DIR__, 3);
    $web_root = dirname(__DIR__, 6);
    $controller = file_get_contents($module_root . '/src/Controller/VendorEventTicketsBaseController.php');
    $workspace = file_get_contents($web_root . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $navigation = file_get_contents($web_root . '/themes/custom/myeventlane_vendor_theme/templates/components/workspace/workspace-tickets-sidebar.html.twig');
    $styles = file_get_contents($web_root . '/themes/custom/myeventlane_vendor_theme/src/scss/components/_workspace.scss');
    $this->assertIsString($controller);
    $this->assertIsString($workspace);
    $this->assertIsString($navigation);
    $this->assertIsString($styles);

    foreach (['Overview', 'Ticket types', 'Groups', 'Access codes', 'Settings', 'Widgets'] as $label) {
      $this->assertStringContainsString("'label' => \$this->t('{$label}')", $controller);
    }
    $this->assertStringNotContainsString("if (\$active_key === 'widgets')", $controller);
    $this->assertStringContainsString("'ticket_tools' => \$ticket_tools", $controller);
    $this->assertStringContainsString("'sidebar' => NULL", $controller);

    $this->assertStringContainsString('mel-workspace-ticket-quality', $workspace);
    $this->assertStringContainsString('{{ ticket_tools }}', $workspace);
    $this->assertStringContainsString('mel-workspace__main-panel--with-ticket-tools', $workspace);
    $this->assertStringContainsString('mel-workspace__tab-bar--after-work-area', $workspace);
    $this->assertStringContainsString('workspace-event-guidance.html.twig', $workspace);

    $this->assertStringContainsString('<nav class="mel-workspace-ticket-tools"', $navigation);
    $this->assertStringContainsString('aria-current="page"', $navigation);
    $this->assertStringContainsString('min-height: 2.75rem', $styles);
    $this->assertStringContainsString('grid-template-columns: minmax(180px, 220px) minmax(0, 1fr)', $styles);
    $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $styles);
  }

  /**
   * Ensures deletion stays scoped to the event in the route.
   */
  public function testDeleteFormChecksTheSelectedEvent(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/PurchaseSurfaceDeleteForm.php');
    $this->assertIsString($form);

    $this->assertStringContainsString('$route_event_id !== $entity->getEventId()', $form);
    $this->assertStringContainsString('NotFoundHttpException', $form);
    $this->assertStringContainsString("'myeventlane_tickets.event_tickets_widgets'", $form);
  }

}
