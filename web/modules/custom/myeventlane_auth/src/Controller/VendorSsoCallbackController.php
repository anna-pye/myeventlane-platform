<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\myeventlane_auth\Service\MelAuthOAuthSession;
use Drupal\myeventlane_auth\Service\TokenGrantService;
use Drupal\myeventlane_auth\Service\TokenIssuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
    $instance = new static(
      $container->get('myeventlane_auth.token_grant_service'),
      $container->get('myeventlane_auth.token_issuer'),
    );
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  public function callback(Request $request): Response {
    $session = $request->getSession();
    $now = MelAuthOAuthSession::requestTime($request);

    $expectedState = (string) $session->get(MelAuthOAuthSession::KEY_STATE, '');
    $redirectUri = (string) $session->get(MelAuthOAuthSession::KEY_REDIRECT_URI, '');
    $stateCreatedRaw = $session->get(MelAuthOAuthSession::KEY_STATE_CREATED);
    $stateCreatedTs = is_numeric($stateCreatedRaw) ? (int) $stateCreatedRaw : 0;

    $state = (string) $request->query->get('state', '');

    // Validate state fully before any single-use clear (malformed requests keep session intact until fail()).
    if ($expectedState === '' || $redirectUri === '') {
      return $this->fail(
        $request,
        'invalid_or_missing_state',
        'Vendor SSO callback rejected: missing session state or redirect_uri.',
        $redirectUri,
        '',
      );
    }

    if ($state === '') {
      return $this->fail(
        $request,
        'invalid_or_missing_state',
        'Vendor SSO callback rejected: missing state query parameter.',
        $redirectUri,
        '',
      );
    }

    if (!hash_equals($expectedState, $state)) {
      return $this->fail(
        $request,
        'state_mismatch',
        'Vendor SSO callback rejected: state mismatch (tabs, session, or race).',
        $redirectUri,
        (string) $this->config('myeventlane_auth.settings')->get('vendor_sso_client_id'),
      );
    }

    if ($stateCreatedTs < 1 || ($now - $stateCreatedTs) > MelAuthOAuthSession::STATE_TTL_SECONDS) {
      $config = $this->config('myeventlane_auth.settings');
      $clientIdForLog = (string) $config->get('vendor_sso_client_id');
      return $this->fail(
        $request,
        'state_expired',
        'Vendor SSO callback rejected: state expired.',
        $redirectUri,
        $clientIdForLog,
      );
    }

    // Single-use: only after state is fully valid.
    MelAuthOAuthSession::clearOAuthState($session);

    $code = (string) $request->query->get('code', '');
    $config = $this->config('myeventlane_auth.settings');
    $clientId = (string) $config->get('vendor_sso_client_id');

    if ($code === '') {
      return $this->fail(
        $request,
        'missing_code',
        'Vendor SSO callback: missing authorization code.',
        $redirectUri,
        $clientId,
      );
    }

    if ($clientId === '') {
      return $this->fail($request, 'missing_client_id', 'Vendor SSO callback: vendor_sso_client_id is not configured.', $redirectUri, '');
    }

    $clientSecret = $this->resolveMachineClientSecret($clientId);
    if ($clientSecret === '') {
      return $this->fail(
        $request,
        'missing_client_secret',
        'Missing machine client secret for SSO (settings.myeventlane_auth.client_secrets).',
        $redirectUri,
        $clientId,
      );
    }

    $tokens = $this->tokenGrantService->grantFromAuthorizationCode(
      $clientId,
      $clientSecret,
      $code,
      $redirectUri,
      NULL
    );
    if ($tokens === NULL) {
      return $this->fail($request, 'token_exchange_failed', 'Vendor SSO token exchange failed.', $redirectUri, $clientId);
    }

    $user = $this->tokenIssuer->loadUserFromAccessToken($tokens['access_token']);
    if ($user === NULL) {
      return $this->fail(
        $request,
        'user_from_token_failed',
        'Vendor SSO: could not resolve user from access token.',
        $redirectUri,
        $clientId,
      );
    }

    user_login_finalize($user);

    // Correlation must be read before clearForSuccess() (it removes KEY_CORRELATION_ID).
    $correlationId = (string) $session->get(MelAuthOAuthSession::KEY_CORRELATION_ID, '');
    MelAuthOAuthSession::clearForSuccess($session);

    $logContext = ['@uid' => (string) $user->id()];
    if ($correlationId !== '') {
      $logContext['correlation_id'] = $correlationId;
    }
    $this->getLogger('myeventlane_auth')->notice('Audit: vendor SSO completed for uid @uid.', $logContext);

    $path = $this->sanitizeVendorInternalPath((string) $config->get('vendor_sso_success_path'));
    return new RedirectResponse(Url::fromUri('internal:' . $path, ['absolute' => TRUE])->toString(), 302);
  }

  /**
   * Clears OAuth session data, logs with context, shows a generic message, redirects.
   */
  private function fail(
    Request $request,
    string $reasonCode,
    string $logMessage,
    string $redirectUri,
    string $clientId,
  ): Response {
    $session = $request->getSession();
    // Correlation must be read before clearForFailure() (it removes KEY_CORRELATION_ID).
    $correlationId = (string) $session->get(MelAuthOAuthSession::KEY_CORRELATION_ID, '');

    MelAuthOAuthSession::clearForFailure($session);

    $failCount = (int) $session->get(MelAuthOAuthSession::KEY_FAIL_COUNT, 0);
    $failCount++;
    $session->set(MelAuthOAuthSession::KEY_FAIL_COUNT, $failCount);

    $logContext = [
      '@message' => $logMessage,
      'reason_code' => $reasonCode,
    ];
    if ($clientId !== '') {
      $logContext['client_id'] = $clientId;
    }
    if ($redirectUri !== '') {
      $logContext['redirect_uri'] = $redirectUri;
    }
    if ($correlationId !== '') {
      $logContext['correlation_id'] = $correlationId;
    }
    $this->getLogger('myeventlane_auth')->error('Audit: vendor SSO failure — @message', $logContext);

    $this->messenger()->addError($this->t("We couldn't complete sign-in. Please try again."));

    // After repeated failures, stop sending users back to /vendor/dashboard (avoids SSO retry loops).
    $query = [];
    if ($failCount < 2) {
      $query['destination'] = '/vendor/dashboard';
    }

    $url = Url::fromRoute('user.login', [], [
      'absolute' => TRUE,
      'query' => $query,
    ])->toString();

    return new RedirectResponse($url, 302);
  }

  private function resolveMachineClientSecret(string $clientId): string {
    $root = Settings::get('myeventlane_auth', []);
    if (!is_array($root) || !isset($root['client_secrets']) || !is_array($root['client_secrets'])) {
      return '';
    }
    $secrets = $root['client_secrets'];
    return isset($secrets[$clientId]) && is_string($secrets[$clientId]) ? $secrets[$clientId] : '';
  }

  /**
   * Restricts configured success path to a safe internal path (no open redirects).
   */
  private function sanitizeVendorInternalPath(string $path): string {
    $path = trim($path);
    if ($path === '') {
      return '/vendor/dashboard';
    }
    if (strlen($path) > 512) {
      return '/vendor/dashboard';
    }
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
      return '/vendor/dashboard';
    }
    if (str_contains($path, '://') || str_contains($path, "\0") || str_contains($path, "\n")) {
      return '/vendor/dashboard';
    }
    return $path;
  }

}
