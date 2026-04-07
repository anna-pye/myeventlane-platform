<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_auth\Service\OAuthClientRepository;
use Drupal\myeventlane_auth\Service\RefreshTokenManager;
use Drupal\myeventlane_auth\Service\TokenGrantService;
use Drupal\myeventlane_auth\Service\TokenIssuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Token, refresh, userinfo, and revocation endpoints.
 */
final class TokenController extends ControllerBase {

  public function __construct(
    private readonly TokenGrantService $tokenGrantService,
    private readonly TokenIssuer $tokenIssuer,
    private readonly OAuthClientRepository $clientRepository,
    private readonly RefreshTokenManager $refreshTokenManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_auth.token_grant_service'),
      $container->get('myeventlane_auth.token_issuer'),
      $container->get('myeventlane_auth.oauth_client_repository'),
      $container->get('myeventlane_auth.refresh_token_manager')
    );
  }

  /**
   * POST /auth/token — authorization_code grant.
   */
  public function token(Request $request): Response {
    if ($request->request->count() === 0 && str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
      $data = json_decode($request->getContent(), TRUE);
      if (is_array($data)) {
        foreach ($data as $k => $v) {
          $request->request->set($k, $v);
        }
      }
    }
    $grant = (string) $request->request->get('grant_type', '');
    if ($grant !== 'authorization_code') {
      return $this->oauthError('unsupported_grant_type', 'Only authorization_code is supported on /auth/token.', 400);
    }
    $clientId = (string) $request->request->get('client_id', '');
    $clientSecret = (string) $request->request->get('client_secret', '');
    $code = (string) $request->request->get('code', '');
    $redirectUri = (string) $request->request->get('redirect_uri', '');
    $codeVerifier = $request->request->get('code_verifier');
    $codeVerifier = $codeVerifier !== NULL && $codeVerifier !== '' ? (string) $codeVerifier : NULL;
    if ($clientId === '' || $clientSecret === '' || $code === '' || $redirectUri === '') {
      return $this->oauthError('invalid_request', 'Missing required parameters.', 400);
    }
    $payload = $this->tokenGrantService->grantFromAuthorizationCode(
      $clientId,
      $clientSecret,
      $code,
      $redirectUri,
      $codeVerifier
    );
    if ($payload === NULL) {
      return $this->oauthError('invalid_grant', 'Invalid code, redirect, or client.', 400);
    }
    return $this->jsonTokenResponse($request, $payload);
  }

  /**
   * POST /auth/refresh — refresh_token grant.
   */
  public function refresh(Request $request): Response {
    if (str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
      $data = json_decode($request->getContent(), TRUE);
      if (is_array($data)) {
        foreach ($data as $k => $v) {
          $request->request->set($k, $v);
        }
      }
    }
    $clientId = (string) $request->request->get('client_id', '');
    $clientSecret = (string) $request->request->get('client_secret', '');
    $refresh = (string) $request->request->get('refresh_token', '');
    if ($refresh === '') {
      $cookies = $request->cookies->all();
      $name = (string) $this->config('myeventlane_auth.settings')->get('refresh_cookie_name');
      if ($name !== '' && isset($cookies[$name])) {
        $refresh = (string) $cookies[$name];
      }
    }
    if ($clientId === '' || $clientSecret === '' || $refresh === '') {
      return $this->oauthError('invalid_request', 'Missing client_id, client_secret, or refresh_token.', 400);
    }
    $payload = $this->tokenGrantService->grantFromRefreshToken($clientId, $clientSecret, $refresh);
    if ($payload === NULL) {
      return $this->oauthError('invalid_grant', 'Invalid refresh token or client.', 400);
    }
    return $this->jsonTokenResponse($request, $payload);
  }

  /**
   * GET /auth/me — Bearer access token.
   */
  public function me(Request $request): Response {
    $header = (string) $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
      return $this->oauthError('invalid_token', 'Missing or invalid Authorization header.', 401);
    }
    $user = $this->tokenIssuer->loadUserFromAccessToken($m[1]);
    if ($user === NULL) {
      return $this->oauthError('invalid_token', 'Access token invalid or expired.', 401);
    }
    $roles = array_values(array_filter($user->getRoles(), static fn(string $r) => $r !== 'authenticated'));
    $data = [
      'sub' => (string) $user->id(),
      'name' => $user->getDisplayName(),
      'mail' => $user->getEmail(),
      'roles' => $roles,
    ];
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * POST /auth/revoke — revoke refresh token or all tokens for bearer.
   */
  public function revoke(Request $request): Response {
    if (str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
      $data = json_decode($request->getContent(), TRUE);
      if (is_array($data)) {
        foreach ($data as $k => $v) {
          $request->request->set($k, $v);
        }
      }
    }
    $action = (string) $request->request->get('action', 'token');
    $header = (string) $request->headers->get('Authorization', '');
    if ($action === 'all' && preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
      $user = $this->tokenIssuer->loadUserFromAccessToken($m[1]);
      if ($user === NULL) {
        return $this->oauthError('invalid_token', 'Invalid access token.', 401);
      }
      $this->refreshTokenManager->revokeAllForUser((int) $user->id());
      $this->getLogger('myeventlane_auth')->notice('Revoked all refresh tokens for uid @u via /auth/revoke.', ['@u' => (string) $user->id()]);
      return new JsonResponse(['revoked' => TRUE], 200);
    }
    $clientId = (string) $request->request->get('client_id', '');
    $clientSecret = (string) $request->request->get('client_secret', '');
    $refresh = (string) $request->request->get('refresh_token', '');
    if ($clientId === '' || $clientSecret === '' || $refresh === '') {
      return $this->oauthError('invalid_request', 'Missing parameters for revoke.', 400);
    }
    if (!$this->clientRepository->validateConfidentialClient($clientId, $clientSecret)) {
      return $this->oauthError('invalid_client', 'Invalid client credentials.', 401);
    }
    $ok = $this->refreshTokenManager->revokeByPlaintext($refresh);
    return new JsonResponse(['revoked' => $ok], $ok ? 200 : 400);
  }

  /**
   * @param array{access_token: string, expires_in: int, refresh_token: string, token_type: string} $payload
   */
  private function jsonTokenResponse(Request $request, array $payload): JsonResponse {
    $response = new JsonResponse($payload);
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Pragma', 'no-cache');
    $this->attachRefreshCookie($request, $response, $payload['refresh_token']);
    return $response;
  }

  private function attachRefreshCookie(Request $request, JsonResponse $response, string $plainRefresh): void {
    $config = $this->config('myeventlane_auth.settings');
    $name = (string) $config->get('refresh_cookie_name');
    $path = (string) $config->get('refresh_cookie_path');
    if ($name === '' || $path === '') {
      return;
    }
    $ttl = (int) $config->get('refresh_token_ttl');
    if ($ttl < 86400) {
      $ttl = 2592000;
    }
    $expire = $request->server->get('REQUEST_TIME', time()) + $ttl;
    $secure = $request->isSecure();
    $sameSite = $secure ? Cookie::SAMESITE_NONE : NULL;
    $response->headers->setCookie(new Cookie(
      $name,
      $plainRefresh,
      $expire,
      $path,
      NULL,
      $secure,
      TRUE,
      FALSE,
      $sameSite
    ));
  }

  private function oauthError(string $code, string $description, int $status): JsonResponse {
    $this->getLogger('myeventlane_auth')->warning('OAuth error @code: @desc', ['@code' => $code, '@desc' => $description]);
    $response = new JsonResponse(['error' => $code, 'error_description' => $description], $status);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
