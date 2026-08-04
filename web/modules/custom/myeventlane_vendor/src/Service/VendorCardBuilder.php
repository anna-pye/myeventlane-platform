<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\VendorFollowService;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds reusable public vendor card component data.
 */
final class VendorCardBuilder {

  /**
   * Canonical image style for public organiser / vendor logos.
   *
   * Config uses core image_convert_avif: AVIF when the toolkit supports it;
   * extension webp in image style config is the fallback format only.
   */
  private const LOGO_IMAGE_STYLE = 'mel_vendor_logo';

  private const DESCRIPTION_LIMIT = 120;

  private const NEW_ORGANISER_DAYS = 30;

  private const MAX_CATEGORIES = 3;

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
    $logo = $this->buildLogo($vendor);
    if ($logo !== NULL) {
      $logo['#attributes']['class'][] = 'mel-vendor-card__avatar-img';
    }

    $build = [
      'url' => Url::fromRoute('entity.myeventlane_vendor.canonical', [
        'myeventlane_vendor' => $vendor->id(),
      ])->toString(),
      'name' => $vendor->label(),
      'logo' => $logo,
      'description' => $this->buildDescription($vendor),
      'event_count' => $this->countUpcomingEvents($vendor),
      'follower_count' => $this->vendorFollowService->countFollowers($vendor),
      'categories' => $this->loadTopCategories($vendor),
      'is_new_organiser' => $this->isNewOrganiser($vendor),
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
   * Builds a truncated organiser description for directory cards.
   */
  private function buildDescription(Vendor $vendor): string {
    $text = $this->fieldText($vendor, [
      'field_description',
      'field_vendor_bio',
      'field_tagline',
      'field_summary',
    ]);

    if ($text === '') {
      return '';
    }

    return Unicode::truncate($text, self::DESCRIPTION_LIMIT, TRUE, TRUE);
  }

  /**
   * Whether the vendor account is less than 30 days old.
   */
  private function isNewOrganiser(Vendor $vendor): bool {
    if (!$vendor->hasField('created') || $vendor->get('created')->isEmpty()) {
      return FALSE;
    }

    $created = (int) $vendor->get('created')->value;
    if ($created <= 0) {
      return FALSE;
    }

    return ($this->time->getRequestTime() - $created) < (self::NEW_ORGANISER_DAYS * 86400);
  }

  /**
   * Loads up to three most common categories from published vendor events.
   *
   * @return string[]
   *   Category labels sorted by frequency.
   */
  private function loadTopCategories(Vendor $vendor): array {
    try {
      $nids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_event_vendor', (int) $vendor->id())
        ->range(0, 100)
        ->execute();

      if ($nids === []) {
        return [];
      }

      $events = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      $counts = [];

      foreach ($events as $event) {
        if (!$event->hasField('field_category') || $event->get('field_category')->isEmpty()) {
          continue;
        }

        foreach ($event->get('field_category')->referencedEntities() as $term) {
          if (!$term instanceof TermInterface) {
            continue;
          }
          $tid = (int) $term->id();
          $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        }
      }

      if ($counts === []) {
        return [];
      }

      arsort($counts);
      $topIds = array_slice(array_keys($counts), 0, self::MAX_CATEGORIES);
      $labels = [];

      foreach ($topIds as $tid) {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
        if ($term instanceof TermInterface) {
          $label = trim($term->label());
          if ($label !== '') {
            $labels[] = $label;
          }
        }
      }

      return $labels;
    }
    catch (\Exception $e) {
      $this->logger->error('Unable to load categories for vendor @vendor_id: @message', [
        '@vendor_id' => $vendor->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the organiser logo render array using the canonical logo image style.
   *
   * @return array|null
   *   A render array, or NULL when no logo exists.
   */
  public function buildLogo(EntityInterface $entity): ?array {
    return $this->buildStyledImage(
      $entity,
      VendorImageFieldPolicy::PUBLIC_LOGO_FIELDS,
      self::LOGO_IMAGE_STYLE,
    );
  }

  /**
   * Builds the absolute URL for a vendor organiser logo.
   *
   * @return string|null
   *   Styled logo URL, or NULL when no logo or image style exists.
   */
  public function buildLogoUrl(EntityInterface $entity): ?string {
    $style = $this->entityTypeManager->getStorage('image_style')->load(self::LOGO_IMAGE_STYLE);
    if ($style === NULL) {
      return NULL;
    }

    foreach (VendorImageFieldPolicy::PUBLIC_LOGO_FIELDS as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $file = $entity->get($field_name)->entity;
        if ($file !== NULL) {
          return $style->buildUrl($file->getFileUri());
        }
      }
    }

    return NULL;
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
