<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for customer onboarding step 1: Account creation.
 */
final class CustomerOnboardAccountController extends ControllerBase {

  /**
   * Step 1: Account creation or sign in.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function account(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    // If already logged in, proceed to next step.
    if (!$currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_core.onboard.explore')->toString()
      );
    }

    // Show login/registration page with customer context.
    $loginUrl = Url::fromRoute('user.login', [], [
      'query' => [
        'destination' => '/onboard/explore',
      ],
    ]);

    $registerUrl = Url::fromRoute('user.register', [], [
      'query' => [
        'destination' => '/onboard/explore',
      ],
    ]);

    return [
      '#theme' => 'customer_onboard_step',
      '#step_number' => 1,
      '#total_steps' => 4,
      '#step_title' => $this->t('Create your account'),
      '#step_description' => $this->t('Join MyEventLane to discover events, RSVP, and purchase tickets. Sign in if you already have an account.'),
      '#content' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-account'],
        ],
        'login_link' => [
          '#type' => 'link',
          '#title' => $this->t('Sign in'),
          '#url' => $loginUrl,
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
          ],
        ],
        'register_link' => [
          '#type' => 'link',
          '#title' => $this->t('Create account'),
          '#url' => $registerUrl,
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn-secondary', 'mel-btn-lg'],
          ],
        ],
      ],
      '#attached' => [
        'library' => ['myeventlane_core/onboarding'],
      ],
    ];
  }

}
