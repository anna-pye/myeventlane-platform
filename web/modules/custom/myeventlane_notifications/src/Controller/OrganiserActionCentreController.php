<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\Form\MarkDeliveryHandledForm;
use Drupal\myeventlane_notifications\Form\MarkDeliveryReadForm;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationFilter;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Drupal\myeventlane_notifications\Service\NotificationViewBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Organiser-specific Action Centre, separate from the customer inbox shell.
 */
final class OrganiserActionCentreController extends ControllerBase {

  public function __construct(
    private readonly NotificationUserInboxService $userInbox,
    private readonly NotificationViewBuilder $viewBuilder,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_notifications.user_inbox'),
      $container->get('myeventlane_notifications.view_builder'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Portfolio-level organiser updates.
   *
   * @return array<string, mixed>
   *   The Action Centre render array.
   */
  public function page(): array {
    return $this->buildActionCentre();
  }

  /**
   * Selected-event subset linked from Event Studio.
   *
   * @return array<string, mixed>
   *   The event-scoped Action Centre render array.
   */
  public function eventPage(NodeInterface $node): array {
    return $this->buildActionCentre($node);
  }

  /**
   * Builds organiser updates for the portfolio or one event.
   *
   * @return array<string, mixed>
   *   The Action Centre render array.
   */
  private function buildActionCentre(?NodeInterface $event = NULL): array {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }

    $eventId = $event ? (int) $event->id() : NULL;
    $deliveryIds = $this->userInbox->getActionCentreDeliveryIds($uid, $eventId);
    $rows = $this->viewBuilder->collapseActionCentreRows(
      $this->viewBuilder->buildRowsForDeliveries($deliveryIds, $uid),
    );
    $sections = [
      'attention' => [],
      'activity' => [],
      'platform' => [],
    ];

    foreach ($rows as $row) {
      $row['when'] = $this->formatWhen((int) ($row['delivered_at'] ?: $row['created']));
      $row['state_label'] = !empty($row['is_handled'])
        ? (string) $this->t('Handled')
        : (!empty($row['requires_action']) ? (string) $this->t('Needs action') : (string) $this->t('For your information'));
      $row['mark_read_form'] = !empty($row['is_unread'])
        ? $this->formBuilder()->getForm(
          MarkDeliveryReadForm::class,
          (int) $row['delivery_id'],
          NotificationFilter::TAB_BUSINESS,
          NotificationFilter::FILTER_ALL,
          $eventId
            ? 'myeventlane_notifications.organiser_event_action_centre'
            : 'myeventlane_notifications.organiser_action_centre',
          (int) ($eventId ?? 0),
        )
        : NULL;
      $row['handled_form'] = !empty($row['requires_action']) && empty($row['is_handled'])
        ? $this->formBuilder()->getForm(
          MarkDeliveryHandledForm::class,
          (int) $row['delivery_id'],
          (int) ($eventId ?? 0),
        )
        : NULL;

      if ($row['context'] === NotificationContext::PLATFORM) {
        $sections['platform'][] = $row;
      }
      elseif (!empty($row['requires_action']) && empty($row['is_handled'])) {
        $sections['attention'][] = $row;
      }
      else {
        $sections['activity'][] = $row;
      }
    }

    return [
      '#theme' => 'mel_organiser_action_centre',
      '#title' => $event === NULL ? NULL : $this->t('Event updates'),
      '#event' => $event ? [
        'id' => (int) $event->id(),
        'label' => (string) $event->label(),
        'studio_url' => Url::fromRoute('myeventlane_event_studio.workspace', [
          'node' => $event->id(),
        ])->toString(),
      ] : NULL,
      '#sections' => $sections,
      '#settings_url' => Url::fromRoute('myeventlane_notifications.organiser_preferences')->toString(),
      '#portfolio_url' => $event
        ? Url::fromRoute('myeventlane_notifications.organiser_action_centre')->toString()
        : NULL,
      '#attached' => ['library' => ['myeventlane_notifications/user_experience']],
      '#cache' => [
        'contexts' => ['user', 'route'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Formats a delivery timestamp for the organiser list.
   */
  private function formatWhen(int $timestamp): string {
    if ($timestamp < 1) {
      return (string) $this->t('Earlier');
    }
    return $this->dateFormatter->format($timestamp, 'custom', 'j M Y, g:ia');
  }

}
