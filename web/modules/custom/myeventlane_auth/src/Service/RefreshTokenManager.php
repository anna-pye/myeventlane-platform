<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Hashed refresh tokens with rotation and revocation.
 */
final class RefreshTokenManager {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Creates the first refresh token in a new family chain.
   *
   * @return array{plain: string, family_id: string}
   */
  public function createInitial(int $uid): array {
    $familyId = $this->randomFamilyId();
    return $this->insertToken($uid, $familyId, NULL);
  }

  /**
   * Rotates a valid refresh token: revokes old row, inserts successor.
   *
   * @return array{plain: string, family_id: string, uid: int}|null
   */
  public function rotate(string $plainRefreshToken): ?array {
    $hash = hash('sha256', $plainRefreshToken);
    $now = $this->time->getRequestTime();

    $reuse = $this->database->select('mel_auth_refresh_token', 't')
      ->fields('t', ['revoked', 'family_id'])
      ->condition('token_hash', $hash)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if ($reuse && (int) $reuse['revoked'] === 1) {
      $this->revokeFamily((string) $reuse['family_id']);
      $this->logger->warning('Detected reuse of revoked refresh token; family revoked.');
      return NULL;
    }

    $transaction = $this->database->startTransaction();

    try {
      $select = $this->database->select('mel_auth_refresh_token', 't')
        ->fields('t')
        ->condition('token_hash', $hash)
        ->condition('revoked', 0)
        ->range(0, 1)
        ->forUpdate();

      $row = $select->execute()->fetchAssoc();
      if (!$row) {
        $this->logger->warning('Refresh token rotation failed: invalid or unknown.');
        return NULL;
      }
      if ((int) $row['expires'] < $now) {
        $this->logger->warning('Refresh token rotation failed: expired.');
        return NULL;
      }

      $this->database->update('mel_auth_refresh_token')
        ->fields([
          'revoked' => 1,
          'last_used' => $now,
        ])
        ->condition('id', (int) $row['id'])
        ->execute();

      $familyId = (string) $row['family_id'];
      $inserted = $this->insertToken((int) $row['uid'], $familyId, (int) $row['id']);
      $inserted['uid'] = (int) $row['uid'];

      unset($transaction);

      $this->logger->notice('Refresh token rotated uid=@uid family=@fam', [
        '@uid' => (string) $row['uid'],
        '@fam' => $familyId,
      ]);

      return $inserted;
    }
    catch (\Throwable $e) {
      $this->logger->error('Refresh rotation error: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Revokes a single token by plaintext (logout one device).
   */
  public function revokeByPlaintext(string $plainRefreshToken): bool {
    $hash = hash('sha256', $plainRefreshToken);
    $updated = $this->database->update('mel_auth_refresh_token')
      ->fields(['revoked' => 1])
      ->condition('token_hash', $hash)
      ->execute();
    if ($updated > 0) {
      $this->logger->notice('Revoked single refresh token.');
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Revokes every non-revoked refresh token for the user (global logout).
   */
  public function revokeAllForUser(int $uid): int {
    $count = $this->database->update('mel_auth_refresh_token')
      ->fields(['revoked' => 1])
      ->condition('uid', $uid)
      ->condition('revoked', 0)
      ->execute();
    if ($count > 0) {
      $this->logger->notice('Revoked @n refresh token(s) for uid @u', [
        '@n' => (string) $count,
        '@u' => (string) $uid,
      ]);
    }
    return (int) $count;
  }

  /**
   * Revokes an entire rotation family (reuse / theft detection).
   */
  public function revokeFamily(string $familyId): void {
    $this->database->update('mel_auth_refresh_token')
      ->fields(['revoked' => 1])
      ->condition('family_id', $familyId)
      ->condition('revoked', 0)
      ->execute();
    $this->logger->warning('Revoked refresh token family @f (possible reuse).', ['@f' => $familyId]);
  }

  /**
   * @return array{plain: string, family_id: string, uid?: int}
   */
  private function insertToken(int $uid, string $familyId, ?int $parentId): array {
    $plain = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $hash = hash('sha256', $plain);
    $now = $this->time->getRequestTime();
    $ttl = (int) $this->configFactory->get('myeventlane_auth.settings')->get('refresh_token_ttl');
    if ($ttl < 86400 || $ttl > 86400 * 90) {
      $ttl = 2592000;
    }
    $expires = $now + $ttl;

    $this->database->insert('mel_auth_refresh_token')
      ->fields([
        'uid' => $uid,
        'token_hash' => $hash,
        'family_id' => $familyId,
        'parent_id' => $parentId,
        'expires' => $expires,
        'created' => $now,
        'last_used' => NULL,
        'revoked' => 0,
      ])
      ->execute();

    return ['plain' => $plain, 'family_id' => $familyId];
  }

  private function randomFamilyId(): string {
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
  }

}
