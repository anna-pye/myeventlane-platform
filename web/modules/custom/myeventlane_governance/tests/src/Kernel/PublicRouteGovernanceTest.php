<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_governance\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Scans custom routing YAML for _access: TRUE routes and enforces an allowlist.
 *
 * Every route open at the Drupal access layer must be documented here with the
 * compensating control that protects it (signature verification, controller gate,
 * CSRF, flood limits, published-content-only Views, etc.).
 *
 * @group myeventlane_governance
 */
final class PublicRouteGovernanceTest extends TestCase {

  /**
   * Routes intentionally open at the Drupal access layer with compensating controls.
   *
   * @var array<string, string>
   */
  protected array $allowedPublicRoutes = [
    'myeventlane_admin_dashboard.stripe_payout_webhook' => 'Secured by Stripe signature verification in StripeWebhookController.',
    'myeventlane_auth.login' => 'OAuth login entry; AuthController validates client_id and OAuth params before issuing codes.',
    'myeventlane_auth.me' => 'OAuth userinfo; TokenController requires a valid Bearer access token before returning profile fields.',
    'myeventlane_auth.mel_continue' => 'Anonymous-safe redirect handoff to core login with destination preserved.',
    'myeventlane_auth.mel_email_entry' => 'Anonymous-safe sign-in placeholder linking to core login via mel_continue.',
    'myeventlane_auth.refresh' => 'OAuth refresh; TokenController enforces client credentials and a valid refresh token.',
    'myeventlane_auth.revoke' => 'OAuth revocation; TokenController requires client credentials plus refresh or Bearer token.',
    'myeventlane_auth.token' => 'OAuth token exchange; TokenController enforces client credentials, one-time code, and redirect_uri.',
    'myeventlane_auth.vendor_sso_callback' => 'Vendor SSO callback; validates HMAC-signed OAuth state and one-time authorization code.',
    'myeventlane_boost.performance_guide_pdf' => 'Anonymous-safe static marketing PDF; no vendor or financial data.',
    'myeventlane_core.error_403' => 'Anonymous-safe themed 403 error markup only.',
    'myeventlane_core.error_404' => 'Anonymous-safe themed 404 error markup only.',
    'myeventlane_core.health' => 'Load-balancer health probe returning ok/error status strings only (no secrets).',
    'myeventlane_event.passcode_gate' => 'Event passcode form; EventPasscodeForm validates passcode and Form API CSRF on POST.',
    'myeventlane_help.analytics_click' => 'Help panel click beacon; HelpAnalyticsController accepts anonymous-safe click keys only.',
    'myeventlane_help_assistant.ask' => 'Help assistant JSON POST; flood limits and public help retrieval only (read-only).',
    'myeventlane_help_centre.article_feedback' => 'Help feedback POST; CSRF token, flood limits, and help_article bundle check.',
    'myeventlane_help_centre.attendees_index' => 'Public attendee help listing via published Views content only.',
    'myeventlane_help_centre.category_index' => 'Public help category listing via published Views; term entity access applies.',
    'myeventlane_help_centre.organisers_index' => 'Public organiser help listing via published Views content only.',
    'myeventlane_help_centre.policies_index' => 'Public policies help listing via published Views content only.',
    'myeventlane_help_centre.public_index' => 'Public Help Centre index via published Views content only.',
    'myeventlane_help_centre.search' => 'Public help search via published article and FAQ Views only.',
    'myeventlane_help_centre.vendors_index' => 'Organiser help hub; controller redirects anonymous users to login.',
    'myeventlane_messaging.postmark_webhook_bounce' => 'Secured by X-Webhook-Secret header validation in PostmarkWebhookController.',
    'myeventlane_messaging.postmark_webhook_delivery' => 'Secured by X-Webhook-Secret header validation in PostmarkWebhookController.',
    'myeventlane_pro.subscription_webhook' => 'Secured by Stripe signature verification in ProSubscriptionWebhookController.',
    'myeventlane_public_trust.page' => 'Public trust page; aggregated cached metrics only, no PII.',
    'myeventlane_vendor.create_event_gateway' => 'Create-event entry; controller redirects anonymous users and enforces onboarding.',
    'myeventlane_vendor.login_alias' => 'Vendor login alias; redirect-only to core user.login with safe destination.',
    'myeventlane_vendor.shell.dashboard' => 'Vendor shell entry; permission-based redirect only, no sensitive payload.',
    'myeventlane_vendor.shell.vendor_root' => 'Vendor console root; permission-based redirect only, no sensitive payload.',
    'myeventlane_vendor.stripe_callback' => 'Stripe Connect return; controller requires authenticated user and account_id validation.',
    'myeventlane_vendor.stripe_callback_legacy' => 'Legacy Stripe Connect return; same authenticated account_id validation as stripe_callback.',
    'myeventlane_vendor.stripe_onboard_return' => 'Stripe onboarding return; controller requires authentication and account_id validation.',
    'mel_search.autocomplete' => 'Public search autocomplete JSON from Search API indexes; no private entity fields.',
    'mel_search.view' => 'Public event discovery search via Search API indexes; published events only.',
  ];

