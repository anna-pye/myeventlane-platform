<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for customer onboarding step 2: Understanding RSVPs & Tickets.
 */
final class CustomerOnboardExploreController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Step 2: Understanding RSVPs & Tickets.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function explore(): array|RedirectResponse {
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
        'class' => ['mel-onboard-explore'],
      ],
    ];

    $content['intro'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-explore-intro'],
      ],
      'text' => [
        '#markup' => '<p>' . $this->t('MyEventLane offers two ways to attend events: RSVPs for free events and tickets for paid events.') . '</p>',
      ],
    ];

    $content['rsvp'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-explore-card', 'mel-onboard-explore-rsvp'],
      ],
      'icon' => [
        '#markup' => '<div class="mel-onboard-explore-icon">📅</div>',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('RSVP Events'),
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Free events that require registration. Simply RSVP to reserve your spot.') . '</p>',
      ],
      'features' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Free to attend'),
          $this->t('Quick registration'),
          $this->t('Receive event reminders'),
          $this->t('Add to your calendar'),
        ],
      ],
    ];

    $content['tickets'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-explore-card', 'mel-onboard-explore-tickets'],
      ],
      'icon' => [
        '#markup' => '<div class="mel-onboard-explore-icon">🎫</div>',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Paid Events'),
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Events with tickets available for purchase. Secure checkout with credit or debit card.') . '</p>',
      ],
      'features' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Secure payment processing'),
          $this->t('Instant ticket delivery'),
          $this->t('Digital tickets on your phone'),
          $this->t('Easy refunds (if available)'),
        ],
      ],
    ];

    $content['continue'] = [
      '#type' => 'link',
      '#title' => $this->t('Continue'),
      '#url' => Url::fromRoute('myeventlane_core.onboard.first_action'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
      ],
    ];

    return [
      '#theme' => 'customer_onboard_step',
      '#step_number' => 2,
      '#total_steps' => 4,
      '#step_title' => $this->t('How MyEventLane works'),
      '#step_description' => $this->t('Learn about RSVPs and tickets, then find your first event.'),
      '#content' => $content,
      '#attached' => [
        'library' => ['myeventlane_core/onboarding'],
      ],
    ];
  }

}
