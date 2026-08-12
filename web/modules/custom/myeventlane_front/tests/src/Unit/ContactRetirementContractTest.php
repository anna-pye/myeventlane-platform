<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the public contact page while retiring Drupal core Contact.
 *
 * @group myeventlane_front
 */
final class ContactRetirementContractTest extends TestCase {

  /**
   * Confirms Contact is absent without changing the public contact destination.
   */
  public function testCoreContactIsRetiredAndPublicPageRemains(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $repositoryRoot = dirname($moduleRoot, 4);
    $sync = $repositoryRoot . '/config/sync';

    $extensions = Yaml::parseFile($sync . '/core.extension.yml');
    self::assertArrayNotHasKey('contact', $extensions['module']);

    foreach (['contact.form.feedback.yml', 'contact.form.personal.yml', 'contact.settings.yml', 'core.entity_view_mode.contact_message.token.yml'] as $retiredConfig) {
      self::assertFileDoesNotExist($sync . '/' . $retiredConfig);
    }

    foreach (['anonymous', 'authenticated'] as $roleId) {
      $role = Yaml::parseFile($sync . '/user.role.' . $roleId . '.yml');
      self::assertNotContains('contact', $role['dependencies']['module'] ?? []);
      self::assertNotContains('access site-wide contact form', $role['permissions'] ?? []);
    }

    foreach (['default', 'register'] as $mode) {
      $display = Yaml::parseFile($sync . '/core.entity_form_display.user.user.' . $mode . '.yml');
      self::assertArrayNotHasKey('contact', $display['content'] ?? []);
    }

    $honeypot = Yaml::parseFile($sync . '/honeypot.settings.yml');
    self::assertArrayNotHasKey('contact_message_feedback_form', $honeypot['form_settings'] ?? []);

    $routeSubscriber = (string) file_get_contents($moduleRoot . '/src/Routing/RouteSubscriber.php');
    self::assertStringNotContainsString('contact.site_page', $routeSubscriber);

    $footerLinks = (string) file_get_contents(dirname($moduleRoot) . '/myeventlane_core/myeventlane_core.links.menu.yml');
    self::assertStringContainsString("url: 'internal:/contact'", $footerLinks);
  }

}
