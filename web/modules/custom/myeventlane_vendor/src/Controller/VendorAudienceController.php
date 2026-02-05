<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\CategoryAudienceService;

/**
 * Audience management controller for vendor console.
 *
 * Displays real attendee data from all vendor events.
 */
final class VendorAudienceController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly CategoryAudienceService $categoryAudienceService,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays audience lists and filters.
   */
  public function audience(): array {
    $userId = (int) $this->currentUser->id();

    // Get real attendee data.
    $attendees = $this->categoryAudienceService->getVendorAudience($userId);
    $totalCount = count($attendees);

    // Get category breakdown.
    $segments = $this->categoryAudienceService->getCategoryBreakdown();

    // Build summary.
    $summary = [
      'total' => $totalCount,
      'event_categories' => count($segments),
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Audience',
      'header_actions' => $totalCount > 0 ? [
        [
          'label' => 'Export CSV',
          'url' => '/vendor/audience/export',
          'class' => 'mel-btn--secondary',
        ],
      ] : [],
      'body' => [
        '#theme' => 'myeventlane_vendor_audience',
        '#attendees' => $attendees,
        '#summary' => $summary,
        '#segments' => $segments,
      ],
    ]);
  }

}
