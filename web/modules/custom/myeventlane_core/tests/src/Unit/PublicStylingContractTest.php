<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the customer-facing styling and messaging architecture.
 */
#[Group('myeventlane_core')]
final class PublicStylingContractTest extends TestCase {

  /**
   * Absolute repository root.
   */
  private string $repositoryRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->repositoryRoot = dirname(__DIR__, 7);
  }

  /**
   * Commerce-specific CSS must not ship in the global bundle.
   */
  public function testCommerceCssIsRouteScoped(): void {
    $main = $this->source('web/themes/custom/myeventlane_theme/src/scss/main.scss');
    $commerce = $this->source('web/themes/custom/myeventlane_theme/src/scss/commerce.scss');
    $libraries = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.libraries.yml');
    $theme = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.theme');
    $html = $this->source('web/themes/custom/myeventlane_theme/templates/html.html.twig');

    self::assertStringContainsString("@use 'commerce/commerce' as commerce with (\$include-transactional: false)", $main);
    self::assertStringNotContainsString("@use 'components/checkout'", $main);
    self::assertStringContainsString("@use 'commerce/commerce'", $commerce);
    self::assertStringContainsString("@use 'components/checkout'", $commerce);
    self::assertMatchesRegularExpression(
      '/commerce-styling:\R  dependencies:\R    - core\/drupal/',
      $libraries,
      'The dynamically populated commerce library must remain valid before hook_library_info_alter() runs.',
    );
    self::assertStringContainsString("\$manifest['scss/commerce.scss']", $theme);
    self::assertStringContainsString('_myeventlane_theme_is_commerce_styled_route', $theme);
    self::assertStringContainsString("'entity.commerce_payment_method.add_form'", $theme);
    self::assertStringContainsString("\$variables['#attached']['library'][] = 'myeventlane_theme/commerce-styling';", $theme);
    self::assertStringContainsString("\$variables['mel_attach_commerce_styling'] = TRUE;", $theme);
    self::assertStringContainsString("attach_library('myeventlane_theme/commerce-styling')", $html);
  }

  /**
   * Account card fields must override Commerce Stripe's fixed em widths.
   */
  public function testStripeCardFieldsUseReadableResponsivePresentation(): void {
    $checkout = $this->source('web/themes/custom/myeventlane_theme/src/scss/components/_checkout.scss');
    $javascript = $this->source('web/themes/custom/myeventlane_theme/js/stripe-card-presentation.js');
    $libraries = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.libraries.yml');

    self::assertStringContainsString('.mel-commerce-checkout .stripe-form', $checkout);
    self::assertStringContainsString('.mel-account-payment-methods .stripe-form', $checkout);
    self::assertMatchesRegularExpression(
      '/#card-number-element\s*\{\s*width:\s*min\(100%,\s*32rem\);/s',
      $checkout,
    );
    self::assertMatchesRegularExpression(
      '/#expiration-element,\s*#security-code-element\s*\{\s*width:\s*min\(100%,\s*12rem\);/s',
      $checkout,
    );
    self::assertStringContainsString('align-items: center', $checkout);
    self::assertStringContainsString('min-height: 56px', $checkout);
    self::assertStringNotContainsString('!important', $checkout);
    self::assertStringContainsString('melStripeCardPresentation', $javascript);
    self::assertStringContainsString("fontSize: '18px'", $javascript);
    self::assertStringContainsString("lineHeight: '24px'", $javascript);
    self::assertStringContainsString('cardElement.update({ style: STRIPE_CARD_STYLE })', $javascript);
    self::assertStringContainsString('myeventlane_theme/stripe-card-presentation', $libraries);
  }

  /**
   * Ticket urgency must be backed by the server hold and remain accessible.
   */
  public function testTicketHoldTimerUsesServerExpiryAndAccessibleThresholds(): void {
    $commerceModule = $this->source('web/modules/custom/myeventlane_commerce/myeventlane_commerce.module');
    $checkout = $this->source('web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-form.html.twig');
    $timer = $this->source('web/themes/custom/myeventlane_theme/templates/commerce/mel-ticket-hold.html.twig');
    $javascript = $this->source('web/themes/custom/myeventlane_theme/js/cart-ticket-hold.js');
    $styles = $this->source('web/themes/custom/myeventlane_theme/src/scss/commerce/_commerce.scss');
    $libraries = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.libraries.yml');
    $theme = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.theme');

    self::assertStringContainsString("'#theme' => 'mel_ticket_hold'", $commerceModule);
    self::assertStringContainsString("'#surface' => 'cart'", $commerceModule);
    self::assertStringContainsString("\$form['#validate'] = array_merge(", $commerceModule);
    self::assertStringNotContainsString("['checkout']['#validate'][] = 'myeventlane_commerce_cart_ticket_hold_validate'", $commerceModule);
    self::assertStringNotContainsString("['next']['#validate'][] = 'myeventlane_commerce_checkout_ticket_hold_validate'", $commerceModule);
    self::assertStringContainsString("in_array('mel-ticket-hold-protected-action', \$classes, TRUE)", $commerceModule);
    self::assertStringContainsString("'mel_ticket_hold' => [", $theme);
    self::assertStringContainsString("surface: 'checkout'", $checkout);
    self::assertStringContainsString('data-server-now', $timer);
    self::assertStringContainsString('data-expires-at', $timer);
    self::assertStringContainsString('role="progressbar"', $timer);
    self::assertStringContainsString('aria-live="polite"', $timer);
    self::assertStringContainsString('WARNING_SECONDS = 5 * 60', $javascript);
    self::assertStringContainsString('URGENT_SECONDS = 2 * 60', $javascript);
    self::assertStringContainsString('window.performance.now()', $javascript);
    self::assertStringNotContainsString('Date.now()', $javascript);
    self::assertStringContainsString('.mel-ticket-hold', $styles);
    self::assertStringContainsString('.mel-cart-action--renew-hold[hidden]', $styles);
    self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    self::assertStringContainsString('cart_ticket_hold:', $libraries);
  }

  /**
   * Feature components must not replace the global brand token cascade.
   */
  public function testWizardDoesNotLeakGlobalBrandTokens(): void {
    $wizard = $this->source('web/themes/custom/myeventlane_theme/src/scss/components/_wizard.scss');
    $tokens = $this->source('web/themes/custom/myeventlane_theme/src/scss/base/_tokens.scss');

    self::assertStringNotContainsString(':root', $wizard);
    self::assertStringNotContainsString('--mel-primary:', $wizard);
    self::assertStringContainsString('--mel-color-primary: #6b46ff', $tokens);
  }

  /**
   * Error and maintenance pages must not depend on product-page chrome.
   */
  public function testRecoveryPagesRemainCalmAndDependencyLight(): void {
    $maintenanceSettings = $this->source('web/sites/default/settings.mel_shared_session.php');
    self::assertStringContainsString("\$settings['maintenance_theme'] = 'mel_maintenance';", $maintenanceSettings);

    $maintenancePage = $this->source('web/themes/custom/mel_maintenance/templates/system/maintenance-page.html.twig');
    $maintenanceLogo = $this->source('web/themes/custom/mel_maintenance/logo.svg');
    self::assertStringContainsString("path('user.login')", $maintenancePage);
    self::assertStringContainsString('{{ directory }}/logo.svg', $maintenancePage);
    self::assertStringContainsString('MyEventLane', $maintenanceLogo);
    self::assertStringNotContainsString('linearGradient id="a"', $maintenanceLogo);

    foreach ([
      'web/themes/custom/mel_maintenance/templates/system/page--403.html.twig',
      'web/themes/custom/mel_maintenance/templates/system/page--404.html.twig',
      'web/themes/custom/myeventlane_theme/templates/system/mel-403.html.twig',
      'web/themes/custom/myeventlane_theme/templates/system/mel-404.html.twig',
    ] as $path) {
      $source = $this->source($path);
      self::assertStringContainsString('mel-err__panel', $source, $path);
      self::assertStringNotContainsString('mascot', strtolower($source), $path);
      self::assertStringNotContainsString('invite-only', strtolower($source), $path);
    }
  }

  /**
   * Platform email and SMS defaults must use the current MEL brand.
   */
  public function testPlatformMessagingUsesCurrentBrandDefaults(): void {
    $email = $this->source('web/modules/custom/myeventlane_messaging/templates/email/mel-email-base.html.twig');
    $sms = $this->source('web/modules/custom/myeventlane_rsvp/templates/sms/rsvp-confirm-sms.txt.twig');

    self::assertStringContainsString("default('#6b46ff')", $email);
    self::assertStringContainsString("set page_bg = '#fff7ee'", $email);
    self::assertStringContainsString("MyEventLane: You're confirmed", $sms);
    self::assertStringNotContainsString("\n", trim($sms));

    $ticketDefaults = $this->source('web/modules/custom/myeventlane_tickets/config/install/myeventlane_tickets.settings.yml');
    self::assertStringContainsString("brand_accent_color: '#6b46ff'", $ticketDefaults);

    $templatePaths = array_merge(
      glob($this->repositoryRoot . '/config/sync/myeventlane_messaging.template.*.yml') ?: [],
      glob($this->repositoryRoot . '/web/modules/custom/myeventlane_messaging/config/install/myeventlane_messaging.template.*.yml') ?: [],
    );
    self::assertNotEmpty($templatePaths);
    foreach ($templatePaths as $path) {
      $source = (string) file_get_contents($path);
      self::assertDoesNotMatchRegularExpression(
        '/#(?:f26d5b|6e7ef2|5360bf|ff6b9d|8e7cff|6c5ce7|f5c04c)/i',
        $source,
        $path,
      );
    }
  }

  /**
   * Sales-open active config must converge with its owning module default.
   */
  public function testSalesOpenMessagingConfigConverges(): void {
    $relativePath = 'myeventlane_messaging.template.sales_open.yml';
    $sync = Yaml::parse($this->source('config/sync/' . $relativePath));
    $install = Yaml::parse($this->source(
      'web/modules/custom/myeventlane_automation/config/install/' . $relativePath,
    ));

    self::assertIsArray($sync);
    self::assertIsArray($install);
    self::assertIsBool($sync['enabled'] ?? NULL);
    self::assertTrue($sync['enabled']);
    self::assertSame($sync, $install);
  }

  /**
   * Reads a repository source file.
   */
  private function source(string $relativePath): string {
    $source = file_get_contents($this->repositoryRoot . '/' . $relativePath);
    self::assertIsString($source, $relativePath);
    return $source;
  }

}
