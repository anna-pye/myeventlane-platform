<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Converts Pro-gated access denials into a Pro upgrade experience.
 *
 * Scope: ONLY routes carrying the `_myeventlane_pro_access` requirement, and
 * only for an authenticated user who is not Pro-active. Pro members denied for
 * another reason (e.g. ownership) and the global 403 page are left untouched.
 */
final class ProUpgradeRedirectSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProActiveResolver $resolver,
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Runs before the default HTML 403 renderer; stops propagation when acting.
    return [KernelEvents::EXCEPTION => ['onException', 100]];
  }

  /**
   * Redirects Pro-gated denials to the upgrade page.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!$exception instanceof AccessDeniedHttpException) {
      return;
    }

    $request = $event->getRequest();
    $route = $request->attributes->get('_route_object');
    if (!$route instanceof Route || !$route->hasRequirement('_myeventlane_pro_access')) {
      return;
    }

    // Admin/staff routes should keep Drupal's normal admin denial experience,
    // not the vendor Pro upgrade funnel.
    if ($this->isAdminRoute($route) || $this->currentUserHasAdminRoutePermission($route)) {
      return;
    }

    // Anonymous users follow the normal denial flow (e.g. redirect to login).
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      return;
    }

    // If the user IS Pro-active, the Pro gate is not the blocker — leave the
    // normal 403 in place (e.g. they were denied by ownership/permission).
    if ($this->resolver->isUserProActive($user)) {
      return;
    }

    $this->messenger->addWarning(
      $this->t('That’s a MyEventLane Pro feature. Upgrade to unlock it for your events.')
    );

    $url = Url::fromRoute('myeventlane_pro.overview', [], [
      'query' => ['return_to' => $request->getRequestUri()],
    ])->toString();

    $event->setResponse(new RedirectResponse($url));
    $event->stopPropagation();
  }

  private function isAdminRoute(Route $route): bool {
    if ((bool) $route->getOption('_admin_route')) {
      return TRUE;
    }

    return str_starts_with($route->getPath(), '/admin/');
  }

  private function currentUserHasAdminRoutePermission(Route $route): bool {
    $requirement = $route->getRequirement('_permission');
    if (!is_string($requirement) || $requirement === '') {
      return FALSE;
    }

    $permissions = preg_split('/[,+]/', $requirement) ?: [];
    foreach ($permissions as $permission) {
      $permission = trim($permission);
      if ($this->isAdminPermission($permission) && $this->currentUser->hasPermission($permission)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function isAdminPermission(string $permission): bool {
    return str_starts_with($permission, 'administer ')
      || str_starts_with($permission, 'access myeventlane admin ');
  }

}
