<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_auth\Service\IdentityIntentResolver;
use Drupal\myeventlane_auth\Service\PostAuthRedirectResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Intent-aware continuation endpoint: anonymous users go to login, then return.
 */
final class MelContinueController extends ControllerBase {

  public function __construct(
    private readonly PostAuthRedirectResolver $postAuthRedirectResolver,
    private readonly IdentityIntentResolver $identityIntentResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_auth.post_auth_redirect_resolver'),
      $container->get('myeventlane_auth.identity_intent_resolver'),
    );
  }

  /**
   * Continues after login or sends anonymous users to Drupal login first.
   */
  public function handoff(Request $request): RedirectResponse {
    $destination = (string) $request->query->get('destination', '/');
    $intent = $this->identityIntentResolver->resolveFromRequest($request);

    if ($this->currentUser()->isAnonymous()) {
      $query = ['destination' => Url::fromRoute('myeventlane_auth.mel_continue', [], [
        'query' => array_filter([
          'destination' => $destination,
          'mel_intent' => $intent !== IdentityIntentResolver::INTENT_BROWSE ? $intent : NULL,
        ]),
      ])->toString()];
      $login = Url::fromRoute('user.login', [], ['query' => $query]);
      return new RedirectResponse($login->toString());
    }

    $target = $this->postAuthRedirectResolver->buildPostLoginHubUrl(
      $this->currentUser()->getAccount(),
      $destination,
      $intent,
    );
    return new RedirectResponse($target);
  }

}
