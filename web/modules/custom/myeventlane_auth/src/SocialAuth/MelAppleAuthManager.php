<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\SocialAuth;

use Drupal\Core\Utility\Error;
use Drupal\social_auth_apple\AppleAuthManager;
use League\OAuth2\Client\Token\AppleAccessToken;

/**
 * Adds Apple nonce correlation and explicit identity-token claim validation.
 */
final class MelAppleAuthManager extends AppleAuthManager {

  public const NONCE_SESSION_KEY = 'myeventlane_auth.apple_nonce';

  /**
   * {@inheritdoc}
   */
  public function getAuthorizationUrl(): string {
    $nonce = self::generateNonce();
    if ($this->request === NULL || !$this->request->hasSession()) {
      throw new \UnexpectedValueException('Apple authentication requires a browser session.');
    }
    $this->request->getSession()->set(self::NONCE_SESSION_KEY, $nonce);

    $scopes = ['name', 'email'];
    if ($extraScopes = $this->getScopes()) {
      $scopes = array_merge($scopes, explode(',', $extraScopes));
    }

    return $this->client->getAuthorizationUrl([
      'scope' => $scopes,
      'nonce' => $nonce,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(): void {
    $code = $this->request?->query->get('code');
    if (!is_string($code) || $code === '') {
      return;
    }

    try {
      if (!$this->request->hasSession()) {
        throw new \UnexpectedValueException('Apple authentication session is missing.');
      }
      $session = $this->request->getSession();
      $nonce = $session->get(self::NONCE_SESSION_KEY);
      $session->remove(self::NONCE_SESSION_KEY);
      if (!is_string($nonce) || $nonce === '') {
        throw new \UnexpectedValueException('Apple authentication nonce is missing.');
      }

      $token = $this->client->getAccessToken('authorization_code', ['code' => $code]);
      if (!$token instanceof AppleAccessToken || !is_string($token->getIdToken())) {
        throw new \UnexpectedValueException('Apple identity token is missing.');
      }

      $validator = new AppleIdentityTokenValidator($this->client->getHttpClient());
      $validator->validate(
        $token->getIdToken(),
        (string) $this->settings->get('client_id'),
        $nonce,
      );
      $this->setAccessToken($token);
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('social_auth_apple')->error(
        'Apple identity verification failed. ' . Error::DEFAULT_ERROR_MESSAGE . ' @backtrace_string',
        Error::decodeException($exception),
      );
    }
  }

  /**
   * Generates an unguessable, URL-safe OpenID nonce.
   */
  private static function generateNonce(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

}
