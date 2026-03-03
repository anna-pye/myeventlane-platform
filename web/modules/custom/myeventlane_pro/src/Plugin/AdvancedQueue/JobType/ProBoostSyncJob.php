<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\myeventlane_pro\Service\ProBoostProvisioner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs Pro boost entitlements for a single store.
 */
#[AdvancedQueueJobType(
  id: 'pro_boost_sync_job',
  label: new \Drupal\Core\StringTranslation\TranslatableMarkup('Pro boost entitlement sync'),
  max_retries: 2,
  retry_delay: 300,
)]
final class ProBoostSyncJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    mixed $plugin_id,
    mixed $plugin_definition,
    private readonly ProBoostProvisioner $proBoostProvisioner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_pro.pro_boost_provisioner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $storeId = (int) ($job->getPayload()['store_id'] ?? 0);
    if ($storeId <= 0) {
      return JobResult::failure('Missing store_id payload.');
    }

    $stats = $this->proBoostProvisioner->syncStoreById($storeId);
    return JobResult::success(sprintf(
      'Pro boost sync complete for store %d (created=%d, updated=%d, revoked=%d).',
      $storeId,
      (int) ($stats['created'] ?? 0),
      (int) ($stats['updated'] ?? 0),
      (int) ($stats['revoked'] ?? 0),
    ));
  }

}
