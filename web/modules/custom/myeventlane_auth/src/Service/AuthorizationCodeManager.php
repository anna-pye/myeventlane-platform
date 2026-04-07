<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Persists and consumes one-time authorization codes.
 */
final class AuthorizationCodeManager {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Creates a new authorization code (plaintext returned once).
   *
   * @return string
   *   Plaintext code to embed in redirect.
   */
  public function issue(
    int $uid,
    string $clientId,
    string $redirectUri,
    ?string $codeChallenge,
    ?string $codeChallengeMethod,
  ): string {
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $now = $this->time->getRequestTime();
    $ttl = (int) $this->configFactory->get('myeventlane_auth.settings')->get('authorization_code_ttl');
    if ($ttl < 60 || $ttl > 3600) {
      $ttl = 600;
    }
    $expires = $now + $ttl;

    $this->database->insert('mel_auth_authorization_code')
      ->fields([
        'code_hash' => $hash,
        'uid' => $uid,
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => $codeChallengeMethod,
        'expires' => $expires,
        'created' => $now,
        'used' => 0,
      ])
      ->execute();

    $this->logger->notice('Issued authorization code uid=@uid client=@client exp=@exp', [
      '@uid' => (string) $uid,
      '@client' => $clientId,
      '@exp' => (string) $expires,
    ]);

    return $plain;
  }

  /**
   * Validates PKCE S256 code_verifier against stored challenge.
   */
  public function verifyPkce(?string $storedChallenge, ?string $storedMethod, ?string $codeVerifier): bool {
    if ($storedChallenge === NULL || $storedChallenge === '') {
      return TRUE;
    }
    if (($storedMethod ?? 'S256') !== 'S256') {
      $this->logger->warning('Unsupported PKCE method @m', ['@m' => (string) $storedMethod]);
      return FALSE;
    }
    if ($codeVerifier === NULL || $codeVerifier === '') {
      return FALSE;
    }
    $len = strlen($codeVerifier);
    if ($len < 43 || $len > 128) {
      return FALSE;
    }
    $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, TRUE)), '+/', '-_'), '=');
    return hash_equals($storedChallenge, $computed);
  }

  /**
   * Consumes a code exactly once; returns metadata for token issuance.
   *
   * @return array{uid: int, client_id: string, redirect_uri: string, code_challenge: ?string, code_challenge_method: ?string}|null
   */
  public function consume(
    string $plainCode,
    string $clientId,
    string $redirectUri,
    ?string $codeVerifier,
  ): ?array {
    $hash = hash('sha256', $plainCode);
    $now = $this->time->getRequestTime();

    $transaction = $this->database->startTransaction();

    try {
      $select = $this->database->select('mel_auth_authorization_code', 'c')
        ->fields('c')
        ->condition('code_hash', $hash)
        ->condition('client_id', $clientId)
        ->condition('used', 0)
        ->range(0, 1)
        ->forUpdate();

      $row = $select->execute()->fetchAssoc();
      if (!$row) {
        $this->logger->warning('Authorization code exchange failed: not found or used.');
        return NULL;
      }
      if ((int) $row['expires'] < $now) {
        $this->logger->warning('Authorization code expired.');
        return NULL;
      }
      if (!hash_equals((string) $row['redirect_uri'], $redirectUri)) {
        $this->logger->warning('Authorization code redirect_uri mismatch.');
        return NULL;
      }

      $challenge = $row['code_challenge'] !== NULL ? (string) $row['code_challenge'] : NULL;
      $method = $row['code_challenge_method'] !== NULL ? (string) $row['code_challenge_method'] : NULL;
      if (!$this->verifyPkce($challenge, $method, $codeVerifier)) {
        $this->logger->warning('PKCE verification failed.');
        return NULL;
      }

      $this->database->update('mel_auth_authorization_code')
        ->fields(['used' => 1])
        ->condition('id', (int) $row['id'])
        ->execute();

      unset($transaction);

      return [
        'uid' => (int) $row['uid'],
        'client_id' => (string) $row['client_id'],
        'redirect_uri' => (string) $row['redirect_uri'],
        'code_challenge' => $challenge,
        'code_challenge_method' => $method,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Authorization code consume error: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

}
