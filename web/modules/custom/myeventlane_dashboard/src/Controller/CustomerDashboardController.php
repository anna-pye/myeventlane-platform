<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_account\Service\CustomerHubDataBuilder;
use Drupal\myeventlane_core\Service\DisplayNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the customer dashboard (My Events).
 */
final class CustomerDashboardController extends ControllerBase {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly DisplayNameResolver $displayNameResolver,
    private readonly CustomerHubDataBuilder $customerHubDataBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time'),
      $container->get('myeventlane_core.display_name_resolver'),
      $container->get('myeventlane_account.customer_hub_data_builder'),
    );
  }

  /**
   * Renders the customer "My Events" page.
   *
   * @return array
   *   A render array for the customer dashboard.
   */
  public function myEvents(): array {
    $currentUser = $this->currentUser();
    $userEmail = $currentUser->getEmail();
    $userId = (int) $currentUser->id();

    if ($userId <= 0 && $userEmail === '') {
      return [
        '#theme' => 'myeventlane_customer_dashboard',
        '#upcoming_events' => [],
        '#past_events' => [],
        '#welcome_message' => $this->t('Please log in or provide your email to view your events.'),
        '#cache' => [
          'contexts' => ['user'],
        ],
        '#attached' => [
          'library' => ['myeventlane_theme/global-styling'],
        ],
      ];
    }

    $now = (int) $this->time->getRequestTime();
    $includeRsvpSubmissions = $userId > 0;
    $participation = $this->customerHubDataBuilder->buildParticipationLists($userId, (string) $userEmail, $now, $includeRsvpSubmissions);

    $cacheTags = ['node_list', 'user:' . $userId];
    foreach (array_merge($participation['unified_upcoming'], $participation['unified_past']) as $event) {
      $cacheTags[] = 'node:' . $event['id'];
    }

    return [
      '#theme' => 'myeventlane_customer_dashboard',
      '#upcoming_events' => $participation['unified_upcoming'],
      '#past_events' => $participation['unified_past'],
      '#welcome_message' => $this->t('Welcome, @name! Here are your events.', [
        '@name' => $this->displayNameResolver->getDisplayName($currentUser),
      ]),
      '#attached' => [
        'library' => ['myeventlane_theme/global-styling'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $cacheTags,
        'max-age' => 300,
      ],
    ];
  }

}
