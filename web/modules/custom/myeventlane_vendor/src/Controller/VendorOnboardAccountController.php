<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 1: Account creation.
 */
final class VendorOnboardAccountController extends ControllerBase {

  /**
   * Step 1: Account creation or verification.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function account(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    // If already logged in, proceed to next step.
    if (!$currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.profile')->toString()
      );
    }

    // Show login/registration page with vendor context.
    $loginUrl = Url::fromRoute('user.login', [], [
      'query' => [
        'destination' => '/vendor/onboard/profile',
        'vendor' => '1',
      ],
    ]);

    $registerUrl = Url::fromRoute('user.register', [], [
      'query' => [
        'destination' => '/vendor/onboard/profile',
        'vendor' => '1',
      ],
    ]);

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 1,
      '#total_steps' => 5,
      '#step_title' => $this->t('Create your organiser account'),
      '#step_description' => $this->t('To create and manage events, you need an organiser account. Sign in if you already have one, or create a new account below.'),
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
        'library' => ['myeventlane_vendor/onboarding'],
      ],
    ];
  }

}

















