<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Drupal\myeventlane_escalations_sla\Service\EscalationSlaBadgeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff-facing SLA dashboard for escalations.
 *
 * Staff users MAY see escalation level, SLA due timestamps, and breach flags.
 */
final class EscalationSlaDashboardController extends ControllerBase {

  public function __construct(
    private readonly EscalationSlaBadgeResolver $badgeResolver,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_sla.badge_resolver'),
      $container->get('date.formatter'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Renders the SLA dashboard for staff.
   */
  public function dashboard(): array {
    $ids = $this->entityTypeManager()->getStorage('escalation')
      ->getQuery()
      ->condition('status', ['resolved', 'closed'], 'NOT IN')
      ->sort('created', 'DESC')
      ->range(0, 100)
      ->accessCheck(FALSE)
      ->execute();

    $rows = [];
    if ($ids) {
      $escalations = $this->entityTypeManager()->getStorage('escalation')->loadMultiple($ids);
      foreach ($escalations as $escalation) {
        /** @var \Drupal\myeventlane_escalations\Entity\Escalation $escalation */
        $badge = $this->badgeResolver->resolve($escalation);

        $level = $escalation->hasField('field_escalation_level')
          ? (int) ($escalation->get('field_escalation_level')->value ?? 0)
          : 0;

        $first_due = $this->formatTimestamp($escalation, 'field_sla_first_due');
        $resolution_due = $this->formatTimestamp($escalation, 'field_sla_resolution_due');

        $first_breached = $escalation->hasField('field_sla_first_breached')
          ? (bool) ($escalation->get('field_sla_first_breached')->value ?? FALSE)
          : FALSE;
        $resolution_breached = $escalation->hasField('field_sla_resolution_breached')
          ? (bool) ($escalation->get('field_sla_resolution_breached')->value ?? FALSE)
          : FALSE;

        $waiting_on = $escalation->hasField('field_waiting_on')
          ? ($escalation->get('field_waiting_on')->value ?? '-')
          : '-';

        $customer = $escalation->getCustomer();

        $rows[] = [
          'id' => $escalation->id(),
          'subject' => [
            'data' => [
              '#type' => 'link',
              '#title' => $escalation->get('subject')->value,
              '#url' => Url::fromRoute('entity.escalation.canonical', ['escalation' => $escalation->id()]),
            ],
          ],
          'status' => $escalation->get('status')->value,
          'priority' => $escalation->get('priority')->value,
          'sla' => [
            'data' => [
              '#theme' => 'escalation_sla_badge',
              '#badge' => $badge,
            ],
          ],
          'level' => $level > 0 ? (string) $this->t('Level @level', ['@level' => $level]) : '-',
          'first_due' => $first_due,
          'first_breached' => $first_breached ? (string) $this->t('Yes') : '-',
          'resolution_due' => $resolution_due,
          'resolution_breached' => $resolution_breached ? (string) $this->t('Yes') : '-',
          'waiting_on' => $waiting_on,
          'customer' => $customer ? $customer->getDisplayName() : '-',
          'created' => $this->dateFormatter->format($escalation->getCreatedTime(), 'short'),
        ];
      }
    }

    $build = [];

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-bottom: 20px;'],
      'count' => [
        '#markup' => '<p>' . $this->t('Showing @count active escalation(s).', ['@count' => count($rows)]) . '</p>',
      ],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('#'),
        $this->t('Subject'),
        $this->t('Status'),
        $this->t('Priority'),
        $this->t('SLA'),
        $this->t('Level'),
        $this->t('First response due'),
        $this->t('1st breached'),
        $this->t('Resolution due'),
        $this->t('Res. breached'),
        $this->t('Waiting on'),
        $this->t('Customer'),
        $this->t('Created'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No active escalations.'),
      '#attributes' => ['class' => ['admin-escalation-sla-dashboard']],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $build,
      $this->t('Escalation SLA Dashboard'),
      $this->t('Active escalations with SLA badges and due dates.'),
    );
  }

  /**
   * Formats a timestamp field for display, or returns '-' if empty.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   * @param string $field_name
   *   The timestamp field name.
   *
   * @return string
   *   Formatted date or '-'.
   */
  private function formatTimestamp(object $escalation, string $field_name): string {
    if (!$escalation->hasField($field_name) || $escalation->get($field_name)->isEmpty()) {
      return '-';
    }

    $value = (int) $escalation->get($field_name)->value;
    if ($value <= 0) {
      return '-';
    }

    return $this->dateFormatter->format($value, 'short');
  }

}
