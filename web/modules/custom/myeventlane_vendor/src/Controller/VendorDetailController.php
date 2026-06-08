<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\AnalyticsService;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_core\Service\VendorFollowService;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\myeventlane_event_studio\Service\EventRepository;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\VendorCardBuilder;
use Drupal\myeventlane_vendor\Service\VendorEventRemovalService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for public vendor detail pages.
 */
final class VendorDetailController extends ControllerBase {

  /**
   * Constructs a VendorDetailController object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly ?EventRepository $eventRepository,
    private readonly VendorCardBuilder $vendorCardBuilder,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly VendorFollowService $vendorFollowService,
    private readonly AnalyticsService $analytics,
    private readonly AccountInterface $account,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.entity_id_normalizer'),
      $container->has('myeventlane_event_studio.repository') ? $container->get('myeventlane_event_studio.repository') : NULL,
      $container->get('myeventlane_vendor.vendor_card_builder'),
      $container->get('myeventlane_vendor.vendor_event_removal'),
      $container->get('myeventlane_core.vendor_follow'),
      $container->get('myeventlane_core.analytics'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Displays the public vendor detail page.
   */
  public function view(Vendor $myeventlane_vendor): array {
    $this->analytics->track('myeventlane_vendor', (int) $myeventlane_vendor->id(), AnalyticsService::EVENT_VENDOR_PAGE_VIEW);

    $content = $this->buildVisibleContent($myeventlane_vendor);
    $events = $this->buildUpcomingEventCards($myeventlane_vendor);
    $is_authenticated = (int) $this->account->id() > 0;
    $csrf_token = $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    $build = [
      '#theme' => 'entity__myeventlane_vendor__full',
      '#entity' => $myeventlane_vendor,
      '#view_mode' => 'full',
      '#content' => $content,
      '#label' => $myeventlane_vendor->label(),
      '#vendor_id' => (int) $myeventlane_vendor->id(),
      '#banner' => $content['field_banner_image'] ?? NULL,
      '#logo' => $this->vendorCardBuilder->buildStyledImage($myeventlane_vendor, ['field_logo_image', 'field_vendor_logo'], 'thumbnail'),
      '#tagline' => $this->vendorCardBuilder->fieldText($myeventlane_vendor, ['field_tagline', 'field_summary']),
      '#description' => $content['field_vendor_bio'] ?? $content['field_description'] ?? NULL,
      '#contact' => $this->buildContactContent($content),
      '#events' => $events,
      '#is_authenticated' => $is_authenticated,
      '#is_following' => $is_authenticated ? $this->vendorFollowService->isFollowing($this->account, $myeventlane_vendor) : FALSE,
      '#follower_count' => $this->vendorFollowService->countFollowers($myeventlane_vendor),
      '#follow_url' => $is_authenticated ? Url::fromRoute('myeventlane_core.vendor_follow_toggle', [
        'myeventlane_vendor' => $myeventlane_vendor->id(),
      ])->toString() : Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => Url::fromRoute('entity.myeventlane_vendor.canonical', [
            'myeventlane_vendor' => $myeventlane_vendor->id(),
          ])->toString(),
        ],
      ])->toString(),
      '#attributes' => new Attribute(['class' => ['mel-vendor']]),
      '#title_attributes' => new Attribute(),
      '#attached' => [
        'library' => ['myeventlane_core/vendor_public'],
        'drupalSettings' => [
          'melVendorPublic' => [
            'csrfToken' => $csrf_token,
          ],
          'melPublicAnalytics' => [
            'eventClickUrl' => Url::fromRoute('myeventlane_core.analytics_event_click')->toString(),
            'csrfToken' => $csrf_token,
          ],
        ],
      ],
      '#cache' => [
        'tags' => array_values(array_unique(array_merge(
          $myeventlane_vendor->getCacheTags(),
          ['node_list:event', 'myeventlane_vendor_follow_list']
        ))),
        'contexts' => array_values(array_unique(array_merge(
          $myeventlane_vendor->getCacheContexts(),
          ['session', 'user', 'user.permissions']
        ))),
        'max-age' => $myeventlane_vendor->getCacheMaxAge(),
      ],
    ];

    return $build;
  }

  /**
   * Builds public fields while preserving vendor visibility preferences.
   *
   * @return array<string, array>
   *   Render arrays keyed by field name.
   */
  private function buildVisibleContent(Vendor $vendor): array {
    $view_builder = $this->entityTypeManagerService->getViewBuilder('myeventlane_vendor');
    $content = [];

    foreach (['field_vendor_logo', 'field_logo_image', 'field_vendor_bio', 'field_tagline'] as $field_name) {
      if ($vendor->hasField($field_name) && !$vendor->get($field_name)->isEmpty()) {
        $content[$field_name] = $view_builder->viewField($vendor->get($field_name), 'full');
      }
    }

    $visibility = [
      'field_email' => 'field_public_show_email',
      'field_phone' => 'field_public_show_phone',
      'field_address' => 'field_public_show_location',
      'field_website' => 'field_public_show_website',
      'field_social_links' => 'field_public_show_social_links',
      'field_summary' => 'field_public_show_summary',
      'field_description' => 'field_public_show_description',
      'field_banner_image' => 'field_public_show_banner',
    ];

    foreach ($visibility as $field_name => $visibility_field) {
      if ($this->canShowField($vendor, $field_name, $visibility_field)) {
        $content[$field_name] = $view_builder->viewField($vendor->get($field_name), 'full');
      }
    }

    return $content;
  }

  /**
   * Checks field visibility for public vendor pages.
   */
  private function canShowField(Vendor $vendor, string $field_name, string $visibility_field): bool {
    if (!$vendor->hasField($field_name) || $vendor->get($field_name)->isEmpty()) {
      return FALSE;
    }

    if (!$vendor->hasField($visibility_field)) {
      return TRUE;
    }

    return !$vendor->get($visibility_field)->isEmpty() && (bool) $vendor->get($visibility_field)->value;
  }

  /**
   * Groups optional contact fields for the template.
   *
   * @param array<string, array> $content
   *   Visible field render arrays.
   *
   * @return array<string, array>
   *   Contact render arrays.
   */
  private function buildContactContent(array $content): array {
    return array_intersect_key($content, array_flip([
      'field_email',
      'field_phone',
      'field_address',
      'field_website',
      'field_social_links',
    ]));
  }

  /**
   * Builds upcoming event card variables for this vendor.
   *
   * @return array<int, array<string, mixed>>
   *   Event card component variables.
   */
  private function buildUpcomingEventCards(Vendor $vendor): array {
    if ($this->eventRepository === NULL) {
      return [];
    }

    $query = $this->entityTypeManagerService
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', (int) $vendor->id())
      ->condition('status', 1);
    UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, (int) $this->time->getRequestTime());
    $event_ids = $query
      ->sort('field_event_start', 'ASC')
      ->range(0, 10)
      ->execute();

    if (empty($event_ids)) {
      return [];
    }

    $nids = $this->entityIdNormalizer->normalizeNodeIds(array_values($event_ids));
    $nodes = $this->entityTypeManagerService->getStorage('node')->loadMultiple($nids);
    $cards = [];

    $view_builder = $this->entityTypeManagerService->getViewBuilder('node');
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface || !$node->access('view', $this->account)) {
        continue;
      }
      $cards[] = $view_builder->view($node, 'compact_commerce');
    }

    return $cards;
  }

}
