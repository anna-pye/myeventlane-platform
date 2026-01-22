<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor venue management.
 */
class VendorVenueController extends ControllerBase {

  /**
   * Constructs a VendorVenueController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected AccountProxyInterface $current_user,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Gets the current user's vendor.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  protected function getCurrentVendor(): ?Vendor {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    // First, try to find vendor where user is the owner.
    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($owner_ids)) {
      $vendor = $storage->load(reset($owner_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    // If not found, check vendors where user is in field_vendor_users.
    $user_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($user_ids)) {
      $vendor = $storage->load(reset($user_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

  /**
   * Lists venues for the current vendor.
   *
   * @return array
   *   A render array.
   */
  public function list(): array {
    $vendor = $this->getCurrentVendor();

    if (!$vendor) {
      return [
        '#markup' => $this->t('You must be associated with a vendor to manage venues.'),
      ];
    }

    $venue_storage = $this->entityTypeManager->getStorage('myeventlane_venue');
    $venue_ids = $venue_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor', $vendor->id())
      ->sort('name', 'ASC')
      ->execute();

    $venues = [];
    if (!empty($venue_ids)) {
      $venues = $venue_storage->loadMultiple($venue_ids);
    }

    $build = [
      '#theme' => 'myeventlane_venue_list',
      '#venues' => $venues,
      '#vendor' => $vendor,
      '#cache' => [
        'tags' => ['myeventlane_venue_list'],
        'contexts' => ['user'],
      ],
    ];

    return $build;
  }

}
