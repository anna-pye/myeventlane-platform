<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_pro\Service\ProEntitlementReconciler;
use Drupal\myeventlane_pro\Service\ProSubscriptionCatalogEnsurer;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Pro billing / entitlement admin overrides.
 */
final class ProAdminCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProEntitlementReconciler $reconciler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProSubscriptionCatalogEnsurer $catalogEnsurer,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_pro.entitlement_reconciler'),
      $container->get('config.factory'),
      $container->get('myeventlane_pro.catalog_ensurer'),
    );
  }

  /**
   * Extend Pro grace period for a user (admin recovery).
   *
   * @param array $options
   *   Command options.
   *
   * @command mel:pro-extend-grace
   * @option uid User ID (required)
   * @option days Days to add (default from grace_days config)
   * @usage drush mel:pro-extend-grace --uid=42 --days=7
   *   Adds seven days to user 42’s grace window.
   */
  public function extendGrace(array $options = [
    'uid' => NULL,
    'days' => NULL,
  ]): void {
    $uid = isset($options['uid']) ? (int) $options['uid'] : 0;
    if ($uid <= 0) {
      $this->logger()->error('The --uid option is required.');
      return;
    }

    $days = $options['days'] !== NULL && $options['days'] !== ''
      ? (int) $options['days']
      : (int) ($this->configFactory->get('myeventlane_pro.settings')->get('grace_days') ?? 7);
    if ($days <= 0) {
      $this->logger()->error('Days must be a positive integer.');
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      $this->logger()->error('User @uid not found.', ['@uid' => (string) $uid]);
      return;
    }

    $seconds = $days * 86400;
    $ok = $this->reconciler->extendGracePeriodBySeconds($user, $seconds);
    if ($ok) {
      $this->reconciler->reconcileUser($user);
      $this->logger()->notice('Extended grace for user @uid by @days day(s).', [
        '@uid' => (string) $uid,
        '@days' => (string) $days,
      ]);
    }
    else {
      $this->logger()->warning('Grace not extended for user @uid.', ['@uid' => (string) $uid]);
    }
  }

  /**
   * Reconcile Pro entitlements for one user.
   *
   * @command mel:pro-reconcile-user
   * @option uid User ID (required)
   * @usage drush mel:pro-reconcile-user --uid=42
   */
  public function reconcileUser(array $options = ['uid' => NULL]): void {
    $uid = isset($options['uid']) ? (int) $options['uid'] : 0;
    if ($uid <= 0) {
      $this->logger()->error('The --uid option is required.');
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      $this->logger()->error('User @uid not found.', ['@uid' => (string) $uid]);
      return;
    }

    $this->reconciler->reconcileUser($user);
    $this->logger()->notice('Reconciled user @uid.', ['@uid' => (string) $uid]);
  }

  /**
   * Create the MEL Pro subscription product if none exists (staging / new env).
   *
   * @command mel:pro-ensure-catalog
   * @usage drush mel:pro-ensure-catalog
   *   Creates the default MEL Pro Commerce product and variation when missing.
   */
  public function ensureProCatalog(): void {
    if ($this->catalogEnsurer->ensureDefaultCatalog()) {
      $this->logger()->notice('MEL Pro catalog check complete. Run drush cr if you created new entities.');
    }
    else {
      $this->logger()->error('MEL Pro catalog could not be ensured. Check watchdog and Commerce config.');
    }
  }

}
