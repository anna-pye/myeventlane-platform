<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Commands;

use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Drush\Commands\DrushCommands;

/**
 * Ticket operational Drush commands.
 */
final class TicketsCommands extends DrushCommands {

  public function __construct(
    private readonly TicketQrPayload $ticketQrPayload,
  ) {
    parent::__construct();
  }

  /**
   * Reports QR signing secret configuration status (never prints the secret).
   *
   * @command mel:qr-secret-status
   * @aliases mel-qr-secret-status
   * @usage ddev drush mel:qr-secret-status
   *   Verify MEL_QR_SECRET / settings QR signing secret is present when required.
   *
   * @return int
   *   0 when configuration is healthy for the active qr_payload_mode, 1 otherwise.
   */
  public function qrSecretStatus(): int {
    $source = $this->ticketQrPayload->resolveSecretSource();
    $requiresSigning = $this->ticketQrPayload->requiresSigningSecret();
    $healthy = $this->ticketQrPayload->isSigningConfigurationHealthy();
    $this->io()->title('QR signing secret');

    if (!$requiresSigning) {
      $this->io()->table(
        ['Field', 'Value'],
        [
          ['Status', 'PASS'],
          ['Source', $source ?? 'not required (qr_payload_mode=code_only)'],
          ['Requires signing', 'no (code_only)'],
        ],
      );
      $this->io()->success('QR signing secret is not required for qr_payload_mode=code_only.');
      return 0;
    }

    if (!$healthy) {
      $this->io()->table(
        ['Field', 'Value'],
        [
          ['Status', 'FAIL'],
          ['Source', 'MEL_QR_SECRET missing'],
          ['Requires signing', 'yes'],
        ],
      );
      $this->io()->error('Set MEL_QR_SECRET on the host or $settings[\'myeventlane_qr_secret\'] in settings.php. Never store the secret in config/sync.');
      return 1;
    }

    $this->io()->table(
      ['Field', 'Value'],
      [
        ['Status', 'PASS'],
        ['Source', $source],
        ['Requires signing', 'yes'],
      ],
    );
    $this->io()->success('QR signing secret is configured.');
    return 0;
  }

}
