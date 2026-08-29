<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards organiser navigation, Pro management, and Studio focus mode.
 *
 * @group myeventlane_vendor
 */
final class OrganiserShellFocusContractTest extends TestCase {

  public function testProManagementUsesCanonicalRoute(): void {
    $webRoot = dirname(__DIR__, 6);
    $navBuilder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorNavBuilder.php',
    );
    $dashboard = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig',
    );
    $services = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml',
    );

    self::assertStringContainsString("'myeventlane_pro.manage'", $navBuilder);
    self::assertStringContainsString('pro_membership.manage_url', $dashboard);
    self::assertStringContainsString("'Manage subscription'|t", $dashboard);
    self::assertStringContainsString("'@?myeventlane_pro.subscription_status'", $services);
  }

  public function testStudioUsesCollapsibleEventWorkspaceNavigation(): void {
    $webRoot = dirname(__DIR__, 6);
    $pageTemplate = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/layout/page.html.twig',
    );
    $studioSidebar = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_event_studio/templates/mel-event-studio-sidebar.html.twig',
    );
    $shellHeader = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/includes/vendor-shell-header.html.twig',
    );
    $workspace = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_event_studio/templates/mel-event-studio-workspace.html.twig',
    );
    $workspaceScript = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_event_studio/js/mel-event-studio-shell.js',
    );

    self::assertStringContainsString('mel-vendor-shell--studio-focus', $pageTemplate);
    self::assertStringContainsString('data-sidebar-toggle', $shellHeader);
    self::assertStringContainsString('Event Workspace sections', $studioSidebar);
    self::assertStringContainsString('item.url', $studioSidebar);
    self::assertStringContainsString('data-mel-studio-sidebar-toggle', $workspace);
    self::assertStringContainsString('is-sidebar-collapsed', $workspaceScript);
  }

  public function testOrganiserShellUsesApprovedLogoAsset(): void {
    $webRoot = dirname(__DIR__, 6);
    $sidebar = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/includes/sidebar.html.twig',
    );
    $header = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/includes/vendor-shell-header.html.twig',
    );
    $logo = $webRoot . '/themes/custom/myeventlane_vendor_theme/images/myeventlane-logo-icon.png';

    self::assertFileExists($logo);
    self::assertStringContainsString('images/myeventlane-logo-icon.png', $sidebar);
    self::assertStringContainsString('images/myeventlane-logo-icon.png', $header);
    self::assertStringNotContainsString('<span class="mel-sidebar__logo-mark">M</span>', $sidebar);
  }

  public function testMobileSidebarOverlayIsInteractiveOnlyWhenVisible(): void {
    $webRoot = dirname(__DIR__, 6);
    $page = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/layout/page.html.twig',
    );
    $navigation = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/layout/_navigation.scss',
    );

    self::assertStringNotContainsString('style="pointer-events:none;"', $page);
    self::assertStringContainsString('&.is-visible {', $navigation);
    self::assertStringContainsString('pointer-events: auto;', $navigation);
  }

  public function testStudioContentUsesWiderLeftAlignedDesktopLayout(): void {
    $webRoot = dirname(__DIR__, 6);
    $studioStyles = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_event_studio/css/mel-event-studio-shell.css',
    );

    self::assertStringContainsString('@media (min-width: 1121px)', $studioStyles);
    self::assertStringContainsString('--mel-es-content-width: 64rem;', $studioStyles);
    self::assertStringContainsString('align-items: flex-start;', $studioStyles);
    self::assertStringContainsString('.mel-event-studio-wizard-form.mel-form', $studioStyles);
    self::assertStringContainsString('max-width: 64rem;', $studioStyles);
  }

  public function testMobileShellCannotGrowPastTheViewport(): void {
    $webRoot = dirname(__DIR__, 6);
    $navigation = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/layout/_navigation.scss',
    );
    $support = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_help_centre/css/mel-support-components.css',
    );

    self::assertStringContainsString('box-sizing: border-box;', $navigation);
    self::assertStringContainsString('width: 100%;', $navigation);
    self::assertStringContainsString('.mel-shell-header__left {', $navigation);
    self::assertStringContainsString('min-width: 0;', $navigation);
    self::assertStringContainsString('.mel-mel-support-floating {', $support);
    self::assertStringContainsString('position: static;', $support);
  }

}
