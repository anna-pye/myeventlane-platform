<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 4: Create first event.
 */
final class VendorOnboardFirstEventController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Step 4: Create first event (guided).
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function firstEvent(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.profile')->toString()
      );
    }

    // Check if user already has events.
    $eventStorage = $this->entityTypeManager()->getStorage('node');
    $eventIds = $eventStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('uid', $currentUser->id())
      ->range(0, 1)
      ->execute();

    $hasEvents = !empty($eventIds);

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-first-event'],
      ],
    ];

    if ($hasEvents) {
      $content['status'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-alert', 'mel-alert-success'],
        ],
        'message' => [
          '#markup' => '<p><strong>' . $this->t('You already have events!') . '</strong> ' . $this->t('Great work. You can continue to create more events or proceed to your dashboard.') . '</p>',
        ],
      ];

      $content['create_more'] = [
        '#type' => 'link',
        '#title' => $this->t('Create another event'),
        '#url' => Url::fromRoute('myeventlane_vendor.create_event_gateway'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ];

      $content['continue'] = [
        '#type' => 'link',
        '#title' => $this->t('Continue to dashboard'),
        '#url' => Url::fromRoute('myeventlane_vendor.onboard.complete'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary'],
        ],
      ];
    }
    else {
      $content['intro'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-first-event-intro'],
        ],
        'text' => [
          '#markup' => '<p>' . $this->t('Now let\'s create your first event! This is where you\'ll set up all the details: name, date, location, tickets, and more.') . '</p>',
        ],
      ];

      $content['tips'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-first-event-tips'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Quick tips:'),
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('You can save as a draft and come back later'),
            $this->t('Add ticket types and pricing when you\'re ready'),
            $this->t('Preview your event page before publishing'),
          ],
        ],
      ];

      $content['create'] = [
        '#type' => 'link',
        '#title' => $this->t('Create your first event'),
        '#url' => Url::fromRoute('myeventlane_vendor.create_event_gateway'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ];

      $content['skip'] = [
        '#type' => 'link',
        '#title' => $this->t('Skip for now'),
        '#url' => Url::fromRoute('myeventlane_vendor.onboard.complete'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary'],
        ],
      ];

      $content['skip_note'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-first-event-skip-note'],
        ],
        'text' => [
          '#markup' => '<p class="mel-text-muted">' . $this->t('You can create events anytime from your dashboard.') . '</p>',
        ],
      ];
    }

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 4,
      '#total_steps' => 5,
      '#step_title' => $this->t('Create your first event'),
      '#step_description' => $this->t('Let\'s get your first event set up. You can always come back to edit it later.'),
      '#content' => $content,
      '#attached' => [
        'library' => ['myeventlane_vendor/onboarding'],
      ],
    ];
  }

  /**
   * Gets the vendor entity for the current user.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  private function getCurrentUserVendor(): ?Vendor {
    $currentUser = $this->currentUser();
    $userId = (int) $currentUser->id();

    if ($userId === 0) {
      return NULL;
    }

    $vendorStorage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (!empty($vendorIds)) {
      $vendor = $vendorStorage->load(reset($vendorIds));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

}

















