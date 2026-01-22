<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for customer onboarding step 3: First purchase/RSVP flow.
 */
final class CustomerOnboardFirstActionController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Step 3: First purchase/RSVP flow.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function firstAction(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    // Require authentication.
    if ($currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_core.onboard.account')->toString()
      );
    }

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-first-action'],
      ],
    ];

    $content['intro'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-first-action-intro'],
      ],
      'text' => [
        '#markup' => '<p>' . $this->t('Ready to find your first event? Browse our featured events below, or explore all events.') . '</p>',
      ],
    ];

    $content['browse'] = [
      '#type' => 'link',
      '#title' => $this->t('Browse events'),
      '#url' => Url::fromRoute('view.upcoming_events.page_all'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
      ],
    ];

    $content['skip'] = [
      '#type' => 'link',
      '#title' => $this->t('Skip for now'),
      '#url' => Url::fromRoute('myeventlane_core.onboard.my_tickets'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-secondary'],
      ],
    ];

    $content['skip_note'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-first-action-skip-note'],
      ],
      'text' => [
        '#markup' => '<p class="mel-text-muted">' . $this->t('You can browse events anytime. Let\'s show you where to find your tickets and RSVPs.') . '</p>',
      ],
    ];

    return [
      '#theme' => 'customer_onboard_step',
      '#step_number' => 3,
      '#total_steps' => 4,
      '#step_title' => $this->t('Find your first event'),
      '#step_description' => $this->t('Browse events and RSVP or purchase tickets. Your events will appear in "My Events".'),
      '#content' => $content,
      '#attached' => [
        'library' => ['myeventlane_core/onboarding'],
      ],
    ];
  }

}
