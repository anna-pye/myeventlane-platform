<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_auth\Utility\JwtHs256;
use Drupal\user\UserInterface;

/**
 * Issues and validates short-lived HS256 access JWTs.
 */
final class TokenIssuer {

  public function __construct(
    private readonly JwtHs256 $jwt,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Issues an access JWT for the given Drupal user.
   */
  public function issueAccessToken(UserInterface $user): string {
    $secret = myeventlane_auth_signing_key();
    if ($secret === '') {
      throw new \RuntimeException('Missing $settings["myeventlane_auth"]["signing_key"] for access JWTs.');
    }
    $ttl = (int) $this->configFactory->get('myeventlane_auth.settings')->get('access_token_ttl');
    if ($ttl < 300 || $ttl > 900) {
      $ttl = min(900, max(300, $ttl));
    }
    $now = $this->time->getRequestTime();
    $claims = [
      'sub' => (string) $user->id(),
      'iat' => $now,
      'exp' => $now + $ttl,
      'jti' => bin2hex(random_bytes(16)),
    ];
    $issuer = $this->effectiveJwtIssuer();
    if ($issuer !== '') {
      $claims['iss'] = $issuer;
    }
    $audience = $this->effectiveJwtAudience();
    if ($audience !== '') {
      $claims['aud'] = $audience;
    }
    $token = $this->jwt->encode($claims, $secret);
    $this->logger->notice('Issued access JWT uid=@uid exp=@exp', [
      '@uid' => (string) $user->id(),
      '@exp' => (string) ($now + $ttl),
    ]);
    return $token;
  }

  /**
   * Validates bearer JWT and loads the user (issuer/audience enforced when configured).
   */
  public function loadUserFromAccessToken(string $jwtString): ?UserInterface {
    $secret = myeventlane_auth_signing_key();
    if ($secret === '') {
      $this->logger->error('Access JWT validation skipped: signing key not configured.');
      return NULL;
    }
    $now = $this->time->getRequestTime();
    $payload = $this->jwt->decode($jwtString, $secret, $now);
    if ($payload === NULL) {
      return NULL;
    }
    $expectedIssuer = $this->effectiveJwtIssuer();
    if ($expectedIssuer !== '') {
      if (($payload['iss'] ?? '') !== $expectedIssuer) {
        $this->logger->warning('Access JWT rejected: issuer mismatch.');
        return NULL;
      }
    }
    $expectedAudience = $this->effectiveJwtAudience();
    if ($expectedAudience !== '') {
      $aud = $payload['aud'] ?? NULL;
      $audOk = FALSE;
      if (is_string($aud) && hash_equals($expectedAudience, $aud)) {
        $audOk = TRUE;
      }
      elseif (is_array($aud)) {
        foreach ($aud as $a) {
          if (is_string($a) && hash_equals($expectedAudience, $a)) {
            $audOk = TRUE;
            break;
          }
        }
      }
      if (!$audOk) {
        $this->logger->warning('Access JWT rejected: audience mismatch.');
        return NULL;
      }
    }
    $uid = isset($payload['sub']) ? (int) $payload['sub'] : 0;
    if ($uid < 1) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return NULL;
    }
    return $user;
  }

  /**
   * Canonical issuer string for JWTs (auth host); empty if not configured.
   */
  private function effectiveJwtIssuer(): string {
    $config = $this->configFactory->get('myeventlane_auth.settings');
    $issuer = trim((string) $config->get('jwt_issuer'));
    if ($issuer !== '') {
      return rtrim($issuer, '/');
    }
    $base = trim((string) $config->get('auth_base_url'));
    return $base !== '' ? rtrim($base, '/') : '';
  }

  /**
   * Expected audience claim; empty disables aud claim and related validation.
   */
  private function effectiveJwtAudience(): string {
    return trim((string) $this->configFactory->get('myeventlane_auth.settings')->get('jwt_audience'));
  }

}
