<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Drupal\myeventlane_escalations_portal\Form\CustomerEscalationForm;
use Drupal\myeventlane_escalations_portal\Form\EscalationReplyForm;
use Drupal\myeventlane_escalations_portal\Service\EscalationMailer;
use Drupal\myeventlane_escalations_portal\Service\EscalationPartyResolver;
use Drupal\myeventlane_escalations_portal\Service\EscalationThreadRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Customer-facing escalation controller.
 */
final class CustomerEscalationController extends ControllerBase {

  public function __construct(
    private readonly EscalationPartyResolver $partyResolver,
    private readonly EscalationMailer $mailer,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EscalationThreadRenderer $threadRenderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_portal.party_resolver'),
      $container->get('myeventlane_escalations_portal.mailer'),
      $container->get('date.formatter'),
      $container->get('myeventlane_escalations_portal.thread_renderer'),
    );
  }

  /**
   * Lists the current customer's escalations.
   */
  public function list(): array {
    $uid = (int) $this->currentUser()->id();

    $ids = $this->entityTypeManager()->getStorage('escalation')
      ->getQuery()
      ->condition('user_id', $uid)
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->accessCheck(FALSE)
      ->execute();

    $rows = [];
    if ($ids) {
      $escalations = $this->entityTypeManager()->getStorage('escalation')->loadMultiple($ids);
      foreach ($escalations as $escalation) {
        $rows[] = [
          'id' => $escalation->id(),
          'subject' => [
            'data' => [
              '#type' => 'link',
              '#title' => $escalation->get('subject')->value,
              '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_view', ['escalation' => $escalation->id()]),
            ],
          ],
          'status' => $this->resolveCustomerStatusLabel($escalation),
          'priority' => $escalation->get('priority')->value,
          'created' => $this->dateFormatter->format((int) $escalation->get('created')->value, 'short'),
        ];
      }
    }

    $helpBlock = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-support-actions']],
      '#weight' => -10,
    ];
    if ($this->moduleHandler()->moduleExists('myeventlane_help_centre')) {
      $helpBlock['help_centre'] = [
        '#type' => 'link',
        '#title' => $this->t('Help Centre'),
        '#url' => Url::fromRoute('myeventlane_help_centre.home'),
        '#attributes' => ['class' => ['mel-support-actions__item', 'mel-button', 'mel-button--secondary']],
      ];
    }
    if ($this->moduleHandler()->moduleExists('myeventlane_help_assistant')) {
      try {
        $helpBlock['ask'] = [
          '#type' => 'link',
          '#title' => $this->t('Ask a question'),
          '#url' => Url::fromRoute('myeventlane_help_assistant.page'),
          '#attributes' => ['class' => ['mel-support-actions__item', 'mel-button', 'mel-button--secondary']],
        ];
      }
      catch (\Throwable) {
        // Route unavailable; omit link.
      }
    }
    $helpBlock['contact'] = [
      '#type' => 'link',
      '#title' => $this->t('Contact support'),
      '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_add'),
      '#attributes' => ['class' => ['mel-support-actions__item', 'mel-button', 'mel-button--primary']],
    ];

    $content = [
      'help_block' => $helpBlock,
      'add_link' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-support-actions']],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Submit a new escalation'),
          '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_add'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('#'),
          $this->t('Subject'),
          $this->t('Status'),
          $this->t('Priority'),
          $this->t('Created'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('You have no support escalations yet.'),
        '#attributes' => ['class' => ['mel-support-table']],
      ],
    ];

    return [
      '#theme' => 'mel_support_layout',
      '#title' => $this->t('My Support'),
      '#intro' => $this->t('View and manage your support escalations.'),
      '#content' => $content,
    ];
  }

  /**
   * Renders the add-escalation form wrapped in the support layout.
   */
  public function add(): array {
    $form = $this->formBuilder()->getForm(CustomerEscalationForm::class);
    return [
      '#theme' => 'mel_support_layout',
      '#title' => $this->t('Submit an Escalation'),
      '#intro' => $this->t('Describe your issue and we\'ll get back to you as soon as possible.'),
      '#content' => $form,
    ];
  }

  /**
   * Views a single escalation with comment thread.
   */
  public function view(int $escalation): array {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return ['#markup' => $this->t('Escalation not found.')];
    }

    /** @var \Drupal\myeventlane_escalations\Entity\Escalation $entity */
    $build = [];

    // Friendly status banner (NO SLA badges for customers).
    $build['status_banner'] = $this->buildCustomerStatusBanner($entity);

    // Escalation details (NO internal notes).
    $build['details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-escalation-card']],
      'subject' => ['#markup' => '<h2>' . htmlspecialchars((string) $entity->get('subject')->value) . '</h2>'],
      'meta' => [
        '#markup' => '<div><strong>' . $this->t('Type:') . '</strong> ' . htmlspecialchars((string) $entity->get('type')->value)
          . ' &nbsp; <strong>' . $this->t('Priority:') . '</strong> ' . htmlspecialchars((string) $entity->get('priority')->value)
          . '</div>',
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '<p>' . nl2br(htmlspecialchars((string) $entity->get('description')->value)) . '</p>',
        '#attributes' => ['class' => ['mel-escalation-card__description']],
      ],
    ];

    // Meta row: status chip, priority chip (customer-friendly labels).
    $build['meta_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-meta-row']],
      'status' => [
        '#markup' => '<span class="mel-chip mel-chip--status">' . htmlspecialchars($this->resolveCustomerStatusLabel($entity)) . '</span>',
      ],
      'priority' => [
        '#markup' => '<span class="mel-chip mel-chip--priority">' . htmlspecialchars((string) $entity->get('priority')->value) . '</span>',
      ],
    ];

    // Reopen button for resolved/closed.
    $status = (string) $entity->get('status')->value;
    if (in_array($status, ['resolved', 'closed'], TRUE)) {
      $build['reopen'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-escalation-card__actions']],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Request to reopen'),
          '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_reopen', ['escalation' => $escalation]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ];
    }

    // Comment thread (shared service).
    $build['thread'] = $this->threadRenderer->renderThread($entity);

    // Reply form (only if not resolved/closed).
    if (!in_array($status, ['resolved', 'closed'], TRUE)) {
      if ($this->currentUser()->hasPermission('comment on own escalation')) {
        $build['reply_form'] = \_myeventlane_escalations_portal_wrap_reply_shell(
          $this->formBuilder()->getForm(EscalationReplyForm::class, $entity)
        );
      }
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
      return (string) $this->t('Escalation');
    }
    return (string) $entity->get('subject')->value;
  }

  /**
   * Reopens a resolved/closed escalation (customer request).
   */
  public function reopen(int $escalation): RedirectResponse {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      $this->messenger()->addError($this->t('Escalation not found.'));
      return new RedirectResponse('/my/support/escalations');
    }

    $entity->set('status', 'in_progress');
    if ($entity->hasField('field_waiting_on')) {
      // Use vendor if assigned, otherwise staff. Avoids invalid state when
      // no vendor exists (e.g. staff-only escalation).
      $entity->set('field_waiting_on', $entity->hasAssignedVendor() ? 'vendor' : 'staff');
    }
    $entity->save();

    $this->mailer->notifyVendorReopened($entity);
    $this->messenger()->addStatus($this->t('Your escalation has been reopened.'));

    return new RedirectResponse('/my/support/escalations/' . $escalation);
  }

  /**
   * Builds a friendly status banner for the customer escalation view.
   *
   * Customers must NEVER see SLA badges, timestamps, breach flags, or
   * escalation levels. Only friendly, gender-neutral, respectful copy.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return array
   *   Render array for the status banner.
   */
  private function buildCustomerStatusBanner(Escalation $escalation): array {
    $status = (string) $escalation->get('status')->value;
    $waiting_on = $escalation->hasField('field_waiting_on')
      ? (string) ($escalation->get('field_waiting_on')->value ?? '')
      : '';

    // Determine friendly message and visual style.
    [$message, $variant, $icon] = match (TRUE) {
      $status === 'resolved' => [
        (string) $this->t('Resolved — you can reopen this if needed.'),
        'resolved',
        "\xE2\x9C\x85",
      ],
      $status === 'closed' => [
        (string) $this->t('This escalation has been closed.'),
        'closed',
        "\xF0\x9F\x93\x81",
      ],
      $status === 'new' => [
        (string) $this->t('Your escalation has been received and is being reviewed.'),
        'info',
        "\xF0\x9F\x93\xA8",
      ],
      $waiting_on === 'vendor' => [
        (string) $this->t('Waiting for the organiser to respond.'),
        'waiting',
        "\xE2\x8F\xB3",
      ],
      $waiting_on === 'staff' => [
        (string) $this->t('MyEventLane support is reviewing this.'),
        'info',
        "\xF0\x9F\x94\x8D",
      ],
      $waiting_on === 'customer' => [
        (string) $this->t("We're waiting for your reply."),
        'waiting',
        "\xF0\x9F\x92\xAC",
      ],
      default => [
        (string) $this->t('Your escalation is being handled.'),
        'info',
        "\xF0\x9F\x93\x8B",
      ],
    };

    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['mel-escalation-status-banner', 'mel-escalation-status-banner--' . $variant]],
      '#value' => '<span class="mel-escalation-status-banner__icon" aria-hidden="true">' . $icon . '</span> ' . htmlspecialchars($message),
    ];
  }

  /**
   * Resolves a customer-friendly status label for the list view.
   *
   * Customers see simplified status text, not raw field values.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return string
   *   Friendly status label.
   */
  private function resolveCustomerStatusLabel(Escalation $escalation): string {
    $status = (string) $escalation->get('status')->value;

    return match ($status) {
      'new' => (string) $this->t('Received'),
      'in_progress' => (string) $this->t('In progress'),
      'waiting_vendor' => (string) $this->t('Awaiting response'),
      'waiting_customer' => (string) $this->t('Awaiting your reply'),
      'resolved' => (string) $this->t('Resolved'),
      'closed' => (string) $this->t('Closed'),
      default => $status,
    };
  }

}
