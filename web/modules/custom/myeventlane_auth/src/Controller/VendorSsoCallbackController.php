<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\myeventlane_auth\Service\TokenGrantService;
use Drupal\myeventlane_auth\Service\TokenIssuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vendor-host OAuth callback: exchanges code using server-side client secret.
 *
 * Establishes a Drupal session on the vendor host (vendor-scoped cookie) after
 * a successful SSO exchange on the auth host.
 */
final class VendorSsoCallbackController extends ControllerBase {

  public function __construct(
    private readonly TokenGrantService $tokenGrantService,
    private readonly TokenIssuer $tokenIssuer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_auth.token_grant_service'),
      $container->get('myeventlane_auth.token_issuer')
    );
  }

  public function callback(Request $request): Response {
    $session = $request->getSession();
    $expectedState = (string) $session->get('myeventlane_auth.oauth_state', '');
    $redirectUri = (string) $session->get('myeventlane_auth.oauth_redirect_uri', '');
    $session->remove('myeventlane_auth.oauth_state');
    $session->remove('myeventlane_auth.oauth_redirect_uri');

    $state = (string) $request->query->get('state', '');
    if ($expectedState === '' || $redirectUri === '' || !hash_equals($expectedState, $state)) {
      $this->getLogger('myeventlane_auth')->warning('Vendor SSO callback rejected: invalid or missing state.');
      throw new AccessDeniedHttpException('Invalid SSO state.');
    }

    $code = (string) $request->query->get('code', '');
    if ($code === '') {
      throw new AccessDeniedHttpException('Missing authorization code.');
    }

    $config = $this->config('myeventlane_auth.settings');
    $clientId = (string) $config->get('vendor_sso_client_id');
    if ($clientId === '') {
      throw new AccessDeniedHttpException('SSO client is not configured.');
    }

    $clientSecret = $this->resolveMachineClientSecret($clientId);
    if ($clientSecret === '') {
      $this->getLogger('myeventlane_auth')->error('Missing machine client secret for SSO (settings.myeventlane_auth.client_secrets).');
      throw new AccessDeniedHttpException('SSO is not configured.');
    }

    $tokens = $this->tokenGrantService->grantFromAuthorizationCode(
      $clientId,
      $clientSecret,
      $code,
      $redirectUri,
      NULL
    );
    if ($tokens === NULL) {
      $this->getLogger('myeventlane_auth')->error('Vendor SSO token exchange failed.');
      throw new AccessDeniedHttpException('Token exchange failed.');
    }

    $user = $this->tokenIssuer->loadUserFromAccessToken($tokens['access_token']);
    if ($user === NULL) {
      throw new AccessDeniedHttpException('Could not resolve user from access token.');
    }

    $session->set('myeventlane_auth.sso_tokens', [
      'access_token' => $tokens['access_token'],
      'refresh_token' => $tokens['refresh_token'],
      'expires_in' => $tokens['expires_in'],
      'obtained' => $request->server->get('REQUEST_TIME', time()),
    ]);

    user_login_finalize($user);

    $this->getLogger('myeventlane_auth')->notice('Vendor SSO completed for uid @uid.', ['@uid' => (string) $user->id()]);

    $path = (string) $config->get('vendor_sso_success_path');
    if ($path === '') {
      $path = '/vendor/dashboard';
    }
    return new RedirectResponse(Url::fromUserInput($path, ['absolute' => TRUE])->toString(), 302);
  }

  private function resolveMachineClientSecret(string $clientId): string {
    $root = Settings::get('myeventlane_auth', []);
    if (!is_array($root) || !isset($root['client_secrets']) || !is_array($root['client_secrets'])) {
      return '';
    }
    $secrets = $root['client_secrets'];
    return isset($secrets[$clientId]) && is_string($secrets[$clientId]) ? $secrets[$clientId] : '';
  }

}
