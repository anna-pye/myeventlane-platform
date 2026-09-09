<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Privacy-safe customer and consent views for the Admin Control Center.
 */
final class PeopleController extends ControllerBase {

  public function __construct(
    private readonly MelAdminShellBuilder $adminShellBuilder,
    private readonly EntityTypeManagerInterface $melEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.mel_admin_shell_builder'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists customer accounts without duplicating vendor identities.
   */
  public function customers(Request $request): array {
    $storage = $this->melEntityTypeManager->getStorage('user');
    $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 100);
    $status = (string) $request->query->get('status', 'all');
    $relationship = (string) $request->query->get('relationship', 'all');
    if (!in_array($status, ['all', 'active', 'blocked'], TRUE)) {
      $status = 'all';
    }
    if (!in_array($relationship, ['all', 'customer', 'vendor'], TRUE)) {
      $relationship = 'all';
    }
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 1, '>')
      ->sort('created', 'DESC');
    if ($search !== '') {
      $query->condition(
        $query->orConditionGroup()
          ->condition('name', $search, 'CONTAINS')
          ->condition('mail', $search, 'CONTAINS'),
      );
    }
    if ($status !== 'all') {
      $query->condition('status', $status === 'active' ? 1 : 0);
    }
    if ($relationship !== 'all') {
      $vendorOwnerIds = $this->melEntityTypeManager->getStorage('myeventlane_vendor')->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      $vendorOwners = [];
      foreach ($this->melEntityTypeManager->getStorage('myeventlane_vendor')->loadMultiple($vendorOwnerIds) as $vendor) {
        $vendorOwners[] = (int) $vendor->getOwnerId();
      }
      if ($relationship === 'vendor') {
        $query->condition('uid', $vendorOwners !== [] ? $vendorOwners : [-1], 'IN');
      }
      elseif ($vendorOwners !== []) {
        $query->condition('uid', $vendorOwners, 'NOT IN');
      }
    }
    $matchCount = (int) (clone $query)->count()->execute();
    $query->pager(25);
    $ids = $query->execute();

    $vendorOwners = [];
    if ($ids !== []) {
      $vendorStorage = $this->melEntityTypeManager->getStorage('myeventlane_vendor');
      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', array_values($ids), 'IN')
        ->execute();
      foreach ($vendorStorage->loadMultiple($vendorIds) as $vendor) {
        $vendorOwners[(int) $vendor->getOwnerId()] = $vendor->toUrl('edit-form')->toString();
      }
    }

    $customers = [];
    foreach ($storage->loadMultiple($ids) as $account) {
      $uid = (int) $account->id();
      $accountType = $account->hasField('field_mel_account_type')
        ? trim((string) $account->get('field_mel_account_type')->value)
        : '';
      $customers[] = [
        'name' => $account->getDisplayName(),
        'email' => $account->getEmail(),
        'status' => $account->isActive() ? $this->t('Active') : $this->t('Blocked'),
        'status_key' => $account->isActive() ? 'active' : 'blocked',
        'created' => $this->formatTimestamp((int) $account->getCreatedTime()),
        'last_access' => $this->formatTimestamp((int) $account->getLastAccessedTime()),
        'account_type' => $accountType !== '' ? ucfirst($accountType) : $this->t('Customer'),
        'vendor_url' => $vendorOwners[$uid] ?? '',
        'terms_version' => $this->fieldValueOrNotRecorded($account, 'field_customer_terms_version'),
        'terms_accepted_at' => $this->fieldTimestampOrNotRecorded($account, 'field_customer_terms_accepted_at'),
        'privacy_version' => $this->fieldValueOrNotRecorded($account, 'field_privacy_version'),
        'privacy_accepted_at' => $this->fieldTimestampOrNotRecorded($account, 'field_privacy_accepted_at'),
        'marketing' => $this->marketingPreference($account),
        'marketing_recorded' => $account->hasField('field_marketing_opt_in')
        && !$account->get('field_marketing_opt_in')->isEmpty(),
        'marketing_recorded_at' => $this->fieldTimestampOrNotRecorded($account, 'field_marketing_choice_at'),
        'edit_url' => $account->toUrl('edit-form')->toString(),
      ];
    }

