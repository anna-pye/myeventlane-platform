<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Drupal\myeventlane_escalations_portal\Form\EscalationReplyForm;
use Drupal\myeventlane_escalations_portal\Form\VendorEscalationForm;
use Drupal\myeventlane_escalations_portal\Service\EscalationMailer;
use Drupal\myeventlane_escalations_portal\Service\EscalationPartyResolver;
use Drupal\myeventlane_escalations_portal\Service\EscalationThreadRenderer;
use Drupal\myeventlane_escalations_sla\Service\EscalationSlaBadgeResolver;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\VendorSupportHubBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Organiser-facing support controller.
 */
final class VendorEscalationController extends ControllerBase {

  public function __construct(
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly EscalationPartyResolver $partyResolver,
    private readonly EscalationMailer $mailer,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EscalationSlaBadgeResolver $badgeResolver,
    private readonly EscalationThreadRenderer $threadRenderer,
    private readonly VendorSupportHubBuilder $supportHubBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_escalations_portal.party_resolver'),
      $container->get('myeventlane_escalations_portal.mailer'),
      $container->get('date.formatter'),
      $container->get('myeventlane_escalations_sla.badge_resolver'),
      $container->get('myeventlane_escalations_portal.thread_renderer'),
      $container->get('myeventlane_vendor.support_hub_builder'),
    );
  }

  /**
   * Support hub with open requests for the current organiser.
   */
  public function list(): array {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser());
    if (!$vendor) {
      return ['#markup' => '<p>' . $this->t('You are not associated with an organiser account.') . '</p>'];
    }

    // Open requests only — resolved/closed must not drive Support health.
    $ids = $this->entityTypeManager()->getStorage('escalation')
      ->getQuery()
      ->condition('vendor_id', $vendor->id())
      ->condition('status', ['resolved', 'closed'], 'NOT IN')
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->accessCheck(FALSE)
      ->execute();

    $rows = [];
    if ($ids) {
      $escalations = $this->entityTypeManager()->getStorage('escalation')->loadMultiple($ids);
      foreach ($escalations as $escalation) {
        /** @var \Drupal\myeventlane_escalations\Entity\Escalation $escalation */
        $badge = $this->badgeResolver->resolve($escalation);
        $waiting_on = $this->resolveWaitingOnLabel($escalation);

        $rows[] = [
          'id' => $escalation->id(),
          'subject' => [
            'data' => [
              '#type' => 'link',
              '#title' => $escalation->get('subject')->value,
              '#url' => Url::fromRoute('myeventlane_escalations_portal.vendor_view', ['escalation' => $escalation->id()]),
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
          'waiting_on' => $waiting_on,
          'created' => $this->dateFormatter->format((int) $escalation->get('created')->value, 'short'),
        ];
      }
    }

    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('#'),
        $this->t('Subject'),
        $this->t('Status'),
        $this->t('Priority'),
        $this->t('Response time'),
        $this->t('Waiting on'),
        $this->t('Created'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No open support requests right now.'),
      '#attributes' => ['class' => ['mel-support-table']],
    ];

    $hub = $this->supportHubBuilder->build($rows);

    return [
      '#theme' => 'myeventlane_vendor_support_hub',
      '#hub' => $hub,
      '#requests_table' => $table,
      '#title' => $this->t('Support'),
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Organiser contact form — creates escalations with vendor_id set.
   */
  public function add(): array {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser());
    if (!$vendor) {
      return ['#markup' => '<p>' . $this->t('You are not associated with an organiser account.') . '</p>'];
    }

    $form = $this->formBuilder()->getForm(VendorEscalationForm::class);
    return [
      '#theme' => 'mel_support_layout',
      '#title' => $this->t('Contact Support'),
      '#intro' => $this->t('Tell us what’s going on — we’ll reply by email.'),
      '#content' => $form,
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Views a single escalation with comment thread.
   */
  public function view(int $escalation): array {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return ['#markup' => $this->t('Support request not found.')];
    }

    /** @var \Drupal\myeventlane_escalations\Entity\Escalation $entity */
    $build = [];

    // Escalation details (NO internal notes, NO SLA timestamps, NO escalation levels).
    $waiting_on = $this->resolveWaitingOnLabel($entity);
    $badge = $this->badgeResolver->resolve($entity);

    $build['details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-escalation-card']],
      'subject' => ['#markup' => '<h2>' . htmlspecialchars((string) $entity->get('subject')->value) . '</h2>'],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '<p>' . nl2br(htmlspecialchars((string) $entity->get('description')->value)) . '</p>',
        '#attributes' => ['class' => ['mel-escalation-card__description']],
      ],
    ];

    // Meta row: status, priority, waiting_on, SLA badge (chips only).
    $build['meta_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-meta-row']],
      'status' => [
        '#markup' => '<span class="mel-chip mel-chip--status">' . htmlspecialchars((string) $entity->get('status')->value) . '</span>',
      ],
      'priority' => [
        '#markup' => '<span class="mel-chip mel-chip--priority">' . htmlspecialchars((string) $entity->get('priority')->value) . '</span>',
      ],
      'waiting_on' => [
        '#markup' => '<span class="mel-chip mel-chip--waiting">' . htmlspecialchars($waiting_on) . '</span>',
      ],
      'sla_badge' => [
        '#theme' => 'escalation_sla_badge',
        '#badge' => $badge,
      ],
    ];

    // Action buttons.
    $status = (string) $entity->get('status')->value;
    $build['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['mel-escalation-card__actions']]];

    if (!in_array($status, ['resolved', 'closed'], TRUE)) {
      if ($this->partyResolver->isVendor($this->currentUser(), $entity)) {
        $build['actions']['resolve'] = [
          '#type' => 'link',
          '#title' => $this->t('Mark as resolved'),
          '#url' => Url::fromRoute('myeventlane_escalations_portal.vendor_resolve', ['escalation' => $escalation]),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--small']],
        ];
      }
    }

    if (in_array($status, ['resolved', 'closed'], TRUE) && $this->currentUser()->hasPermission('reopen escalations')) {
      $build['actions']['reopen'] = [
        '#type' => 'link',
        '#title' => $this->t('Reopen request'),
        '#url' => Url::fromRoute('myeventlane_escalations_portal.vendor_reopen', ['escalation' => $escalation]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    // Comment thread (shared service).
    $build['thread'] = $this->threadRenderer->renderThread($entity);

    // Reply form (only if not resolved/closed).
    if (!in_array($status, ['resolved', 'closed'], TRUE)) {
      if ($this->currentUser()->hasPermission('comment on vendor escalations')) {
        $build['reply_form'] = \_myeventlane_escalations_portal_wrap_reply_shell(
          $this->formBuilder()->getForm(EscalationReplyForm::class, $entity)
        );
      }
    }

    // AI assistant link (only when vendor AI module enabled and user has permission).
    if ($this->moduleHandler()->moduleExists('myeventlane_vendor_ai')
      && $this->currentUser()->hasPermission('use vendor ai assistant')) {
      $build['ai_assistant'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ai-assistant']],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Ask MEL Assistant'),
          '#url' => Url::fromRoute('myeventlane_vendor_ai.assistant', ['escalation' => $escalation]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'mel-ai-assistant__link'],
            'aria-label' => $this->t('Open MEL Assistant for policy questions'),
          ],
        ],
      ];
    }

    return [
      '#theme' => 'mel_support_layout',
      '#title' => (string) $entity->get('subject')->value,
      '#intro' => NULL,
      '#content' => $build,
      '#attached' => [
        'library' => [
          'myeventlane_escalations_portal/escalation_thread_ux',
          'myeventlane_escalations_portal/escalation_reply_sticky',
        ],
      ],
    ];
  }

  /**
   * Title callback for the view route.
   */
  public function viewTitle(int $escalation): string {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return (string) $this->t('Support request');
    }
    return (string) $entity->get('subject')->value;
  }

  /**
   * Resolves an escalation.
   */
  public function resolve(int $escalation): RedirectResponse {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      $this->messenger()->addError($this->t('Support request not found.'));
      return new RedirectResponse('/vendor/support');
    }

    $entity->set('status', 'resolved');
    $entity->save();

    $this->mailer->notifyCustomerResolved($entity);
    $this->messenger()->addStatus($this->t('The support request has been marked as resolved.'));

    return new RedirectResponse('/vendor/support/' . $escalation);
  }

  /**
   * Reopens an escalation (staff only via vendor portal).
   */
  public function reopen(int $escalation): RedirectResponse {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      $this->messenger()->addError($this->t('Support request not found.'));
      return new RedirectResponse('/vendor/support');
    }

    $entity->set('status', 'in_progress');
    if ($entity->hasField('field_waiting_on')) {
      // Use vendor if assigned, otherwise staff. Avoids invalid state when
      // no vendor exists (e.g. staff-only escalation).
      $entity->set('field_waiting_on', $entity->hasAssignedVendor() ? 'vendor' : 'staff');
    }
    $entity->save();

    $this->mailer->notifyVendorReopened($entity);
    $this->messenger()->addStatus($this->t('The support request has been reopened.'));

    return new RedirectResponse('/vendor/support/' . $escalation);
  }

  /**
   * Resolves waiting_on field value to a vendor-friendly label.
   *
   * Vendors must NEVER see raw field values or SLA mechanics.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return string
   *   Friendly label for the current waiting_on state.
   */
  private function resolveWaitingOnLabel(Escalation $escalation): string {
    if (!$escalation->hasField('field_waiting_on') || $escalation->get('field_waiting_on')->isEmpty()) {
      return '-';
    }

    $value = (string) $escalation->get('field_waiting_on')->value;

    return match ($value) {
      'vendor' => (string) $this->t('Waiting on you'),
      'customer' => (string) $this->t('Waiting on customer'),
      'staff' => (string) $this->t('Waiting on MyEventLane support'),
      default => '-',
    };
  }

}
