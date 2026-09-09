<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the MEL shell around Gin Login without replacing authentication.
 *
 * @group myeventlane_auth
 */
final class LoginShellPresentationContractTest extends TestCase {

  /**
   * The template owns presentation while Drupal continues to render the form.
   */
  public function testLoginTemplateKeepsDrupalFormOwnership(): void {
    $root = dirname(__DIR__, 3);
    $template = file_get_contents($root . '/templates/page--user--login-mel.html.twig');
    $register_template = file_get_contents($root . '/templates/page--user--register-mel.html.twig');
    self::assertIsString($template);
    self::assertIsString($register_template);

    self::assertStringContainsString('user-form-page mel-auth-shell', $template);
    self::assertStringContainsString("'Sign in to MyEventLane'|t", $template);
    self::assertStringContainsString("'Manage your bookings, saved events and organiser tools.'|t", $template);
    self::assertStringContainsString("page.content|without('claro_primary_local_tasks', 'gin_primary_local_tasks')", $template);
    self::assertStringContainsString('aria-label="{{ \'Back to MyEventLane\'|t }}"', $template);
    self::assertStringNotContainsString('<form', $template);

    self::assertStringContainsString('mel-auth-shell--register', $register_template);
    self::assertStringContainsString("'Create your account'|t", $register_template);
    self::assertStringContainsString("page.content|without('claro_primary_local_tasks', 'gin_primary_local_tasks')", $register_template);
    self::assertStringNotContainsString('<form', $register_template);
  }

  /**
   * The route keeps MEL's existing auth and destination logic intact.
   */
  public function testLoginFormAddsPresentationAfterExistingAuthControls(): void {
    $root = dirname(__DIR__, 3);
    $module = file_get_contents($root . '/myeventlane_auth.module');
    self::assertIsString($module);

    $start = strpos($module, 'function myeventlane_auth_form_user_login_form_alter');
    $end = strpos($module, 'function myeventlane_auth_add_social_login_controls', $start);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $alter = substr($module, $start, $end - $start);

    self::assertStringContainsString('myeventlane_auth_add_social_login_controls($form, $request)', $alter);
    self::assertStringContainsString("'myeventlane_auth/login_shell'", $alter);
    self::assertStringContainsString("t('Sign in')", $alter);
    self::assertStringContainsString("t('New to MyEventLane?')", $alter);
    self::assertStringContainsString("t('Create a free account')", $alter);
    self::assertStringContainsString("['button', 'button--secondary']", $alter);
    self::assertStringContainsString("['autocomplete'] = 'current-password'", $alter);
    self::assertStringContainsString('myeventlane_auth_auth_entry_preserved_query($request)', $alter);

    self::assertStringContainsString("['page__user__login']['template'] = 'page--user--login-mel'", $module);
    self::assertStringContainsString("['page__user__register']['template'] = 'page--user--register-mel'", $module);
    self::assertStringContainsString("'mel-auth-form--register'", $module);
    self::assertStringContainsString("t('Create my account')", $module);
  }

  /**
   * Styling and interactions remain route-scoped and accessible.
   */
  public function testShellAssetsAreScopedAndAccessible(): void {
    $root = dirname(__DIR__, 3);
    $library = file_get_contents($root . '/myeventlane_auth.libraries.yml');
    $css = file_get_contents($root . '/css/login-shell.css');
    $script = file_get_contents($root . '/js/login-shell.js');
    self::assertIsString($library);
    self::assertIsString($css);
    self::assertIsString($script);

    self::assertStringContainsString("login_shell:\n", $library);
    self::assertStringContainsString('core/once', $library);
    self::assertStringContainsString('body.gin-login .user-form-page.mel-auth-shell', $css);
    self::assertStringContainsString('@media (max-width: 899px)', $css);
    self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    self::assertStringNotContainsString('!important', $css);
    self::assertStringContainsString("toggle.type = 'button'", $script);
    self::assertStringContainsString("Drupal.t('Show password')", $script);
    self::assertStringContainsString("toggle.setAttribute('aria-pressed'", $script);

    $mel = $root . '/images/mel-waving.png';
    self::assertFileExists($mel);
    self::assertSame([120, 198], array_slice(getimagesize($mel), 0, 2));
  }

}
