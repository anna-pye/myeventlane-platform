<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_auth\Service\PostLoginRouter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Post-login router entry: redirects using PostLoginRouter only (no branching here).
 */
final class MelPostLoginController extends ControllerBase {

  public function __construct(
    private readonly PostLoginRouter $postLoginRouter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_auth.post_login_router'),
    );
  }

  /**
   * Redirects to the resolved internal destination.
   */
  public function handle(Request $request): RedirectResponse {
    $destination = $this->postLoginRouter->resolveDestination($this->currentUser()->getAccount(), $request);
    return new RedirectResponse($destination->toString());
  }

}
