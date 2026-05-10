<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\VendorFollowService;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Builds reusable public vendor card component data.
 */
final class VendorCardBuilder {

  /**
   * Constructs a VendorCardBuilder object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly VendorFollowService $vendorFollowService,
  ) {}

  /**
   * Builds the shared vendor card component variables.
   *
   * @return array<string, mixed>
   *   Component variables for components/vendor-card.html.twig.
   */
  public function build(Vendor $vendor, bool $include_follow_control = FALSE): array {
    $build = [
      'url' => Url::fromRoute('entity.myeventlane_vendor.canonical', [
        'myeventlane_vendor' => $vendor->id(),
      ])->toString(),
      'name' => $vendor->label(),
      'logo' => $this->buildStyledImage($vendor, ['field_logo_image', 'field_vendor_logo'], 'thumbnail'),
      'tagline' => $this->fieldText($vendor, ['field_tagline', 'field_summary']),
      'event_count' => $this->countUpcomingEvents($vendor),
      'category' => NULL,
    ];

    if ($include_follow_control) {
      $build['vendor_follow'] = $this->buildVendorFollowVariables($vendor);
    }

    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildVendorFollowVariables(Vendor $vendor): array {
    $vid = (int) $vendor->id();
    $account = $this->currentUser;
    $uid = (int) $account->id();

    $destination = Url::fromRoute('entity.myeventlane_vendor.canonical', [
      'myeventlane_vendor' => $vid,
    ])->toString();

    $login_url = '';
    if ($uid <= 0) {
      try {
        $login_url = Url::fromRoute('user.login', [], [
          'query' => ['destination' => $destination],
        ])->toString();
      }
      catch (\Throwable) {
        $login_url = '/user/login?destination=' . rawurlencode($destination);
      }
    }

    return [
      'show' => TRUE,
      'vendor_id' => $vid,
      'is_authenticated' => $uid > 0,
      'is_following' => $uid > 0 && $this->vendorFollowService->isFollowing($account, $vendor),
      'follow_toggle_url' => $uid > 0
        ? Url::fromRoute('myeventlane_core.vendor_follow_toggle', [
          'myeventlane_vendor' => $vid,
        ])->toString()
        : '',
      'follow_login_url' => $login_url,
      'follower_count' => $this->vendorFollowService->countFollowers($vendor),
    ];
  }

  /**
   * Builds an image field render array using a Drupal image style.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The source entity.
   * @param string[] $field_names
   *   Candidate image field names in preference order.
   * @param string $image_style
   *   The image style machine name.
   *
   * @return array|null
   *   A render array, or NULL when no image exists.
   */
  public function buildStyledImage(EntityInterface $entity, array $field_names, string $image_style): ?array {
    foreach ($field_names as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        return $entity->get($field_name)->view([
          'type' => 'image',
          'label' => 'hidden',
          'settings' => [
            'image_style' => $image_style,
            'image_link' => '',
          ],
        ]);
      }
    }

    return NULL;
  }

  /**
   * Reads the first non-empty plain text value from candidate fields.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The source entity.
   * @param string[] $field_names
   *   Candidate field names in preference order.
   */
  public function fieldText(EntityInterface $entity, array $field_names): string {
    foreach ($field_names as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $value = $entity->get($field_name)->value ?? '';
        $text = trim(strip_tags((string) $value));
        if ($text !== '') {
          return $text;
        }
      }
    }

    return '';
  }

  /**
   * Counts published upcoming events for a vendor.
   */
  public function countUpcomingEvents(Vendor $vendor): int {
    try {
      $query = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_event_vendor', (int) $vendor->id());
      UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, (int) $this->time->getRequestTime());
      return (int) $query
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Unable to count upcoming events for vendor @vendor_id: @message', [
        '@vendor_id' => $vendor->id(),
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
