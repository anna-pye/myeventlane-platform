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
   *   Verify MEL_QR_SECRET / settings QR signing secret is present.
   *
   * @return int
   *   0 when configured, 1 when missing.
   */
  public function qrSecretStatus(): int {
    $source = $this->ticketQrPayload->resolveSecretSource();
    $this->io()->title('QR signing secret');

    if ($source === NULL) {
      $this->io()->table(
        ['Field', 'Value'],
        [
          ['Status', 'FAIL'],
          ['Source', 'MEL_QR_SECRET missing'],
          ['Requires signing', $this->ticketQrPayload->requiresSigningSecret() ? 'yes' : 'no (code_only)'],
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
        ['Requires signing', $this->ticketQrPayload->requiresSigningSecret() ? 'yes' : 'no (code_only)'],
      ],
    );
    $this->io()->success('QR signing secret is configured.');
    return 0;
  }

}
