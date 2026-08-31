<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser sales-operations presentation and safety contract.
 */
final class VendorSalesOperationsPresentationContractTest extends TestCase {

  public function testRefundRoutesKeepAccessChecksAndUseWorkspaceController(): void {
    $root = dirname(__DIR__, 7);
    $routing = (string) file_get_contents($root . '/web/modules/custom/myeventlane_refunds/myeventlane_refunds.routing.yml');

    self::assertStringContainsString('VendorRefundWorkspaceController::approve', $routing);
    self::assertStringContainsString('VendorRefundWorkspaceController::reject', $routing);
    self::assertStringContainsString('VendorRefundWorkspaceController::refund', $routing);
    self::assertStringContainsString("_permission: 'manage_refunds'", $routing);
    self::assertStringContainsString('vendor_refund_request_access:access', $routing);
    self::assertStringContainsString('VendorRefundForm::access', $routing);
  }

  public function testRefundQueueExplainsDecisionsAndPreservesActions(): void {
    $root = dirname(__DIR__, 7);
    $template = (string) file_get_contents($root . '/web/modules/custom/myeventlane_refunds/templates/mel-vendor-refund-requests.html.twig');

    self::assertStringContainsString("aria-label=\"{{ 'Before you decide'|t }}\"", $template);
    self::assertStringContainsString('mel-orders-console', $template);
    self::assertStringContainsString('request.order_url', $template);
    self::assertStringContainsString('request.approve_url', $template);
    self::assertStringContainsString('request.reject_url', $template);
    self::assertStringContainsString('Nothing is refunded until', $template);
  }

  public function testAddonOrdersRemainReadOnlyAndLinkToFullRecords(): void {
    $root = dirname(__DIR__, 7);
    $controller = (string) file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Controller/VendorOperationalAddonOrdersController.php');
    $template = (string) file_get_contents($root . '/web/modules/custom/myeventlane_commerce/templates/mel-vendor-operational-addon-orders.html.twig');

    self::assertStringContainsString("buildForEvent(\$event, \$this->currentUser)", $controller);
    self::assertStringContainsString('$document[\'orders\'][$key][\'order_url\']', $controller);
    self::assertStringContainsString("\$this->safeRouteUrl('myeventlane_refunds.vendor_refund_requests'", $controller);
    self::assertStringContainsString('order.order_url', $template);
    self::assertStringContainsString('{% if refunds_url %}', $template);
    self::assertStringContainsString("aria-label=\"{{ 'How to use add-on orders'|t }}\"", $template);
    self::assertStringContainsString('mel-orders-console', $template);
    self::assertStringNotContainsString('<form', $template);
  }

  public function testGuidesMatchTheOrdersConsoleLayout(): void {
    $root = dirname(__DIR__, 7);
    $addonCss = (string) file_get_contents($root . '/web/modules/custom/myeventlane_commerce/css/mel-vendor-operational-addon-orders.css');
    $refundCss = (string) file_get_contents($root . '/web/modules/custom/myeventlane_refunds/css/mel-refund-ui.css');
    $ordersScss = (string) file_get_contents($root . '/web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_event-studio-orders.scss');

    self::assertStringContainsString('.mel-vendor-addon-orders .mel-sales-ops__guide {', $addonCss);
    self::assertStringContainsString('.mel-refund-requests .mel-sales-ops__guide {', $refundCss);
    self::assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $addonCss);
    self::assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $refundCss);
    self::assertStringContainsString('.mel-studio-orders .mel-sales-ops__guide {', $ordersScss);
  }

  public function testDecisionFormsRetainConfirmationAndSafeNavigation(): void {
    $root = dirname(__DIR__, 7);
    $approve = (string) file_get_contents($root . '/web/modules/custom/myeventlane_refunds/src/Form/VendorRefundRequestApproveForm.php');
    $refund = (string) file_get_contents($root . '/web/modules/custom/myeventlane_refunds/src/Form/VendorRefundForm.php');

    self::assertStringContainsString("'#value' => \$this->t('Approve refund')", $approve);
    self::assertStringContainsString("'#required' => TRUE", $refund);
    self::assertStringContainsString("'#value' => \$this->t('Process refund')", $refund);
    self::assertStringContainsString('myeventlane_event_studio.workspace_orders', $refund);
  }

}