    $main = [
      '#theme' => 'platform_control_centre_customers',
      '#customers' => $customers,
      '#total' => (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', 1, '>')
        ->count()
        ->execute(),
      '#match_count' => $matchCount,
      '#filters' => [
        'search' => $search,
        'status' => $status,
        'relationship' => $relationship,
        'action_url' => Url::fromRoute('myeventlane_admin_dashboard.customers')->toString(),
        'clear_url' => Url::fromRoute('myeventlane_admin_dashboard.customers')->toString(),
      ],
      '#pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => ['myeventlane_admin_dashboard/platform_control_centre'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args.pagers'],
        'tags' => ['user_list', 'myeventlane_vendor_list'],
        'max-age' => 0,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Customers'),
      $this->t('Account status and recorded registration choices. Vendor accounts remain visible here because one person can use both roles.'),
    );
  }

  /**
   * Lists immutable legal consent events without sensitive request metadata.
   */
  public function consents(Request $request): array {
    $storage = $this->melEntityTypeManager->getStorage('legal_consent_event');
    $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 100);
    $sourceFilter = (string) $request->query->get('source', 'all');
    if (!in_array($sourceFilter, ['all', 'registration', 'checkout', 'rsvp'], TRUE)) {
      $sourceFilter = 'all';
    }
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('accepted_at', 'DESC');
    if ($search !== '') {
      $query->condition('email', $search, 'CONTAINS');
    }
    if ($sourceFilter !== 'all') {
      $query->condition('source', $sourceFilter);
    }
    $matchCount = (int) (clone $query)->count()->execute();
    $query->pager(50);
    $ids = $query->execute();
    $sourceLabels = [
      'registration' => $this->t('Registration'),
      'checkout' => $this->t('Checkout'),
      'rsvp' => $this->t('RSVP'),
    ];
    $consents = [];

    foreach ($storage->loadMultiple($ids) as $consent) {
      $source = (string) $consent->get('source')->value;
      $consents[] = [
        'id' => (int) $consent->id(),
        'email' => (string) $consent->get('email')->value,
        'source' => $sourceLabels[$source] ?? ucfirst($source),
        'terms_version' => (string) $consent->get('terms_version')->value,
        'privacy_version' => (string) $consent->get('privacy_version')->value,
        'accepted_at' => $this->formatTimestamp((int) $consent->get('accepted_at')->value),
      ];
    }

    $main = [
      '#theme' => 'platform_control_centre_consents',
      '#consents' => $consents,
      '#total' => (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute(),
      '#match_count' => $matchCount,
      '#filters' => [
        'search' => $search,
        'source' => $sourceFilter,
        'action_url' => Url::fromRoute('myeventlane_admin_dashboard.consents')->toString(),
        'clear_url' => Url::fromRoute('myeventlane_admin_dashboard.consents')->toString(),
      ],
      '#legal_settings_url' => Url::fromRoute('myeventlane_legal.settings')->toString(),
      '#pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => ['myeventlane_admin_dashboard/platform_control_centre'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args.pagers'],
        'tags' => ['legal_consent_event_list'],
        'max-age' => 0,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Consent history'),
      $this->t('Restricted, immutable evidence of accepted policy versions. Network and browser metadata is deliberately hidden from this everyday view.'),
    );
  }

  /**
   * Formats a timestamp or a clear missing-state label.
   */
  private function formatTimestamp(int $timestamp): string|\Stringable {
    return $timestamp > 0
      ? $this->dateFormatter->format($timestamp, 'short')
      : $this->t('Not recorded');
  }

  /**
   * Returns a scalar field value or a clear missing-state label.
   */
  private function fieldValueOrNotRecorded(ContentEntityInterface $entity, string $fieldName): string|\Stringable {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return $this->t('Not recorded');
    }
    return (string) $entity->get($fieldName)->value;
  }

  /**
   * Returns a formatted timestamp field or a clear missing-state label.
   */
  private function fieldTimestampOrNotRecorded(ContentEntityInterface $entity, string $fieldName): string|\Stringable {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return $this->t('Not recorded');
    }
    return $this->formatTimestamp((int) $entity->get($fieldName)->value);
  }

  /**
   * Returns the explicit registration marketing choice, including opt-out.
   */
  private function marketingPreference(ContentEntityInterface $account): string|\Stringable {
    if (!$account->hasField('field_marketing_opt_in') || $account->get('field_marketing_opt_in')->isEmpty()) {
      return $this->t('Not recorded');
    }
    return (bool) $account->get('field_marketing_opt_in')->value
      ? $this->t('Opted in')
      : $this->t('Declined');
  }

}
