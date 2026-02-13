<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AJAX endpoint to update cookie consent preferences.
 */
final class CookiePreferencesController extends ControllerBase {

  /**
   * Handles POST to update cookie preferences.
   *
   * Expects JSON body: { "necessary": true, "analytics": false, "marketing": false }
   * Returns JSON with success status.
   */
  public function update(Request $request): JsonResponse {
    $content = $request->getContent();
    if (empty($content)) {
      return new JsonResponse(['error' => 'No content'], Response::HTTP_BAD_REQUEST);
    }

    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
    }

    $necessary = isset($data['necessary']) && $data['necessary'] === TRUE;
    $analytics = isset($data['analytics']) && $data['analytics'] === TRUE;
    $marketing = isset($data['marketing']) && $data['marketing'] === TRUE;

    // Necessary is always true when consent is given.
    if (!$necessary) {
      $necessary = TRUE;
    }

    $payload = [
      'necessary' => $necessary,
      'analytics' => $analytics,
      'marketing' => $marketing,
      'timestamp' => time(),
    ];

    $response = new JsonResponse([
      'success' => TRUE,
      'preferences' => $payload,
    ]);
    $response->headers->set('Cache-Control', 'no-store');

    // Set consent cookie (365 days).
    $cookieValue = json_encode($payload);
    $response->headers->setCookie(
      new \Symfony\Component\HttpFoundation\Cookie(
        'mel_consent',
        $cookieValue,
        time() + 86400 * 365,
        '/',
        NULL,
        $request->isSecure(),
        TRUE,
        FALSE,
        'lax'
      )
    );

    return $response;
  }

}
