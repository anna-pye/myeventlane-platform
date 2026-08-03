<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\myeventlane_auth\EventSubscriber\AccountSetupFlowSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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
    self::assertStringContainsString("['#submit'][] = 'myeventlane_auth_account_setup_complete_submit'", $module);
    self::assertStringContainsString("setRedirect('myeventlane_account.dashboard')", $module);

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

  /**
   * Create-event setup returns through the public organiser gateway.
   */
  public function testCreateEventPasswordSetupUsesOrganiserGateway(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_auth.module');
    self::assertIsString($module);

    $start = strpos($module, 'function myeventlane_auth_reset_redirect_submit');
    $end = strpos($module, 'function myeventlane_auth_form_user_login_form_alter', $start);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $handler = substr($module, $start, $end - $start);

    self::assertStringContainsString("'myeventlane_vendor.create_event_gateway'", $handler);
    self::assertStringNotContainsString("'myeventlane_event_studio.create'", $handler);
  }

  /**
   * First-use settings open profile and security without exposing usernames.
   */
  public function testFirstUseSettingsPresentationContract(): void {
    $account_module = file_get_contents(dirname(__DIR__, 7) . '/web/modules/custom/myeventlane_account/myeventlane_account.module');
    self::assertIsString($account_module);
    self::assertStringContainsString("data-mel-account-setup'] = 'true'", $account_module);
    self::assertStringContainsString("['#access'] = FALSE", $account_module);
    self::assertStringContainsString("t('Sign-in email')", $account_module);
    self::assertStringContainsString("t('Finish account setup')", $account_module);
    self::assertStringContainsString("['#required'] = TRUE", $account_module);

    $hub_script = file_get_contents(dirname(__DIR__, 7) . '/web/themes/custom/myeventlane_theme/js/account-settings-hub.js');
    self::assertIsString($hub_script);
    self::assertStringContainsString("['profile', 'security'].forEach", $hub_script);
    self::assertStringContainsString("form.dataset.melAccountSetup === 'true'", $hub_script);
  }

  /**
   * A normal recovery link clears abandoned account-setup presentation state.
   */
  public function testPasswordRecoveryClearsStaleAccountSetupIntent(): void {
    $session = new Session(new MockArraySessionStorage());
    $kernel = $this->createMock(HttpKernelInterface::class);
    $subscriber = new AccountSetupFlowSubscriber();

    $setup_request = Request::create('/user/reset/123?mel_flow=account_setup');
    $setup_request->setSession($session);
    $setup_request->attributes->set('_route', 'user.reset');
    $setup_request->attributes->set('uid', '123');
    $subscriber->onRequest(new RequestEvent($kernel, $setup_request, HttpKernelInterface::MAIN_REQUEST));
    self::assertSame('123', $session->get(AccountSetupFlowSubscriber::SESSION_KEY));

    $recovery_request = Request::create('/user/reset/123');
    $recovery_request->setSession($session);
    $recovery_request->attributes->set('_route', 'user.reset');
    $recovery_request->attributes->set('uid', '123');
    $subscriber->onRequest(new RequestEvent($kernel, $recovery_request, HttpKernelInterface::MAIN_REQUEST));
    self::assertFalse($session->has(AccountSetupFlowSubscriber::SESSION_KEY));
  }

  /**
   * Submitting Continue preserves first-use intent for the settings form.
   */
  public function testAccountSetupContinuePreservesIntent(): void {
    $session = new Session(new MockArraySessionStorage());
    $session->set(AccountSetupFlowSubscriber::SESSION_KEY, '123');
    $kernel = $this->createMock(HttpKernelInterface::class);
    $subscriber = new AccountSetupFlowSubscriber();

    $continue_request = Request::create('/user/reset/123', 'POST');
    $continue_request->setSession($session);
    $continue_request->attributes->set('_route', 'user.reset');
    $continue_request->attributes->set('uid', '123');
    $subscriber->onRequest(new RequestEvent($kernel, $continue_request, HttpKernelInterface::MAIN_REQUEST));

    self::assertSame('123', $session->get(AccountSetupFlowSubscriber::SESSION_KEY));
  }

  /**
   * Drupal destination middleware cannot replace the intent-aware redirect.
   */
  public function testPostLoginControllerConsumesResolvedDestination(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/MelPostLoginController.php');
    self::assertIsString($controller);
    self::assertStringContainsString("query->remove('destination')", $controller);
    self::assertStringContainsString('resolveDestination', $controller);
  }

  /**
   * Apple form_post keeps its state session and uses Apple's supplied button.
   */
  public function testAppleSignInComplianceContract(): void {
    $root = dirname(__DIR__, 7);
    foreach (glob($root . '/web/sites/default/mel.session.*.yml') ?: [] as $session_config) {
      $yaml = file_get_contents($session_config);
      self::assertIsString($yaml);
      self::assertStringContainsString('cookie_samesite: None', $yaml, $session_config);
    }

    $settings = file_get_contents($root . '/web/sites/default/settings.mel_shared_session.php');
    self::assertIsString($settings);
    self::assertStringContainsString("\$config['social_auth.settings']['user_allowed'] = 'login';", $settings);
    foreach ([
      'MEL_SOCIAL_APPLE_CLIENT_ID',
      'MEL_SOCIAL_APPLE_TEAM_ID',
      'MEL_SOCIAL_APPLE_KEY_FILE_ID',
      'MEL_SOCIAL_APPLE_KEY_FILE_PATH',
      'MEL_SOCIAL_GOOGLE_CLIENT_ID',
      'MEL_SOCIAL_GOOGLE_CLIENT_SECRET',
    ] as $environmentName) {
      self::assertStringContainsString($environmentName, $settings);
    }
    self::assertStringContainsString("\$config['social_auth_apple.settings']['scopes'] = '';", $settings);
    self::assertStringContainsString("\$config['social_auth_google.settings']['scopes'] = '';", $settings);

    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_auth.module');
    self::assertIsString($module);
    self::assertStringContainsString('images/sign-in-with-apple@2x.png', $module);
    self::assertStringContainsString("t('Sign in with Apple')", $module);
    self::assertStringContainsString("'alt' => (string) \$label", $module);
    self::assertStringContainsString("'aria-label' => (string) \$label", $module);

    $button = dirname(__DIR__, 3) . '/images/sign-in-with-apple@2x.png';
    self::assertFileExists($button);
    self::assertSame([750, 104], array_slice(getimagesize($button), 0, 2));
  }

}
