<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Service\AnalyticsService;
use Drupal\myeventlane_core\Service\VendorFollowService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles public vendor follow toggles.
 */
final class VendorFollowController extends ControllerBase {

  /**
   * Constructs a VendorFollowController object.
   */
  public function __construct(
    private readonly VendorFollowService $vendorFollowService,
    private readonly AnalyticsService $analytics,
    private readonly AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.vendor_follow'),
      $container->get('myeventlane_core.analytics'),
      $container->get('current_user'),
    );
  }

  /**
   * Toggles following for the current user.
   */
  public function toggle(Vendor $myeventlane_vendor): JsonResponse {
    if ((int) $this->account->id() <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Log in to follow organisers.'),
      ], 403);
    }

    $currently_following = $this->vendorFollowService->isFollowing($this->account, $myeventlane_vendor);
    if ($currently_following) {
      $this->vendorFollowService->unfollow($this->account, $myeventlane_vendor);
      $following = FALSE;
    }
    else {
      $this->vendorFollowService->follow($this->account, $myeventlane_vendor);
      $following = TRUE;
      $this->analytics->track('myeventlane_vendor', (int) $myeventlane_vendor->id(), AnalyticsService::EVENT_FOLLOW_CLICK);
    }

    return new JsonResponse([
      'status' => 'ok',
      'following' => $following,
      'label' => $following ? $this->t('Following ✓') : $this->t('Follow organiser'),
      'followers' => $this->vendorFollowService->countFollowers($myeventlane_vendor),
    ]);
  }

}
