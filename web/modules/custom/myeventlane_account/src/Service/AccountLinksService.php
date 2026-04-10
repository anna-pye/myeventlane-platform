<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds account navigation links for the customer sidebar.
 */
final class AccountLinksService {

  use StringTranslationTrait;

  /**
   * Constructs AccountLinksService.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Builds account navigation links.
   *
   * @param string $active
   *   Active section: only "dashboard" is used (My Tickets / My Events / Past
   *   Events are not shown in the sidebar; deep routes still mark Dashboard).
   *
   * @return array<int, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, url: string, active: bool}>
   *   Array of nav links with title, url, and active flag.
   */
  public function buildLinks(string $active = 'dashboard'): array {
    $supportUrl = '/support/tickets';
    if ($this->moduleHandler->moduleExists('myeventlane_escalations_portal')) {
      try {
        $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_support_tickets')->toString();
      }
      catch (\Throwable) {
        try {
          $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_support')->toString();
        }
        catch (\Throwable) {
          // Keep /support/tickets fallback.
        }
      }
    }

    return [
      [
        'title' => $this->t('Dashboard'),
        'url' => Url::fromRoute('myeventlane_account.dashboard')->toString(),
        'active' => $active === 'dashboard',
      ],
      [
        'title' => $this->t('Profile & Settings'),
        'url' => Url::fromRoute('myeventlane_account.settings')->toString(),
        'active' => FALSE,
      ],
      [
        'title' => $this->t('Support'),
        'url' => $supportUrl,
        'active' => FALSE,
      ],
      [
        'title' => $this->t('Log out'),
        'url' => Url::fromRoute('user.logout')->toString(),
        'active' => FALSE,
      ],
    ];
  }

}
