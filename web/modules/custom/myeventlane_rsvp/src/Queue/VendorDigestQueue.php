<?php

namespace Drupal\myeventlane_rsvp\Queue;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_rsvp\Service\VendorDigestGenerator;

final class VendorDigestQueue extends QueueWorkerBase {

  public function __construct(
    private readonly VendorDigestGenerator $generator
  ) {}

  public function processItem($data) {
    $vendor_uid = (int) $data['vendor_uid'];
    $this->generator->sendDigest($vendor_uid);
  }
}