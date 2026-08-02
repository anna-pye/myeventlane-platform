<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the distinction between account setup and password reset.
 *
 * @group myeventlane_auth
 */
final class AccountSetupPresentationContractTest extends TestCase {

  /**
   * Registration mail marks only first-use links as account setup.
   */
  public function testRegistrationMailUsesAccountSetupLanguage(): void {
    $mail = file_get_contents(dirname(__DIR__, 7) . '/config/sync/user.mail.yml');
    self::assertIsString($mail);

    self::assertStringContainsString("subject: 'Set up your [site:name] account'", $mail);
    self::assertSame(2, substr_count($mail, '?mel_flow=account_setup'));
    self::assertStringContainsString("password_reset:\n  subject: 'Replacement login information", $mail);
  }

  /**
   * The one-time-login form changes language only for the explicit marker.
   */
  public function testResetFormHasExplicitAccountSetupPresentation(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_auth.module');
    self::assertIsString($module);

    self::assertStringContainsString('get(AccountSetupFlowSubscriber::SESSION_KEY)', $module);
    self::assertStringContainsString("t('Set up your account')", $module);
    self::assertStringContainsString("t('Continue')", $module);
    self::assertStringContainsString('function myeventlane_auth_mail_alter', $module);
    self::assertStringContainsString("'register_no_approval_required'", $module);
    self::assertStringNotContainsString("remove('mel_account_setup_uid')", $module);
    self::assertStringContainsString('remove(AccountSetupFlowSubscriber::SESSION_KEY)', $module);

    $subscriber = file_get_contents(dirname(__DIR__, 3) . '/src/EventSubscriber/AccountSetupFlowSubscriber.php');
    self::assertIsString($subscriber);
    self::assertStringContainsString("query->get('mel_flow') === 'account_setup'", $subscriber);
    self::assertStringContainsString("\$route === 'user.reset.form'", $subscriber);

    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/page--user--password-mel.html.twig');
    self::assertIsString($template);
    self::assertStringContainsString("mel_account_setup|default(false)", $template);
    self::assertStringContainsString("'Reset your password'|t", $template);

    $hub_script = file_get_contents(dirname(__DIR__, 7) . '/web/themes/custom/myeventlane_theme/js/account-settings-hub.js');
    self::assertIsString($hub_script);
    self::assertStringContainsString("toggle && !invalidCard.classList.contains('is-editing')", $hub_script);
  }

}
