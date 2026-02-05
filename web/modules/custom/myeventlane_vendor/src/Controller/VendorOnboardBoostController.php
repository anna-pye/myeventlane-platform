<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Service\BoostHelpContent;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 5: Promote with Boost.
 */
final class VendorOnboardBoostController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly BoostHelpContent $helpContent,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_boost.help_content'),
    );
  }

  /**
   * Step 5: Promote your event with Boost.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function boost(): array|RedirectResponse {
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

    $copy = $this->helpContent->getOnboardingContent();

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-boost'],
      ],
    ];

    $content['intro'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-boost__intro'],
      ],
    ];
    foreach ($copy['intro'] as $delta => $line) {
      $content['intro']['p_' . $delta] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $line,
      ];
    }

    $content['sections'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-boost__sections'],
      ],
    ];

    foreach ($copy['sections'] as $i => $section) {
      $content['sections']['section_' . $i] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-boost__section'],
        ],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $section['heading'],
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $section['body'],
        ],
      ];
    }

    $content['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-boost__actions'],
      ],
      'open_boost' => [
        '#type' => 'link',
        '#title' => $this->t('Go to Boost'),
        '#url' => Url::fromRoute('myeventlane_vendor.console.boost'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ],
      'pdf' => [
        '#type' => 'link',
        '#title' => $this->t('Download Boost performance guide (PDF)'),
        '#url' => Url::fromRoute('myeventlane_boost.performance_guide_pdf'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary'],
        ],
      ],
      'continue' => [
        '#type' => 'link',
        '#title' => $this->t('Continue to dashboard introduction'),
        '#url' => Url::fromRoute('myeventlane_vendor.onboard.complete'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-ghost'],
        ],
      ],
    ];

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 6,
      '#total_steps' => 7,
      '#step_title' => $copy['title'],
      '#step_description' => NULL,
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
    $query = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1);
    $group = $query->orConditionGroup()
      ->condition('uid', $userId)
      ->condition('field_vendor_users', $userId);
    $vendorIds = $query->condition($group)->execute();

    if (!empty($vendorIds)) {
      $vendor = $vendorStorage->load(reset($vendorIds));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

}
