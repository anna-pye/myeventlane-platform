<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_notifications\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\myeventlane_notifications\Service\NotificationViewBuilder;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/src/Service/NotificationViewBuilder.php';

/**
 * Protects the organiser route, access and interaction contracts.
 */
final class OrganiserActionCentreContractTest extends TestCase {

  /**
   * Ensures organiser routes retain their access and theme boundaries.
   */
  public function testRoutesUseOrganiserAccessAndVendorTheme(): void {
    $routing = (string) file_get_contents(dirname(__DIR__, 3) . '/myeventlane_notifications.routing.yml');

    self::assertStringContainsString("myeventlane_notifications.organiser_action_centre:\n  path: '/vendor/updates'", $routing);
    self::assertStringContainsString("_custom_access: 'myeventlane_vendor.access.vendor_console:access'", $routing);
    self::assertStringContainsString("_custom_access: '\\Drupal\\myeventlane_event_studio\\Access\\EventStudioAccess::access'", $routing);
    self::assertSame(3, substr_count($routing, '_theme: myeventlane_vendor_theme'));
  }

  /**
   * Ensures the organiser Action Centre is reachable from the left navigation.
   */
  public function testOrganiserNavigationExposesActionCentre(): void {
    $webRoot = dirname(__DIR__, 6);
    $navBuilder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorNavBuilder.php',
    );
    $sidebar = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/includes/sidebar.html.twig',
    );

    self::assertStringContainsString("'key' => 'updates'", $navBuilder);
    self::assertStringContainsString("'route' => 'myeventlane_notifications.organiser_action_centre'", $navBuilder);
    self::assertStringContainsString("keys: ['pro', 'updates', 'settings', 'support']", $sidebar);
  }

  /**
   * Ensures vendor button defaults cannot flatten or clip the bell menu.
   */
  public function testVendorButtonDefaultsCannotOverrideBellMenu(): void {
    $css = (string) file_get_contents(dirname(__DIR__, 3) . '/css/mel-notifications-ui.css');

    self::assertStringContainsString('.mel-vendor .mel-notif-bell__trigger', $css);
    self::assertStringContainsString('.mel-vendor .mel-notif-bell__mark-all', $css);
    self::assertStringContainsString('.mel-vendor .mel-notif-bell__row', $css);
    self::assertStringContainsString('.mel-notif-bell__mark-all[hidden]', $css);
    self::assertStringContainsString('white-space: normal;', $css);
    self::assertStringContainsString('overflow-wrap: anywhere;', $css);
  }

  /**
   * Ensures navigation waits for the mark-read request and toasts can close.
   */
  public function testClickNavigationWaitsForReadRequest(): void {
    $javascript = (string) file_get_contents(dirname(__DIR__, 3) . '/js/mel-notifications-ui.js');

    self::assertStringContainsString(".finally(function () {\n          remove();\n          refreshAllBells();\n          if (item.action && item.action.url)", $javascript);
    self::assertStringContainsString("close.setAttribute('aria-label', Drupal.t('Dismiss notification'))", $javascript);
  }

  /**
   * Ensures reading an update cannot silently complete an organiser task.
   */
  public function testActionCentreKeepsSeenAndHandledSeparate(): void {
    $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/OrganiserActionCentreController.php');
    $actionController = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/NotificationController.php');
    $inboxService = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/NotificationUserInboxService.php');

    self::assertStringContainsString("'requires_action'", $controller);
    self::assertStringContainsString("'is_handled'", $controller);
    self::assertStringContainsString('MarkDeliveryHandledForm::class', $controller);
    self::assertStringContainsString('collapseActionCentreRows', $controller);
    self::assertStringContainsString('markHandledOne', $inboxService);
    self::assertStringContainsString('deliveryIdsForGroup', $inboxService);
    self::assertStringContainsString('markReadOne($uid, $delivery)', $actionController);
    self::assertStringNotContainsString('markHandledOne($uid, $delivery)', $actionController);
  }

  /**
   * Protects the distinctions between inbox, toast and grouped bell reads.
   */
  public function testGroupedReadsStayOnGroupedOrganiserSurfaces(): void {
    $service = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/NotificationUserInboxService.php');
    $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/NotificationController.php');
    $form = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Form/MarkDeliveryReadForm.php');
    $javascript = (string) file_get_contents(dirname(__DIR__, 3) . '/js/mel-notifications-ui.js');

    $markOne = strstr($service, 'public function markReadOne');
    self::assertIsString($markOne);
    $markOne = strstr($markOne, 'public function markReadGroup', TRUE);
    self::assertIsString($markOne);
    self::assertStringNotContainsString('deliveryIdsForGroup', $markOne);
    self::assertStringContainsString('public function markReadGroup', $service);
    self::assertStringContainsString('markReadGroup($uid, $deliveryId)', $form);
    self::assertStringContainsString('settings.groupReadUrlTemplate || settings.readUrlTemplate', $javascript);
    self::assertStringContainsString("self::UNREAD_HARD_LIMIT,\n      \$contexts,\n      FALSE,", $controller);
  }

  /**
   * Ensures schema failures and focused-count regressions fail safely.
   */
  public function testUpdateAndFocusedCountGuardrails(): void {
    $install = (string) file_get_contents(dirname(__DIR__, 3) . '/myeventlane_notifications.install');
    $service = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/NotificationUserInboxService.php');
    $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/NotificationController.php');

    self::assertStringContainsString('throw $e;', $install);
    self::assertStringContainsString('COUNT(DISTINCT n.group_key)', $service);
    self::assertStringContainsString('COUNT(DISTINCT n.id)', $service);
    self::assertSame(1, substr_count($controller, 'countFocusedUnreadBreakdown'));
  }

  /**
   * Ensures repeated semantic alerts collapse without merging separate work.
   */
  public function testRepeatedSemanticAlertsCollapseBySection(): void {
    $builder = new NotificationViewBuilder(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(UrlGeneratorInterface::class),
      $this->createMock(RouteProviderInterface::class),
    );
    $base = [
      'context' => 'business',
      'group_key' => 'boost_expiring:42',
      'requires_action' => TRUE,
      'is_handled' => FALSE,
    ];
    $rows = $builder->collapseActionCentreRows([
      ['delivery_id' => 3] + $base,
      ['delivery_id' => 2] + $base,
      ['delivery_id' => 1] + $base,
      array_replace($base, ['delivery_id' => 4, 'is_handled' => TRUE]),
      array_replace($base, ['delivery_id' => 5, 'group_key' => 'refund_request:9']),
    ]);

    self::assertCount(3, $rows);
    self::assertSame(3, $rows[0]['delivery_id']);
    self::assertSame(3, $rows[0]['group_count']);
    self::assertSame(1, $rows[1]['group_count']);
    self::assertSame(1, $rows[2]['group_count']);
  }

}
