<?php

namespace Drupal\myeventlane_rsvp\Commands;

use Drush\Commands\DrushCommands;
use Drupal\myeventlane_rsvp\Service\VendorDigestGenerator;

final class MelRsvpCommands extends DrushCommands {

  public function __construct(
    private readonly VendorDigestGenerator $gen
  ) {}

  /**
   * Test vendor digest email.
   *
   * @command mel-rsvp:test-digest
   * @usage mel-rsvp:test-digest 1
   */
  public function testDigest(int $uid) {
    $this->gen->sendDigest($uid);
    $this->io()->success("Digest sent.");
  }
}