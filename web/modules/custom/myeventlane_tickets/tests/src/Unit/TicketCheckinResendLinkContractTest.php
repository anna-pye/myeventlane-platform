<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: check-in success must advertise a CSRF-safe relative resend URL.
 *
 * @group myeventlane_tickets
 */
final class TicketCheckinResendLinkContractTest extends TestCase {

  public function testCheckinSuccessBuildsRelativeCsrfSafeResendLink(): void {
    $path = dirname(__DIR__, 3) . '/src/Form/TicketCheckinForm.php';
    $this->assertFileExists($path);
    $raw = file_get_contents($path);
    $this->assertIsString($raw);
    $this->assertStringContainsString("myeventlane_tickets.ticket_resend", $raw);
    $this->assertStringContainsString("->toString()", $raw);
    // Absolute URLs can leave the organiser host/session and break CSRF.
    $this->assertStringNotContainsString("'absolute' => TRUE", $raw);
    $this->assertStringNotContainsString('"absolute" => TRUE', $raw);
    // Prefer a clickable :url href over dumping a bare URL string.
    $this->assertStringContainsString('href=":url"', $raw);
  }

}
