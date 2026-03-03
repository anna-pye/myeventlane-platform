<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for ticket check-in validation service.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TicketCheckinServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'myeventlane_tickets',
  ];

  /**
   * Event fixture A.
   */
  private Node $eventA;

  /**
   * Event fixture B.
   */
  private Node $eventB;

  /**
   * Logged-in scanner user fixture.
   */
  private User $scannerUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('myeventlane_ticket');

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();

    $this->scannerUser = User::create([
      'name' => 'scanner',
      'mail' => 'scanner@example.test',
      'status' => 1,
    ]);
    $this->scannerUser->save();
    $this->container->get('current_user')->setAccount($this->scannerUser);

    $this->eventA = Node::create([
      'type' => 'event',
      'title' => 'Event A',
      'uid' => $this->scannerUser->id(),
      'status' => 1,
    ]);
    $this->eventA->save();

    $this->eventB = Node::create([
      'type' => 'event',
      'title' => 'Event B',
      'uid' => $this->scannerUser->id(),
      'status' => 1,
    ]);
    $this->eventB->save();

    \myeventlane_tickets_update_8005();
  }

  /**
   * Valid ticket is admitted and logged.
   */
  public function testAdmitsValidTicketAndLogsAttempt(): void {
    $ticket = $this->createTicket('MEL-VALID-0001', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-1', 'online');

    $this->assertTrue($result['ok']);
    $this->assertSame('admitted', $result['result']);

    $reloaded = Ticket::load($ticket->id());
    $this->assertSame(Ticket::STATUS_CHECKED_IN, (string) $reloaded->get('status')->value);

    $log_count = (int) $this->container->get('database')->select('myeventlane_ticket_checkin_log', 'l')
      ->condition('event_nid', (int) $this->eventA->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertGreaterThan(0, $log_count);
  }

  /**
   * Wrong event payload is blocked.
   */
  public function testBlocksWrongEventPayload(): void {
    $ticket = $this->createTicket('MEL-WRONG-EVENT-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventB->id(), $payload, 'kernel-device-2', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('wrong_event', $result['result']);
  }

  /**
   * Invalid signature payload is rejected.
   */
  public function testBlocksInvalidSignaturePayload(): void {
    $ticket = $this->createTicket('MEL-BAD-SIG-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket) . 'tamper';

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-3', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('invalid', $result['result']);
  }

  /**
   * Refunded/void statuses are blocked.
   */
  public function testBlocksRefundedAndVoid(): void {
    $refunded = $this->createTicket('MEL-REFUNDED-1', (int) $this->eventA->id(), Ticket::STATUS_REFUNDED);
    $void = $this->createTicket('MEL-VOID-1', (int) $this->eventA->id(), Ticket::STATUS_VOID);

    $result_refunded = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), (string) $refunded->get('ticket_code')->value, 'kernel-device-4', 'online');
    $result_void = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), (string) $void->get('ticket_code')->value, 'kernel-device-4', 'online');

    $this->assertSame('refunded', $result_refunded['result']);
    $this->assertSame('void', $result_void['result']);
  }

  /**
   * Creates one ticket fixture.
   */
  private function createTicket(string $ticket_code, int $event_id, string $status): Ticket {
    $ticket = Ticket::create([
      'ticket_code' => $ticket_code,
      'event_id' => $event_id,
      'purchaser_uid' => $this->scannerUser->id(),
      'status' => $status,
    ]);
    $ticket->save();
    return $ticket;
  }

}

