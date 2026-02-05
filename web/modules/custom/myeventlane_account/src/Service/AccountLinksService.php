<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler')
    );
  }

  /**
   * Builds account navigation links.
   *
   * @param string $active
   *   Route key for the active link: 'dashboard', 'my_tickets', 'my_events',
   *   'past_events'.
   *
   * @return array<int, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, url: string, active: bool}>
   *   Array of nav links with title, url, and active flag.
   */
  public function buildLinks(string $active = 'dashboard'): array {
    $supportUrl = $this->moduleHandler->moduleExists('mel_support')
      ? Url::fromRoute('mel_support.customer_tickets')->toString()
      : '/support';

    return [
      [
        'title' => $this->t('Dashboard'),
        'url' => Url::fromRoute('myeventlane_account.dashboard')->toString(),
        'active' => $active === 'dashboard',
      ],
      [
        'title' => $this->t('My Tickets'),
        'url' => Url::fromRoute('myeventlane_checkout_flow.my_tickets')->toString(),
        'active' => $active === 'my_tickets',
      ],
      [
        'title' => $this->t('My Events'),
        'url' => Url::fromRoute('myeventlane_dashboard.customer')->toString(),
        'active' => $active === 'my_events',
      ],
      [
        'title' => $this->t('Past Events'),
        'url' => Url::fromRoute('myeventlane_account.past_events')->toString(),
        'active' => $active === 'past_events',
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
