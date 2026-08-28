<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\Form\MarkAllReadForm;
use Drupal\myeventlane_notifications\Form\MarkDeliveryReadForm;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationFilter;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Drupal\myeventlane_notifications\Service\NotificationViewBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Full-page inbox for the current user.
 */
final class UserInboxController extends ControllerBase {

  public function __construct(
    private readonly NotificationUserInboxService $userInbox,
    private readonly NotificationViewBuilder $viewBuilder,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_notifications.user_inbox'),
      $container->get('myeventlane_notifications.view_builder'),
      $container->get('date.formatter'),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function page(Request $request): array {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }

    $tab = (string) $request->query->get('tab', NotificationFilter::TAB_ALL);
    if (!in_array($tab, NotificationFilter::allowedTabs(), TRUE)) {
      $tab = NotificationFilter::TAB_ALL;
    }

    $filter = (string) $request->query->get('filter', NotificationFilter::FILTER_ALL);
    if (!in_array($filter, NotificationFilter::allowedFilters(), TRUE)) {
      $filter = NotificationFilter::FILTER_ALL;
    }

    $page = max(0, (int) $request->query->get('page', 0));
    $ids = $this->userInbox->getInboxDeliveryIds($uid, $tab, $filter, $page);
    $rows = $this->viewBuilder->buildRowsForDeliveries($ids, $uid);
    // Keep each delivery visible. The former synthetic summaries hid later
    // actions and could only mark the first delivery as read.
    $groups = $this->viewBuilder->groupRowsByDate($rows);

    $groups_out = [];
    foreach ($groups as $group) {
      $ts = (int) ($group['sort_ts'] ?? 0);
      $label = $ts > 0
        ? $this->dateFormatter->format($ts, 'custom', 'l, j F Y')
        : (string) $this->t('Earlier');
      $items = [];
      foreach ($group['items'] as $row) {
        $items[] = [
          'row' => $row,
          'mark_read_form' => $this->formBuilder()->getForm(MarkDeliveryReadForm::class, (int) $row['delivery_id'], $tab, $filter),
        ];
      }
      $groups_out[] = [
        'label' => $label,
        'items' => $items,
      ];
    }

    $tab_links = [];
    foreach (NotificationFilter::allowedTabs() as $key) {
      $tab_links[] = [
        'id' => $key,
        'label' => $this->tabLabel($key),
        'url' => Url::fromRoute('myeventlane_notifications.inbox', [], ['query' => ['tab' => $key, 'filter' => NotificationFilter::FILTER_ALL]])->toString(),
        'active' => $key === $tab,
      ];
    }

    $filter_links = [];
    foreach (NotificationFilter::filtersForTab($tab) as $key) {
      $filter_links[] = [
        'id' => $key,
        'label' => $this->filterLabel($key),
        'url' => Url::fromRoute('myeventlane_notifications.inbox', [], ['query' => ['tab' => $tab, 'filter' => $key]])->toString(),
        'active' => $key === $filter,
      ];
    }

    $build = [
      '#theme' => 'mel_notification_inbox',
      '#tab' => $tab,
      '#filter' => $filter,
      '#tab_links' => $tab_links,
      '#filter_links' => $filter_links,
      '#groups' => $groups_out,
      '#empty' => $rows === [],
      '#settings_url' => Url::fromRoute('myeventlane_notifications.preferences')->toString(),
      '#pager_info' => [
        'page' => $page,
        'page_size' => NotificationUserInboxService::INBOX_PAGE_SIZE,
        'has_more' => count($ids) >= NotificationUserInboxService::INBOX_PAGE_SIZE,
      ],
      '#attached' => [
        'library' => ['myeventlane_notifications/user_experience'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];

    if ($rows !== []) {
      $build['#mark_all_form'] = $this->formBuilder()->getForm(MarkAllReadForm::class, $tab, $filter);
    }

    return $build;
  }

  private function tabLabel(string $key): string {
    return match ($key) {
      NotificationFilter::TAB_ALL => (string) $this->t('All'),
      NotificationFilter::TAB_PERSONAL => (string) $this->t('Personal'),
      NotificationFilter::TAB_BUSINESS => (string) $this->t('Business'),
      NotificationFilter::TAB_PLATFORM => (string) $this->t('Platform'),
      default => $key,
    };
  }

  private function filterLabel(string $key): string {
    return match ($key) {
      NotificationFilter::FILTER_ALL => (string) $this->t('All'),
      NotificationFilter::FILTER_UNREAD => (string) $this->t('Unread'),
      NotificationFilter::FILTER_TICKETS => (string) $this->t('Tickets'),
      NotificationFilter::FILTER_ORDERS => (string) $this->t('Orders'),
      NotificationFilter::FILTER_REMINDERS => (string) $this->t('Reminders'),
      NotificationFilter::FILTER_SALES => (string) $this->t('Sales'),
      NotificationFilter::FILTER_REFUNDS => (string) $this->t('Refunds'),
      NotificationFilter::FILTER_RSVPS => (string) $this->t('RSVPs'),
      NotificationFilter::FILTER_FOLLOWERS => (string) $this->t('Followers'),
      NotificationFilter::FILTER_EVENT_UPDATES => (string) $this->t('Event updates'),
      NotificationFilter::FILTER_BOOSTS => (string) $this->t('Boosts'),
      NotificationFilter::FILTER_SYSTEM => (string) $this->t('System'),
      NotificationFilter::LEGACY_EVENTS => (string) $this->t('Events'),
      NotificationFilter::LEGACY_PLATFORM => (string) $this->t('Platform'),
      default => $key,
    };
  }

}
