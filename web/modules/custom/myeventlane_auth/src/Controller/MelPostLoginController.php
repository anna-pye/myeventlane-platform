<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_auth\Service\IdentityIntentResolver;
use Drupal\myeventlane_auth\Service\PostAuthRedirectResolver;
use Drupal\myeventlane_auth\Service\PostLoginHubBuilder;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Post-login hub: personalised next-step surface after auth handoff.
 */
final class MelPostLoginController extends ControllerBase {

  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly PostAuthRedirectResolver $postAuthRedirectResolver,
    private readonly IdentityIntentResolver $identityIntentResolver,
    private readonly PostLoginHubBuilder $postLoginHubBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('myeventlane_auth.post_auth_redirect_resolver'),
      $container->get('myeventlane_auth.identity_intent_resolver'),
      $container->get('myeventlane_auth.post_login_hub_builder'),
    );
  }

  /**
   * Hub page or redirect when intent is create_event.
   *
   * Security: tokens for /user/reset are validated by core before login; this
   * route only runs after the session is authenticated.
   */
  public function content(Request $request): array|RedirectResponse {
    if ($this->account->isAnonymous()) {
      $destination = $request->getRequestUri();
      return new RedirectResponse(Url::fromRoute('user.login', [], [
        'absolute' => TRUE,
        'query' => ['destination' => $destination],
      ])->toString());
    }

    $intent = $this->identityIntentResolver->resolveFromRequest($request);

    if ($intent === IdentityIntentResolver::INTENT_CREATE_EVENT) {
      $target = $this->postAuthRedirectResolver->resolveRedirectUrl(
        $this->account->getAccount(),
        '/create-event',
        IdentityIntentResolver::INTENT_CREATE_EVENT,
      );
      return new RedirectResponse($target);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load((int) $this->account->id());
    if (!$user instanceof UserInterface) {
      $this->getLogger('myeventlane_auth')->error('Post-login hub: user @uid missing.', [
        '@uid' => (string) $this->account->id(),
      ]);
      return [
        '#markup' => '',
        '#cache' => ['max-age' => 0],
      ];
    }

    $hub = $this->postLoginHubBuilder->build($user, $request);

    return [
      '#theme' => 'mel_post_login_hub',
      '#hub' => $hub,
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user', 'session'],
      ],
    ];
  }

}
