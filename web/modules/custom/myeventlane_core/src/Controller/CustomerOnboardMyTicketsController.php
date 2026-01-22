<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for customer onboarding step 4: My Tickets introduction.
 */
final class CustomerOnboardMyTicketsController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Step 4: My Tickets introduction.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function myTickets(): array|RedirectResponse {
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
        'class' => ['mel-onboard-my-tickets'],
      ],
    ];

    $content['intro'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-my-tickets-intro'],
      ],
      'icon' => [
        '#markup' => '<div class="mel-onboard-my-tickets-icon">🎫</div>',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Welcome to "My Events"!'),
      ],
      'text' => [
        '#markup' => '<p>' . $this->t('This is where you\'ll find all your RSVPs and purchased tickets in one place.') . '</p>',
      ],
    ];

    $content['features'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-my-tickets-features'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('What you can do here:'),
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('View all your upcoming and past events'),
          $this->t('Download .ics calendar files'),
          $this->t('View ticket codes and details'),
          $this->t('Access event information anytime'),
        ],
      ],
    ];

    $content['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-my-tickets-actions'],
      ],
      'dashboard' => [
        '#type' => 'link',
        '#title' => $this->t('Go to My Events'),
        '#url' => Url::fromRoute('myeventlane_dashboard.customer'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ],
      'browse' => [
        '#type' => 'link',
        '#title' => $this->t('Browse events'),
        '#url' => Url::fromRoute('view.upcoming_events.page_all'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary'],
        ],
      ],
    ];

    return [
      '#theme' => 'customer_onboard_step',
      '#step_number' => 4,
      '#total_steps' => 4,
      '#step_title' => $this->t('You\'re all set!'),
      '#step_description' => $this->t('Your account is ready. Start exploring events and managing your tickets.'),
      '#content' => $content,
      '#attached' => [
        'library' => ['myeventlane_core/onboarding'],
      ],
    ];
  }

}
