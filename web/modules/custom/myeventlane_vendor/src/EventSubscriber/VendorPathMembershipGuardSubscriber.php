<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces: authenticated users on the vendor host must not use vendor console
 * paths (except onboarding and public entry points) without a linked vendor.
 *
 * Aligns with VendorConsoleGateRedirectSubscriber path rules (see isVendorPathRequiringOrganiser()).
 * Runs at priority 31, after the router, before Event studio rate limits (30) and
 * @see \Drupal\myeventlane_vendor\EventSubscriber\EventStudioVendorOnboardingGateSubscriber
 */
final class VendorPathMembershipGuardSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly DomainDetector $domainDetector,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 31]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || \PHP_SAPI === 'cli') {
      return;
    }
    if ($event->hasResponse()) {
      return;
    }
    $request = $event->getRequest();
    $path = $request->getPathInfo() ?: '/';
    if (!($path === '/vendor' || str_starts_with($path, '/vendor/'))) {
      return;
    }
    if (str_starts_with($path, '/vendor/stripe') || str_starts_with($path, '/stripe/')) {
      return;
    }
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
      return;
    }
    if ($request->isXmlHttpRequest()) {
      return;
    }
    if ($this->currentUser->isAnonymous()) {
      return;
    }
    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return;
    }

    if (!$this->isVendorPathRequiringOrganiser($path)) {
      return;
    }

    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($vendor_ids !== []) {
      return;
    }

    try {
      $url = Url::fromRoute('myeventlane_vendor.onboard.profile', [], [
        'query' => $this->buildOnboardDestinationQuery($request),
      ])->toString();
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL vendor path guard: onboard profile URL failed uid=@uid @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->notice('Redirected to onboarding uid=@uid', [
      '@uid' => (string) $uid,
    ]);
    $this->logger->notice('MEL vendor path guard: redirect path=@path uid=@uid reason=no_vendor_membership final_route=myeventlane_vendor.onboard.profile', [
      '@path' => $path,
      '@uid' => (string) $uid,
    ]);
    $event->setResponse(new RedirectResponse($url, 302));
  }

  /**
   * @return array<string, string>
   */
  private function buildOnboardDestinationQuery(Request $request): array {
    return [
      'destination' => $this->buildReturnDestination($request),
    ];
  }

  private function buildReturnDestination(Request $request): string {
    $path = $request->getPathInfo();
    $query = $request->query->all();
    unset($query['destination']);
    if ($query === []) {
      return $path;
    }
    return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * True for vendor-console paths the organiser may not use without a vendor
   * entity, mirroring the intent of VendorConsoleGateRedirectSubscriber::isVendorConsolePath().
   */
  private function isVendorPathRequiringOrganiser(string $path): bool {
    if ($path === '/vendor' || $path === '') {
      return FALSE;
    }
    if ($path === '/vendor/login') {
      return FALSE;
    }
    if (preg_match('#^/vendor/\d+$#', $path)) {
      return FALSE;
    }
    if (str_starts_with($path, '/vendor/onboard')) {
      return FALSE;
    }
    if ($this->pathIsVendorSsoCallback($path)) {
      return FALSE;
    }
    return str_starts_with($path, '/vendor');
  }

  private function pathIsVendorSsoCallback(string $path): bool {
    $configured = trim((string) $this->configFactory->get('myeventlane_auth.settings')->get('vendor_sso_callback_path'));
    if ($configured === '') {
      $configured = '/vendor/sso/callback';
    }
    if (!str_starts_with($configured, '/')) {
      $configured = '/' . $configured;
    }
    return str_starts_with($path, $configured);
  }

}
