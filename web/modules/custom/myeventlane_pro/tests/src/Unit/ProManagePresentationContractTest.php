<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser-facing MEL Pro subscription home.
 *
 * @group myeventlane_pro
 */
final class ProManagePresentationContractTest extends TestCase {

  public function testManagePageExplainsPlanToolsAndSelfServiceCancellation(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Your Pro toolkit'|t", $template);
    self::assertStringContainsString("'Deeper analytics'|t", $template);
    self::assertStringContainsString("'Pro email templates'|t", $template);
    self::assertStringContainsString("'Marketing and branding tools'|t", $template);
    self::assertStringContainsString("'A free 7-day Boost for each event'|t", $template);
    self::assertStringContainsString('No Boost payment is created.', $template);
    self::assertStringContainsString("'Cancel at period end'|t", $template);
    self::assertStringContainsString("'Reactivate MEL Pro'|t", $template);
    self::assertStringContainsString("'Invoices and receipts'|t", $template);
    self::assertStringContainsString("'Update payment method'|t", $template);
    self::assertStringContainsString("'Retry payment'|t", $template);
  }

  public function testPaymentRecoveryRoutesUseDedicatedRecurringFlow(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.routing.yml');
    $service = file_get_contents(dirname(__DIR__, 3) . '/src/Service/ProPaymentRecoveryService.php');
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    self::assertIsString($routing);
    self::assertIsString($service);
    self::assertIsString($controller);

    self::assertStringContainsString("path: '/vendor/pro/payment-method'", $routing);
    self::assertMatchesRegularExpression(
      "/myeventlane_pro\\.payment_method_update:.*?ProBillingController::portal/s",
      $routing,
    );
    self::assertStringContainsString('new TrustedRedirectResponse($url)', $controller);
    self::assertStringContainsString("path: '/vendor/pro/payment/retry'", $routing);
    self::assertStringContainsString("private const PAYMENT_GATEWAY = 'stripe_pe_recurring'", $service);
    self::assertStringContainsString("Job::create('myeventlane_pro_payment_retry'", $service);
    self::assertStringContainsString("catch (DuplicateJobException)", $service);
    self::assertStringContainsString("setWithExpire(\$retryKey, TRUE, 300)", $service);
    self::assertStringContainsString("\$this->lock->acquire(\$lockName", $service);
    self::assertStringContainsString("field_pro_grace_expires", $service);
    self::assertStringContainsString("only available during an active Pro grace period", $service);
    $job = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/AdvancedQueue/JobType/ProPaymentRetryJob.php');
    self::assertIsString($job);
    self::assertStringContainsString('allow_duplicates: FALSE', $job);
    self::assertStringContainsString('$this->recurringOrderManager->closeOrder($order)', $job);
    self::assertStringContainsString('Normal dunning remains scheduled', $job);
  }

  public function testStaffRecoveryKeepsCardEntryWithOrganiserAndStripe(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.routing.yml');
    $permissions = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.permissions.yml');
    $emailForm = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProAdminPaymentUpdateEmailForm.php');
    $retryForm = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProAdminPaymentRetryForm.php');
    $template = file_get_contents(dirname(__DIR__, 3) . '/config/install/myeventlane_messaging.template.pro_subscription_payment_update_link.yml');
    self::assertIsString($routing);
    self::assertIsString($permissions);
    self::assertIsString($emailForm);
    self::assertIsString($retryForm);
    self::assertIsString($template);

    self::assertStringContainsString("path: '/admin/platform/pro/{user}/send-payment-update'", $routing);
    self::assertStringContainsString("path: '/admin/platform/pro/{user}/retry-payment'", $routing);
    self::assertStringContainsString("_permission: 'manage pro payment recovery'", $routing);
    self::assertStringContainsString('restrict access: true', $permissions);
    self::assertStringContainsString('not receive or handle the card details', $emailForm);
    self::assertStringContainsString('isInActiveGracePeriod', $retryForm);
    self::assertStringContainsString('pro-payment-update-link:%d:%d', $emailForm);
    self::assertStringContainsString('{{ payment_update_url }}', $template);
  }

  public function testManagePageDoesNotPresentStaleRenewalAsFutureBilling(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($controller);
    self::assertIsString($template);

    self::assertStringContainsString('$this->billingDateResolver->resolve', $controller);
    self::assertStringContainsString("\$renewalDateStale = \$billingDate['stale']", $controller);
    self::assertStringContainsString("'Your billing date needs review'|t", $template);
    self::assertStringContainsString('We do not have a future renewal date to show', $template);
  }

