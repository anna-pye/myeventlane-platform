<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for vendor waitlist management.
 */
final class WaitlistManagementController extends ControllerBase {

  /**
   * Constructs WaitlistManagementController.
   */
  public function __construct(
    private readonly AttendanceWaitlistManager $waitlistManager,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.waitlist'),
      // MelAttendeeOperationsAccess lives in checkout_flow (depends on this
      // module); ownership uses the same canonical checker Mel delegates to.
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Lists waitlisted attendees for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   A render array.
   */
  public function list(NodeInterface $node): array {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $eventId = (int) $node->id();
    $waitlist = $this->waitlistManager->getWaitlist($eventId);
    $analytics = $this->waitlistManager->getWaitlistAnalytics($eventId);
    $waitlistCount = count($waitlist);

    // Build waitlist data for template.
    $waitlistData = [];
    $position = 1;
    foreach ($waitlist as $attendee) {
      $created = $attendee->get('created')->value;
      $waitlistData[] = [
        'id' => $attendee->id(),
        'name' => $attendee->getName(),
        'email' => $attendee->getEmail(),
        'position' => $position++,
        'date_added' => $created ? date('M j, Y g:ia', (int) $created) : '',
        'status' => $attendee->getStatus(),
        'promoted_at' => $attendee->get('promoted_at')->isEmpty()
          ? NULL
          : date('M j, Y g:ia', (int) $attendee->get('promoted_at')->value),
      ];
    }

    return [
      '#theme' => 'waitlist_management',
      '#event' => $node,
      '#waitlist' => $waitlistData,
      '#analytics' => $analytics,
      '#waitlist_count' => $waitlistCount,
      '#attached' => [
        'library' => ['myeventlane_event_attendees/waitlist-management'],
      ],
    ];
  }

  /**
   * Exports waitlist as CSV.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   CSV response.
   */
  public function export(NodeInterface $node): Response {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $eventId = (int) $node->id();
    $waitlist = $this->waitlistManager->getWaitlist($eventId);

    // Build CSV content.
    $csv = [];
    $csv[] = ['Position', 'Name', 'Email', 'Date Added', 'Status'];

    $position = 1;
    foreach ($waitlist as $attendee) {
      $created = $attendee->get('created')->value;
      $csv[] = [
        $position++,
        $attendee->getName(),
        $attendee->getEmail(),
        $created ? date('Y-m-d H:i:s', (int) $created) : '',
        $attendee->getStatus(),
      ];
    }

    // Convert to CSV string.
    $output = fopen('php://temp', 'r+');
    foreach ($csv as $row) {
      fputcsv($output, $row);
    }
    rewind($output);
    $csvContent = stream_get_contents($output);
    fclose($output);

    $response = new Response($csvContent);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', sprintf(
      'attachment; filename="waitlist-%s-%s.csv"',
      $node->id(),
      date('Y-m-d')
    ));
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
  }

  /**
   * Gets the page title.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   The page title.
   */
  public function listTitle(NodeInterface $node): string {
    return $this->t('Waitlist: @title', ['@title' => $node->label()]);
  }

  /**
   * Access callback for waitlist management.
   *
   * Staff bypass and cache metadata preserved; organiser membership is
   * workspace parity via EventVendorAccessChecker (Mel delegate).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Access result.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResult {
    if ($node->bundle() !== 'event') {
      return AccessResult::forbidden();
    }

    // Administrators always have access.
    if ($account->hasPermission('administer nodes') || $account->id() === 1) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Canonical workspace parity: author, vendor entity owner, or team.
    if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($node);
    }

    return AccessResult::forbidden()->cachePerPermissions()->addCacheableDependency($node);
  }

}