  /**
   * Ensures every _access: TRUE route in custom modules is allowlisted with a reason.
   */
  public function testPublicRoutesAreAllowlisted(): void {
    $discovered = $this->discoverPublicRoutes();

    $missing = [];
    foreach ($discovered as $routeName => $sourceFile) {
      if (!array_key_exists($routeName, $this->allowedPublicRoutes)) {
        $missing[$routeName] = $sourceFile;
      }
    }

    $stale = array_diff_key($this->allowedPublicRoutes, $discovered);

    $messages = [];
    if ($missing !== []) {
      $lines = [];
      foreach ($missing as $routeName => $sourceFile) {
        $lines[] = sprintf('- %s (%s)', $routeName, $this->relativePath($sourceFile));
      }
      $messages[] = "Unallowlisted public routes found:\n" . implode("\n", $lines);
    }
    if ($stale !== []) {
      $lines = [];
      foreach (array_keys($stale) as $routeName) {
        $lines[] = sprintf('- %s', $routeName);
      }
      $messages[] = "Allowlist entries no longer present in routing YAML:\n" . implode("\n", $lines);
    }

    $this->assertSame([], $missing, implode("\n\n", $messages));
    $this->assertSame([], $stale, implode("\n\n", $messages));
  }

  /**
   * Ensures every allowlist entry documents a non-empty compensating control.
   */
  public function testAllowlistReasonsAreDocumented(): void {
    foreach ($this->allowedPublicRoutes as $routeName => $reason) {
      $this->assertNotSame('', trim($reason), sprintf('Allowlist reason for %s must not be empty.', $routeName));
    }
  }

  /**
   * @return array<string, string>
   *   Route name keyed to absolute routing YAML path.
   */
  private function discoverPublicRoutes(): array {
    $customModulesDir = dirname(__DIR__, 4);
    $this->assertDirectoryExists($customModulesDir);

    $pattern = $customModulesDir . '/*/*.routing.yml';
    $files = glob($pattern) ?: [];
    $files = array_values(array_filter(
      $files,
      static fn (string $path): bool => !str_contains($path, '/tests/'),
    ));
    sort($files);

    $publicRoutes = [];
    foreach ($files as $file) {
      try {
        $parsed = Yaml::parseFile($file);
      }
      catch (ParseException $exception) {
        $this->fail(sprintf('Failed parsing %s: %s', $this->relativePath($file), $exception->getMessage()));
      }

      if (!is_array($parsed)) {
        continue;
      }

      foreach ($parsed as $routeName => $definition) {
        if (!is_string($routeName) || !is_array($definition)) {
          continue;
        }
        if ($this->isPublicAccessRoute($definition)) {
          $publicRoutes[$routeName] = $file;
        }
      }
    }

    ksort($publicRoutes);
    return $publicRoutes;
  }

  /**
   * @param array<string, mixed> $definition
   */
  private function isPublicAccessRoute(array $definition): bool {
    $requirements = $definition['requirements'] ?? NULL;
    if (!is_array($requirements) || !array_key_exists('_access', $requirements)) {
      return FALSE;
    }

    return $this->isTrueAccessValue($requirements['_access']);
  }

  private function isTrueAccessValue(mixed $value): bool {
    if ($value === TRUE) {
      return TRUE;
    }
    if (is_string($value) && strtoupper(trim($value)) === 'TRUE') {
      return TRUE;
    }
    return FALSE;
  }

  private function relativePath(string $absolutePath): string {
    $webRoot = dirname(__DIR__, 5);
    $prefix = rtrim($webRoot, '/') . '/';
    if (str_starts_with($absolutePath, $prefix)) {
      return substr($absolutePath, strlen($prefix));
    }
    return $absolutePath;
  }

}
