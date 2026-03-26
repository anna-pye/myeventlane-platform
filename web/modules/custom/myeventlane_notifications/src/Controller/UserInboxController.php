<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\Form\MarkAllReadForm;
use Drupal\myeventlane_notifications\Form\MarkDeliveryReadForm;
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

    $filter = (string) $request->query->get('filter', NotificationFilter::ALL);
    if (!in_array($filter, NotificationFilter::allowed(), TRUE)) {
      $filter = NotificationFilter::ALL;
    }

    $page = max(0, (int) $request->query->get('page', 0));
    $ids = $this->userInbox->getInboxDeliveryIds($uid, $filter, $page);
    $rows = $this->viewBuilder->buildRowsForDeliveries($ids, $uid);
    $rows = $this->viewBuilder->applyGroupSummaries($rows);
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
          'mark_read_form' => $this->formBuilder()->getForm(MarkDeliveryReadForm::class, (int) $row['delivery_id'], $filter),
        ];
      }
      $groups_out[] = [
        'label' => $label,
        'items' => $items,
      ];
    }

    $filter_links = [];
    foreach (NotificationFilter::allowed() as $key) {
      $filter_links[] = [
        'id' => $key,
        'label' => $this->filterLabel($key),
        'url' => Url::fromRoute('myeventlane_notifications.inbox', [], ['query' => ['filter' => $key]])->toString(),
        'active' => $key === $filter,
      ];
    }

    $build = [
      '#theme' => 'mel_notification_inbox',
      '#filter' => $filter,
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
      $build['#mark_all_form'] = $this->formBuilder()->getForm(MarkAllReadForm::class, $filter);
    }

    return $build;
  }

  private function filterLabel(string $key): string {
    return match ($key) {
      NotificationFilter::ALL => (string) $this->t('All'),
      NotificationFilter::UNREAD => (string) $this->t('Unread'),
      NotificationFilter::TICKETS => (string) $this->t('Tickets'),
      NotificationFilter::EVENTS => (string) $this->t('Events'),
      NotificationFilter::REMINDERS => (string) $this->t('Reminders'),
      NotificationFilter::PLATFORM => (string) $this->t('Platform'),
      default => $key,
    };
  }

}