  public function testTrialShowsItsFirstBillingDateWhenAvailable(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.module');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($module);
    self::assertIsString($template);

    self::assertStringContainsString("'billing_date_label' => NULL", $module);
    self::assertStringContainsString("'Your first monthly charge is due on @date", $template);
    self::assertStringContainsString("billing_date_label|default('Next billing date'|t)", $template);
  }

  public function testManageThemeExposesReactivationAndBillingHistory(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.module');
    self::assertIsString($module);

    self::assertStringContainsString("'reactivate_url' => NULL", $module);
    self::assertStringContainsString("'billing_history' => []", $module);
  }

  public function testCancelledOrganiserCanStillOpenManagePage(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.routing.yml');
    self::assertIsString($routing);

    self::assertMatchesRegularExpression(
      "/myeventlane_pro\\.manage:.*?_role: 'vendor'.*?myeventlane_pro\\.billing_portal:/s",
      $routing,
    );
  }

  public function testCancelledOrganiserSeesRestartInsteadOfActiveBillingControls(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_pro.module');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($controller);
    self::assertIsString($module);
    self::assertIsString($template);

    self::assertStringContainsString("!(\$status['has_active_subscription'] ?? FALSE)", $controller);
    self::assertStringContainsString("\$status['can_manage_billing'] ?? FALSE", $controller);
    self::assertStringContainsString("'overview_url' => NULL", $module);
    self::assertStringContainsString('status.is_pro|default(false)', $template);
    self::assertStringContainsString("'Restart MEL Pro'|t", $template);
    self::assertStringContainsString("'View Pro options'|t", $template);
  }

  public function testBillingPortalUsesTrustedExternalRedirect(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    self::assertIsString($controller);

    self::assertStringContainsString('use Drupal\\Core\\Routing\\TrustedRedirectResponse;', $controller);
    self::assertStringContainsString('return new TrustedRedirectResponse($url);', $controller);
  }

  public function testManagePageUsesSignupDateBeforeBillingPeriodStart(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    self::assertIsString($controller);

    $createdPosition = strpos($controller, "method_exists(\$subscription, 'getCreatedTime')");
    $billingStartPosition = strpos($controller, "method_exists(\$subscription, 'getStartDate')");
    self::assertNotFalse($createdPosition);
    self::assertNotFalse($billingStartPosition);
    self::assertLessThan($billingStartPosition, $createdPosition);
    self::assertStringContainsString("\$startedDate === NULL && method_exists(\$subscription, 'getStartDate')", $controller);
  }

  public function testZeroRoiUsesPlainLanguage(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString('roi_summary.roi_multiple > 0', $template);
    self::assertStringContainsString('No recovered revenue has been attributed', $template);
  }

  public function testCancellationRemainsAvailableWhenLegacyConfigKeyIsMissing(): void {
    $service = file_get_contents(dirname(__DIR__, 3) . '/src/Service/ProSubscriptionStatusService.php');
    self::assertIsString($service);

    self::assertStringContainsString("get('cancel_request_enabled') ?? TRUE", $service);
  }

  public function testCancellationUsesCommerceScheduledChange(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProCancelRequestForm.php');
    $reactivate = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProReactivateForm.php');
    self::assertIsString($form);
    self::assertIsString($reactivate);

    self::assertStringContainsString('$subscription->cancel(TRUE)', $form);
    self::assertStringContainsString("hasScheduledChange('state', 'canceled')", $form);
    self::assertStringContainsString("removeScheduledChanges('state')", $reactivate);
  }

  public function testUpgradePageUsesVerifiedBenefitLanguageAndOneSubscribeForm(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-overview.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Advanced organiser analytics'|t", $template);
    self::assertStringContainsString("'Pro email templates'|t", $template);
    self::assertStringContainsString('30-day trial, then @price per month', $template);
    self::assertStringContainsString('@price per month from today', $template);
    self::assertStringContainsString('trial_eligible', $template);
    self::assertSame(1, substr_count($template, '{{ subscribe_form }}'));
    self::assertStringNotContainsString('Automated refunds', $template);
    self::assertStringNotContainsString('Revenue Growth', $template);
  }

}
