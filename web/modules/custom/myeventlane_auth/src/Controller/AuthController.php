<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_auth\Service\AuthRedirectValidator;
use Drupal\myeventlane_auth\Service\AuthorizationCodeManager;
use Drupal\myeventlane_auth\Service\OAuthClientRepository;
use Drupal\myeventlane_auth\Service\RefreshTokenManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * SSO login, authorize, and logout on the auth host.
 */
final class AuthController extends ControllerBase {

  public function __construct(
    private readonly AuthRedirectValidator $redirectValidator,
    private readonly AuthorizationCodeManager $authorizationCodeManager,
    private readonly OAuthClientRepository $clientRepository,
    private readonly RefreshTokenManager $refreshTokenManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_auth.auth_redirect_validator'),
      $container->get('myeventlane_auth.authorization_code_manager'),
      $container->get('myeventlane_auth.oauth_client_repository'),
      $container->get('myeventlane_auth.refresh_token_manager')
    );
  }

  /**
   * Starts the OAuth flow; sends anonymous users to Drupal login first.
   */
  public function login(Request $request): Response {
    $params = $this->validateAuthorizeParams($request);
    if (!$this->clientRepository->getClient($params['client_id'])) {
      throw new BadRequestHttpException('Unknown client_id.');
    }
    if ($this->currentUser()->isAuthenticated()) {
      return $this->redirectAuthorize($params);
    }
    $authorize = Url::fromRoute('myeventlane_auth.authorize', [], [
      'query' => $params,
    ])->toString();
    return new RedirectResponse(Url::fromRoute('user.login', [], [
      'absolute' => TRUE,
      'query' => ['destination' => $authorize],
    ])->toString(), 302);
  }

  /**
   * Issues an authorization code and redirects back to the client.
   */
  public function authorize(Request $request): Response {
    $params = $this->validateAuthorizeParams($request);
    $clientId = $params['client_id'];
    $redirectUri = $params['redirect_uri'];
    if (!$this->clientRepository->getClient($clientId)) {
      throw new BadRequestHttpException('Unknown client_id.');
    }
    if (!$this->redirectValidator->isRedirectUriAllowed($redirectUri)) {
      $this->getLogger('myeventlane_auth')->warning('Audit: authorize rejected — redirect_uri not allowlisted.');
      throw new BadRequestHttpException('redirect_uri is not allowed.');
    }
    if (!$this->redirectValidator->matchesClientRedirectPrefixes($clientId, $redirectUri)) {
      $this->getLogger('myeventlane_auth')->warning('Audit: authorize rejected — redirect_uri prefix mismatch for client @id.', ['@id' => $clientId]);
      throw new BadRequestHttpException('redirect_uri does not match client configuration.');
    }
    $challenge = $params['code_challenge'] ?? NULL;
    $method = $params['code_challenge_method'] ?? NULL;
    if ($challenge !== NULL && $method === NULL) {
      throw new BadRequestHttpException('code_challenge_method is required with code_challenge.');
    }
    $plain = $this->authorizationCodeManager->issue(
      (int) $this->currentUser()->id(),
      $clientId,
      $redirectUri,
      $challenge,
      $method,
    );
    $separator = str_contains($redirectUri, '?') ? '&' : '?';
    $target = $redirectUri . $separator . http_build_query([
      'code' => $plain,
      'state' => $params['state'],
    ], '', '&', PHP_QUERY_RFC3986);
    return new RedirectResponse($target, 302);
  }

  /**
   * Ends auth-host session, revokes refresh tokens, clears refresh cookie.
   */
  public function logout(Request $request): Response {
    $config = $this->config('myeventlane_auth.settings');
    $uid = (int) $this->currentUser()->id();
    $this->refreshTokenManager->revokeAllForUser($uid);
    $this->getLogger('myeventlane_auth')->notice('Audit: auth-host logout for uid @uid.', ['@uid' => (string) $uid]);

    $cookieName = (string) $config->get('refresh_cookie_name');
    $cookiePath = (string) $config->get('refresh_cookie_path');
    $cookieDomain = trim((string) $config->get('refresh_cookie_domain'));
    if ($cookieDomain === '') {
      $cookieDomain = NULL;
    }
    $response = new RedirectResponse(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), 302);
    $secure = $request->isSecure();
    $response->headers->setCookie(new Cookie(
      $cookieName,
      'deleted',
      $request->server->get('REQUEST_TIME', time()) - 3600,
      $cookiePath,
      $cookieDomain,
      $secure,
      TRUE,
      FALSE,
      $secure ? Cookie::SAMESITE_NONE : NULL
    ));
    user_logout();
    return $response;
  }

  /**
   * @return array{response_type: string, client_id: string, redirect_uri: string, state: string, code_challenge?: string, code_challenge_method?: string}
   */
  private function validateAuthorizeParams(Request $request): array {
    $responseType = (string) $request->query->get('response_type', '');
    if ($responseType !== 'code') {
      throw new BadRequestHttpException('response_type must be "code".');
    }
    $clientId = trim((string) $request->query->get('client_id', ''));
    $redirectUri = trim((string) $request->query->get('redirect_uri', ''));
    $state = (string) $request->query->get('state', '');
    if ($clientId === '' || $redirectUri === '' || $state === '') {
      throw new BadRequestHttpException('client_id, redirect_uri, and state are required.');
    }
    if (strlen($state) < 8) {
      throw new BadRequestHttpException('state must be at least 8 characters.');
    }
    $codeChallenge = $request->query->get('code_challenge');
    $codeChallengeMethod = $request->query->get('code_challenge_method');
    $out = [
      'response_type' => 'code',
      'client_id' => $clientId,
      'redirect_uri' => $redirectUri,
      'state' => $state,
    ];
    if ($codeChallenge !== NULL && $codeChallenge !== '') {
      $out['code_challenge'] = (string) $codeChallenge;
      $out['code_challenge_method'] = $codeChallengeMethod !== NULL && $codeChallengeMethod !== ''
        ? (string) $codeChallengeMethod
        : 'S256';
    }
    return $out;
  }

  /**
   * @param array{response_type: string, client_id: string, redirect_uri: string, state: string, code_challenge?: string, code_challenge_method?: string} $params
   */
  private function redirectAuthorize(array $params): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('myeventlane_auth.authorize', [], [
      'query' => $params,
    ])->toString(), 302);
  }

}
