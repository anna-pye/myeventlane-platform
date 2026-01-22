<?php

namespace Drupal\myeventlane_rsvp\Plugin\QueueWorker;

use Drupal\myeventlane_rsvp\Service\SmsManager;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
#[QueueWorker(
  id: "myeventlane_rsvp_sms_delivery",
  title: "MEL SMS Delivery",
  cron: ["time" => 60]
)]
final class SmsDeliveryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $config,
    $plugin_id,
    $plugin_definition,
    private readonly SmsManager $sms,
  ) {
    parent::__construct($config, $plugin_id, $plugin_definition);
  }

  /**
   *
   */
  public static function create(ContainerInterface $c, array $config, $plugin_id, $plugin_definition): self {
    return new self(
      $config,
      $plugin_id,
      $plugin_definition,
      $c->get('myeventlane_rsvp.sms_manager')
    );
  }

  /**
   *
   */
  public function processItem($data): void {
    $this->sms->send($data['to'], $data['msg']);
  }

}
