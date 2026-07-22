<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * IA contract tests for ticket sales placement and Manage event navigation.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioManageEventIaTest extends UnitTestCase {

  public function testExtrasFormDoesNotRenderTicketSalesPanel(): void {
    $contents = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventStudioEventExtrasForm.php');
    $this->assertIsString($contents);
    $this->assertStringNotContainsString('buildTicketSalesPanel', $contents);
    $this->assertStringNotContainsString("form['ticket_sales']", $contents);
    $this->assertStringNotContainsString('EventStudioCommerceSalesSummaryBuilder', $contents);
    $this->assertStringContainsString('View ticket sales in Overview', $contents);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace', $contents);
  }

  public function testStudioOverviewDoesNotRenderTicketSalesPanel(): void {
    $contents = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($contents);
    $this->assertStringNotContainsString('buildTicketSalesPanelRenderArray', $contents);
    $this->assertStringNotContainsString("build['ticket_sales']", $contents);
    $this->assertStringNotContainsString('EventStudioCommerceSalesSummaryBuilder', $contents);
  }

  public function testVendorEventWorkspaceRendersTicketSalesPanel(): void {
    $controller = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Controller/EventWorkspaceController.php');
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $this->assertIsString($controller);
    $this->assertIsString($twig);
    $this->assertStringContainsString('EventStudioCommerceSalesSummaryBuilder', $controller);
    $this->assertStringContainsString('EventOperationalExtrasSalesSummaryBuilder', $controller);
    $this->assertStringContainsString('buildTicketSalesPanelRenderArray', $controller);
    $this->assertStringContainsString('buildExtrasSalesPanelRenderArray', $controller);
    $this->assertStringContainsString('ticket_sales_panel', $controller);
    $this->assertStringContainsString('extras_sales_panel', $controller);
    $this->assertStringContainsString('ticket_sales_panel', $twig);
    $this->assertStringContainsString('extras_sales_panel', $twig);
    $this->assertStringContainsString('mel_event_studio_ticket_sales_panel', $controller);
    $this->assertStringContainsString('mel_event_studio_extras_sales_panel', $controller);
  }

  public function testVendorEventTabsUseOverviewLabel(): void {
    $tabs = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorEventTabsService.php');
    $tasks = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/myeventlane_vendor.links.task.yml');
    $this->assertIsString($tabs);
    $this->assertIsString($tasks);
    $this->assertStringContainsString('Overview', $tabs);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace', $tabs);
    $this->assertStringContainsString("title: 'Overview'", $tasks);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace', $tasks);
  }

  public function testManageEventBackLinkUsesWorkspaceRoute(): void {
    $theme = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $this->assertIsString($theme);
    $this->assertIsString($twig);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace', $theme);
    $this->assertStringContainsString('Back to Overview', $theme);
    $this->assertStringContainsString('manage_event_back', $twig);
  }

  public function testStudioTopBarOverviewLinkPointsToVendorWorkspace(): void {
    $topbar = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-topbar.html.twig');
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php');
    $shellCss = file_get_contents(dirname(__DIR__, 3) . '/css/mel-event-studio-shell.css');
    $this->assertIsString($topbar);
    $this->assertIsString($controller);
    $this->assertIsString($shellCss);
    $this->assertStringContainsString('show_manage_event_link', $topbar);
    $this->assertStringContainsString('Overview', $topbar);
    $this->assertStringContainsString('manage_event_url', $topbar);
    $this->assertStringContainsString("hasPermission('administer nodes')", $controller);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace', $controller);
    $this->assertStringContainsString('mel-event-studio-topbar__primary', $topbar);
    $this->assertStringContainsString('{% if topbar.state %}', $topbar);
    $this->assertStringContainsString('display: flex', $shellCss);
    $this->assertStringNotContainsString('grid-template-columns: minmax(0, 1fr) auto auto', $shellCss);
  }

  public function testCommerceSalesSummaryBuilderStillProvidesEditTicketsLink(): void {
    $contents = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioCommerceSalesSummaryBuilder.php');
    $this->assertIsString($contents);
    $this->assertStringContainsString('buildTicketSalesPanelRenderArray', $contents);
    $this->assertStringContainsString('myeventlane_event_studio.workspace_tickets', $contents);
    $this->assertStringContainsString('Edit tickets', $contents);
  }

  public function testVendorManageLinksPointToEventStudioWorkspace(): void {
    $card = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/node--event--vendor-card.html.twig');
    $indexBuilder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php');
    $dashboardBuilder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php');
    $this->assertIsString($card);
    $this->assertIsString($indexBuilder);
    $this->assertIsString($dashboardBuilder);
    $this->assertStringContainsString('myeventlane_event_studio.workspace', $card);
    $this->assertStringNotContainsString('myeventlane_vendor.console.event_overview', $card);
    $this->assertStringContainsString("'manage' => \$this->routeUrlIfAccessible('myeventlane_event_studio.workspace'", $indexBuilder);
    $this->assertStringContainsString("'manage' => \$this->safeUrlFromRoute('myeventlane_event_studio.workspace'", $dashboardBuilder);
  }

  public function testVendorEventWorkspaceRendersTodaysFocusRegion(): void {
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $builder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorEventWorkspaceViewModelBuilder.php');
    $this->assertIsString($twig);
    $this->assertIsString($builder);
    $this->assertStringContainsString('mel-event-mission-control', $twig);
    $this->assertStringContainsString('todays_focus', $twig);
    $this->assertStringContainsString("Today's focus", $twig);
    $this->assertStringContainsString('buildTodaysFocus', $builder);
    $this->assertStringContainsString('readiness_summary', $builder);
  }

  public function testVendorEventWorkspaceReadinessSummaryUsesDetails(): void {
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $this->assertIsString($twig);
    $this->assertStringContainsString('readiness_summary', $twig);
    $this->assertStringContainsString('Show all readiness checks', $twig);
    $this->assertStringContainsString('mel-event-mission-control__readiness-group', $twig);
    $this->assertStringNotContainsString('mel-ws-readiness-heading', $twig);
  }

  public function testVendorEventWorkspaceActionGridGrouped(): void {
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $builder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorEventWorkspaceViewModelBuilder.php');
    $this->assertIsString($twig);
    $this->assertIsString($builder);
    $this->assertStringContainsString('action_grid', $twig);
    $this->assertStringContainsString('grid_setup', $twig);
    $this->assertStringContainsString('buildActionGrid', $builder);
    $this->assertStringContainsString('Merch & add-ons', $builder);
    $this->assertStringContainsString('myeventlane_event_studio.workspace_extras', $builder);
  }

  public function testVendorEventWorkspaceRouteIsCanonicalManageEventSurface(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/myeventlane_vendor.routing.yml');
    $this->assertIsString($routing);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace:', $routing);
    $this->assertStringContainsString("path: '/vendor/events/{event}'", $routing);
    $this->assertStringContainsString('event_workspace:workspace', $routing);
  }

  public function testConfiguredExtrasSummaryBuilderExists(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasConfiguredSummaryBuilder.php');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-extras-configured-summary.html.twig');
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventStudioEventExtrasForm.php');
    $this->assertIsString($builder);
    $this->assertIsString($template);
    $this->assertIsString($form);
    $this->assertStringContainsString('has_products', $builder);
    $this->assertStringContainsString('empty_state', $builder);
    $this->assertStringContainsString('No extras yet', $builder);
    $this->assertStringContainsString('buildStudioAsideRenderArray', $builder);
    $this->assertStringContainsString('mel-event-extras-studio__layout', $form);
    $this->assertStringContainsString('buildStudioAsideRenderArray', $form);
    $this->assertStringContainsString('hide_manage_cta', $template);
    $this->assertStringContainsString('empty.title', $template);
  }

  public function testManageEventRendersConfiguredExtrasSummary(): void {
    $controller = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Controller/EventWorkspaceController.php');
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $this->assertIsString($controller);
    $this->assertIsString($twig);
    $this->assertStringContainsString('EventStudioExtrasConfiguredSummaryBuilder', $controller);
    $this->assertStringContainsString('extras_configured_panel', $controller);
    $this->assertStringContainsString('extras_configured_panel', $twig);
  }

  public function testStudioExtrasFormRendersFeeCopy(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventStudioEventExtrasForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString('PlatformFeeSettings', $form);
    $this->assertStringContainsString('fee_copy', $form);
    $this->assertStringContainsString('buildExtrasStudioFeeCopy', $form);
  }

  public function testAnalyticsIncludesSeparateCommerceMetrics(): void {
    $controller = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php');
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/event/analytics.html.twig');
    $this->assertIsString($controller);
    $this->assertIsString($twig);
    $this->assertStringContainsString('buildCommerceAnalytics', $controller);
    $this->assertStringContainsString('extras_revenue', $controller);
    $this->assertStringContainsString('commerce_analytics', $twig);
    $this->assertStringContainsString('All sales', $twig);
    $this->assertStringContainsString('Ticket sales', $twig);
    $this->assertStringContainsString('Merchandise & add-ons', $twig);
    $this->assertStringContainsString('Products sold', $twig);
    $this->assertStringContainsString('extras_product_rows', $controller);
    $this->assertStringContainsString('What to do next', $twig);
    $this->assertStringContainsString('all_sales_section', $twig);
    $this->assertStringContainsString('extras_revenue', $twig);
  }

  public function testEventManagementShellOnSubRoutes(): void {
    $twig = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig');
    $this->assertIsString($twig);
    $this->assertStringContainsString('mel-event-management-shell', $twig);
    $this->assertStringContainsString('mel-workspace__content--event-management', $twig);
  }

  public function testExtrasSalesPanelIncludesOperationalDocuments(): void {
    $builder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_commerce/src/Service/EventOperationalExtrasSalesSummaryBuilder.php');
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-extras-sales-panel.html.twig');
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventStudioEventExtrasForm.php');
    $this->assertIsString($builder);
    $this->assertIsString($twig);
    $this->assertIsString($form);
    $this->assertStringContainsString('buildOperationalDocumentsBlock', $builder);
    $this->assertStringContainsString('operational_documents', $builder);
    $this->assertStringContainsString('packing_slips', $builder);
    $this->assertStringContainsString('Document generation is coming soon', $builder);
    $this->assertStringContainsString('operational_documents', $twig);
    $this->assertStringContainsString('aria-disabled="true"', $twig);
    $this->assertStringNotContainsString('href="#"', $twig);
    $this->assertStringContainsString('operational_documents_note', $form);
    $this->assertStringContainsString('packing slips, parking slips, and labels from Overview', $form);
  }

  public function testOperationalDocumentsDoNotReferenceTicketPdfOrQr(): void {
    $builder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_commerce/src/Service/EventOperationalExtrasSalesSummaryBuilder.php');
    $this->assertIsString($builder);
    $this->assertStringNotContainsString('TicketPdf', $builder);
    $this->assertStringNotContainsString('QrCodeGenerator', $builder);
    $this->assertStringNotContainsString('TicketIssuer', $builder);
  }

  public function testExtrasProductEditorIncludesStockGuidanceAndOperationalDocsLink(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasProductEditorBuilder.php');
    $manager = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorOperationalProductCreationManager.php');
    $preview = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-extra-preview.html.twig');
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventStudioEventExtrasForm.php');
    $this->assertIsString($builder);
    $this->assertIsString($manager);
    $this->assertIsString($preview);
    $this->assertIsString($form);
    $this->assertStringContainsString('Blank stock = unlimited. 0 = sold out. Positive numbers are enforced.', $builder);
    $this->assertStringContainsString('Limit per order is optional.', $builder);
    $this->assertStringContainsString('Product options', $builder);
    $this->assertStringContainsString('Colour options are coming soon.', $builder);
    $this->assertStringContainsString('buildProductOptionsSection', $builder);
    $this->assertStringContainsString('buildOperationalDocumentsSection', $builder);
    $this->assertStringContainsString('Packing slips, parking slips and labels', $builder);
    $this->assertStringContainsString('Print tools live in Overview once this item starts selling', $builder);
    $this->assertStringContainsString('myeventlane_vendor.console.event_workspace', $builder);
    $this->assertStringContainsString('Back to Overview', $builder);
    $this->assertStringNotContainsString('packing_slip.pdf', $builder);
    $this->assertStringNotContainsString('print/download', $builder);
    $this->assertStringContainsString('syncVariationsFromProductOptions', $manager);
    $this->assertStringContainsString('options_summary', $preview);
    $this->assertStringContainsString('product_options', $form);
    $this->assertStringContainsString('Stock quantity', $builder);
    $this->assertStringContainsString('Limit per order', $builder);
    $this->assertStringContainsString('Show remaining', $builder);
    $this->assertStringContainsString('buildPreviewSection', $builder);
    $this->assertStringContainsString('stock_label', $preview);
    $this->assertStringContainsString('visibility_label', $preview);
    $this->assertStringContainsString('Preview only. Customers see this on the booking page.', $builder);
  }

  public function testStaffShellRetiredInFavorOfEventStudioRedirects(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/myeventlane_vendor.routing.yml');
    $this->assertIsString($routing);
    $this->assertStringContainsString('VendorStudioRedirectController::studio', $routing);
    $this->assertStringContainsString('VendorStudioRedirectController::eventEditor', $routing);
    $this->assertStringNotContainsString('VendorStudioController', $routing);
    $this->assertStringNotContainsString('VendorStudioSchemaController', $routing);
    $this->assertStringNotContainsString('studio.event_data', $routing);
    $this->assertStringNotContainsString('studio_event_schema', $routing);

    $libraries = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.libraries.yml');
    $this->assertIsString($libraries);
    $this->assertStringNotContainsString('vendor-studio.js', $libraries);

    $theme = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    $this->assertIsString($theme);
    $this->assertStringNotContainsString("'studio' =>", $theme);
    $this->assertStringContainsString("'route' => 'myeventlane_vendor.create_event_gateway'", $theme);
  }

  public function testExtrasProductEditorUsesSingleColumnLayoutAndStickyFooter(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasProductEditorBuilder.php');
    $css = file_get_contents(dirname(__DIR__, 3) . '/css/mel-event-extras-studio.css');
    $this->assertIsString($builder);
    $this->assertIsString($css);
    $this->assertStringContainsString('mel-event-product-editor__accordion', $builder);
    $this->assertStringContainsString("'#type' => 'details'", $builder);
    $this->assertStringContainsString('Stock and limits', $builder);
    $this->assertStringContainsString('mel-event-product-editor__footer', $builder);
    $this->assertStringContainsString('Save product', $builder);
    $this->assertStringContainsString('mel-event-product-editor__footer', $css);
  }

  public function testPlatformFeeSettingsDefaultsExtrasToDoubleTicket(): void {
    $settings = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/src/Service/PlatformFeeSettings.php');
    $processor = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_commerce/src/OrderProcessor/PlatformFeeOrderProcessor.php');
    $this->assertIsString($settings);
    $this->assertIsString($processor);
    $this->assertStringContainsString('EXTRAS_FEE_TICKET_MULTIPLIER', $settings);
    $this->assertStringContainsString('myeventlane_operational_extras_platform_fee', $processor);
  }

}
