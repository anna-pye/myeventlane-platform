<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 5: Completion and dashboard intro.
 */
final class VendorOnboardCompleteController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Step 5: Onboarding complete - dashboard introduction.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function complete(): array|RedirectResponse {
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

    $vendorName = $vendor->getName() ?: $this->t('your organisation');

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-complete'],
      ],
    ];

    $content['success'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-complete-success'],
      ],
      'icon' => [
        '#markup' => '<div class="mel-onboard-complete-icon">✓</div>',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Welcome to MyEventLane!'),
      ],
      'message' => [
        '#markup' => '<p>' . $this->t('Congratulations, @name! Your organiser account is set up and ready to go.', [
          '@name' => $vendorName,
        ]) . '</p>',
      ],
    ];

    $content['features'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-complete-features'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('What you can do now:'),
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Create and manage events'),
          $this->t('Set up tickets and pricing'),
          $this->t('Track registrations and sales'),
          $this->t('View analytics and reports'),
          $this->t('Manage your organiser profile'),
        ],
      ],
    ];

    $content['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-complete-actions'],
      ],
      'dashboard' => [
        '#type' => 'link',
        '#title' => $this->t('Go to dashboard'),
        '#url' => Url::fromRoute('myeventlane_dashboard.vendor'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ],
      'create_event' => [
        '#type' => 'link',
        '#title' => $this->t('Create an event'),
        '#url' => Url::fromRoute('myeventlane_vendor.create_event_gateway'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary'],
        ],
      ],
      'profile' => [
        '#type' => 'link',
        '#title' => $this->t('Edit profile'),
        '#url' => Url::fromRoute('entity.myeventlane_vendor.edit_form', [
          'myeventlane_vendor' => $vendor->id(),
        ]),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-ghost'],
        ],
      ],
    ];

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 5,
      '#total_steps' => 5,
      '#step_title' => $this->t('You\'re all set!'),
      '#step_description' => $this->t('Your organiser account is ready. Start creating events and managing your business.'),
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

















