<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Pure routing policy for safe customer and organiser post-login destinations.
 */
final class PostLoginRoutePolicy {

  /**
   * Returns the canonical route for an explicit identity intent.
   */
  public function routeForIntent(string $intent): ?string {
    return $intent === IdentityIntentResolver::INTENT_CREATE_EVENT
      ? 'myeventlane_vendor.create_event_gateway'
      : NULL;
  }

  /**
   * Returns a safe requested internal destination or NULL.
   */
  public function safeExplicitDestination(Request $request, int $uid): ?string {
    $destination = $request->query->get('destination');
    if (!is_string($destination) || $destination === '') {
      return NULL;
    }
    $destination = rawurldecode(trim($destination));
    if (
      !str_starts_with($destination, '/')
      || str_starts_with($destination, '//')
      || str_contains($destination, '..')
      || str_contains($destination, "\0")
      || strlen($destination) > 2048
    ) {
      return NULL;
    }

    $path = (string) (parse_url($destination, PHP_URL_PATH) ?: '/');
    if (
      $path === '/user/' . $uid
      || $path === '/user'
      || $path === '/user/login'
      || $path === '/user/register'
      || $path === '/mel/post-login'
      || $path === '/mel/auth/continue'
      || str_starts_with($path, '/admin')
      || str_starts_with($path, '/system/')
      || str_starts_with($path, '/batch')
    ) {
      return NULL;
    }

    return $destination;
  }

  /**
   * Returns the default account route after a normal login.
   */
  public function defaultRoute(bool $has_vendor, bool $onboarding_complete): string {
    if (!$has_vendor) {
      return 'myeventlane_account.dashboard';
    }
    return $onboarding_complete
      ? 'myeventlane_vendor.console.dashboard'
      : 'myeventlane_event_studio.create';
  }

}
