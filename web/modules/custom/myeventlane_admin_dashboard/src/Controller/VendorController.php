<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Vendors tab – vendor management overview.
 */
final class VendorController extends ControllerBase {

  public function __construct(
    private readonly MelAdminShellBuilder $adminShellBuilder,
    private readonly EntityTypeManagerInterface $melEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_core.mel_admin_shell_builder'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns the Vendors overview page.
   */
  public function overview(Request $request): array {
    $vendorUrl = Url::fromRoute('entity.myeventlane_vendor.collection')->toString();
    $storage = $this->melEntityTypeManager->getStorage('myeventlane_vendor');
    $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 100);
    $status = (string) $request->query->get('status', 'all');
    if (!in_array($status, ['all', 'active', 'blocked'], TRUE)) {
      $status = 'all';
    }
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('created', 'DESC');

    if ($search !== '') {
      $userStorage = $this->melEntityTypeManager->getStorage('user');
      $userQuery = $userStorage->getQuery()->accessCheck(FALSE);
      $userQuery->condition(
        $userQuery->orConditionGroup()
          ->condition('name', $search, 'CONTAINS')
          ->condition('mail', $search, 'CONTAINS'),
      );
      $ownerIds = array_values($userQuery->execute());
      $searchGroup = $query->orConditionGroup()
        ->condition('name', $search, 'CONTAINS')
        ->condition('field_business_name', $search, 'CONTAINS');
      if ($ownerIds !== []) {
        $searchGroup->condition('uid', $ownerIds, 'IN');
      }
      $query->condition($searchGroup);
    }
    if ($status !== 'all') {
      $accountIds = $this->melEntityTypeManager->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', $status === 'active' ? 1 : 0)
        ->execute();
      $query->condition('uid', $accountIds !== [] ? array_values($accountIds) : [-1], 'IN');
    }

    $matchCount = (int) (clone $query)->count()->execute();
    $query->pager(25);
    $ids = $query->execute();
    $vendors = [];

    foreach ($storage->loadMultiple($ids) as $vendor) {
      $owner = $vendor->getOwner();
      $store = $vendor->hasField('field_vendor_store')
        ? $vendor->get('field_vendor_store')->entity
        : NULL;
      $businessName = $vendor->hasField('field_business_name')
        ? trim((string) $vendor->get('field_business_name')->value)
        : '';
      $termsVersion = $vendor->hasField('field_vendor_terms_version')
        ? trim((string) $vendor->get('field_vendor_terms_version')->value)
        : '';
      $termsTimestamp = $vendor->hasField('field_vendor_terms_accepted_at')
        ? (int) $vendor->get('field_vendor_terms_accepted_at')->value
        : 0;

      $vendors[] = [
        'name' => $businessName !== '' ? $businessName : $vendor->label(),
        'owner' => $owner?->getDisplayName() ?? $this->t('No owner'),
        'email' => $owner?->getEmail() ?? '',
        'account_status' => $owner?->isActive() ? $this->t('Active') : $this->t('Blocked'),
        'account_status_key' => $owner?->isActive() ? 'active' : 'blocked',
        'store' => $store?->label() ?? $this->t('Not connected'),
        'terms_version' => $termsVersion !== '' ? $termsVersion : $this->t('Not recorded'),
        'terms_accepted_at' => $termsTimestamp > 0
          ? $this->dateFormatter->format($termsTimestamp, 'short')
          : $this->t('Not recorded'),
        'edit_url' => $vendor->toUrl('edit-form')->toString(),
      ];
    }

    $main = [
      '#theme' => 'platform_control_centre_vendors',
      '#vendor_url' => $vendorUrl,
      '#vendors' => $vendors,
      '#total' => (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute(),
      '#match_count' => $matchCount,
      '#filters' => [
        'search' => $search,
        'status' => $status,
        'action_url' => Url::fromRoute('myeventlane_admin_dashboard.vendors')->toString(),
        'clear_url' => Url::fromRoute('myeventlane_admin_dashboard.vendors')->toString(),
      ],
      '#pager' => [
        '#type' => 'pager',
      ],
      '#attached' => [
        'library' => ['myeventlane_admin_dashboard/platform_control_centre'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args.pagers'],
        'tags' => ['myeventlane_vendor_list', 'user_list', 'commerce_store_list'],
        'max-age' => 0,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Vendors'),
      $this->t('Vendor accounts, stores, and Stripe Connect.'),
    );
  }

}
