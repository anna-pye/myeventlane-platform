<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;

/**
 * OAuth2-style token grants (authorization_code, refresh_token).
 */
final class TokenGrantService {

  public function __construct(
    private readonly OAuthClientRepository $clientRepository,
    private readonly AuthRedirectValidator $redirectValidator,
    private readonly AuthorizationCodeManager $authorizationCodeManager,
    private readonly RefreshTokenManager $refreshTokenManager,
    private readonly TokenIssuer $tokenIssuer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}|null
   */
  public function grantFromAuthorizationCode(
    string $clientId,
    string $clientSecret,
    string $code,
    string $redirectUri,
    ?string $codeVerifier,
  ): ?array {
    if (!$this->clientRepository->validateConfidentialClient($clientId, $clientSecret)) {
      $this->logger->warning('Authorization code grant: invalid client credentials.');
      return NULL;
    }
    if (!$this->redirectValidator->isRedirectUriAllowed($redirectUri)) {
      return NULL;
    }
    if (!$this->redirectValidator->matchesClientRedirectPrefixes($clientId, $redirectUri)) {
      return NULL;
    }
    $meta = $this->authorizationCodeManager->consume($code, $clientId, $redirectUri, $codeVerifier);
    if ($meta === NULL) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($meta['uid']);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return NULL;
    }
    $access = $this->tokenIssuer->issueAccessToken($user);
    $refresh = $this->refreshTokenManager->createInitial((int) $user->id());
    $ttl = (int) $this->configFactory->get('myeventlane_auth.settings')->get('access_token_ttl');
    if ($ttl < 300 || $ttl > 900) {
      $ttl = 900;
    }
    return [
      'access_token' => $access,
      'expires_in' => $ttl,
      'refresh_token' => $refresh['plain'],
      'token_type' => 'Bearer',
    ];
  }

  /**
   * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}|null
   */
  public function grantFromRefreshToken(
    string $clientId,
    string $clientSecret,
    string $refreshToken,
  ): ?array {
    if (!$this->clientRepository->validateConfidentialClient($clientId, $clientSecret)) {
      $this->logger->warning('Refresh grant: invalid client credentials.');
      return NULL;
    }
    $rotated = $this->refreshTokenManager->rotate($refreshToken);
    if ($rotated === NULL) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($rotated['uid']);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return NULL;
    }
    $access = $this->tokenIssuer->issueAccessToken($user);
    $ttl = (int) $this->configFactory->get('myeventlane_auth.settings')->get('access_token_ttl');
    if ($ttl < 300 || $ttl > 900) {
      $ttl = 900;
    }
    return [
      'access_token' => $access,
      'expires_in' => $ttl,
      'refresh_token' => $rotated['plain'],
      'token_type' => 'Bearer',
    ];
  }

}
