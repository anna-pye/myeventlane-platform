<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor settings controller (account-wide).
 */
final class VendorSettingsController extends VendorConsoleBaseController {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity form builder.
   */
  protected EntityFormBuilderInterface $entityFormBuilder;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFormBuilderInterface $entity_form_builder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFormBuilder = $entity_form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
    );
  }

  /**
   * Gets the current vendor for the logged-in user.
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
   * Displays vendor account settings.
   *
   * Uses the Vendor entity form (\Drupal\myeventlane_vendor\Form\VendorForm),
   * form ID organiser_profile_settings.
   */
  public function settings(): array {
    $vendor = $this->getCurrentVendor();

    if (!$vendor) {
      $body = [
        '#markup' => '<div class="card"><p class="error">No vendor profile found. Please contact support.</p></div>',
        '#allowed_tags' => ['div', 'p'],
      ];

      return $this->buildVendorPage('myeventlane_vendor_console_page', [
        'title' => $this->t('Settings'),
        'body' => $body,
      ]);
    }

    return $this->entityFormBuilder->getForm($vendor, 'edit');
  }

}
