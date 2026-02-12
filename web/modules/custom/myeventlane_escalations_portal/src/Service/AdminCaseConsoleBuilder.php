<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Module\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;
use Drupal\myeventlane_escalations_sla\Service\EscalationSlaBadgeResolver;
use Psr\Log\LoggerInterface;

/**
 * Builds the admin case console layout for entity.escalation.canonical.
 *
 * Composes header (chips + workflow), main (thread + composer), sidebar
 * (order + refunds + playbooks + AI). Does not rebuild entity fields.
 */
final class AdminCaseConsoleBuilder {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly EscalationThreadRenderer $threadRenderer,
    private readonly ?LoggerInterface $logger = NULL,
    private readonly ?EscalationSlaBadgeResolver $slaBadgeResolver = NULL,
  ) {}

  /**
   * Builds the structured render arrays for the admin case console.
   *
   * @param array $build
   *   The entity view build array (with field_escalation_thread, refund_context,
   *   myeventlane_staff_playbooks, governance_communication_standards,
   *   mel_ai_draft_root if present).
   * @param \Drupal\myeventlane_escalations\Entity\EscalationInterface $escalation
   *   The escalation entity.
   *
   * @param string $title
   *   The page title (e.g. escalation subject).
   *
   * @return array{
   *   header_left: array,
   *   header_right: array,
   *   main: array,
   *   sidebar: array,
   *   attached: array,
   *   cache: array
   * }
   */
  public function buildConsole(array $build, EscalationInterface $escalation, string $title = ''): array {
    $thread_container = $build['field_escalation_thread'] ?? [];
    $thread = $thread_container['thread'] ?? $this->threadRenderer->renderThread($escalation);
    $composer = $thread_container['comment_form'] ?? [];

    // Header: left (title + chips), right (workflow form).
    $header = $this->buildHeader($escalation, $title);

    // Main: thread + composer + composer tools placeholder.
    $main = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-case-console__main-content']],
      'thread' => $thread,
      'composer' => $composer,
      'composer_tools' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'mel-composer-tools-root',
          'class' => ['mel-composer-tools'],
        ],
      ],
    ];

    // Sidebar: order + refunds + playbooks + AI.
    $sidebar = $this->buildSidebar($build, $escalation);

    // Merge #attached from moved blocks.
    $attached = $build['#attached'] ?? [];
    foreach (['refund_context', 'myeventlane_staff_playbooks', 'governance_communication_standards', 'mel_ai_draft_root'] as $key) {
      if (!empty($build[$key]['#attached'])) {
        $attached = array_merge_recursive($attached, $build[$key]['#attached']);
      }
    }

    // Preserve cache metadata.
    $cache = $build['#cache'] ?? [];
    foreach (['refund_context', 'myeventlane_staff_playbooks', 'governance_communication_standards', 'mel_ai_draft_root'] as $key) {
      if (!empty($build[$key]['#cache'])) {
        $cache['contexts'] = array_merge(
          $cache['contexts'] ?? [],
          $build[$key]['#cache']['contexts'] ?? []
        );
        $cache['tags'] = array_merge(
          $cache['tags'] ?? [],
          $build[$key]['#cache']['tags'] ?? []
        );
        if (isset($build[$key]['#cache']['max-age'])) {
          $cache['max-age'] = min(
            $cache['max-age'] ?? \Drupal\Core\Cache\Cache::PERMANENT,
            (int) $build[$key]['#cache']['max-age']
          );
        }
      }
    }

    return [
      'header_left' => $header['left'],
      'header_right' => $header['right'],
      'main' => $main,
      'sidebar' => $sidebar,
      'attached' => $attached,
      'cache' => $cache,
    ];
  }

  /**
   * Builds the header with status/priority/waiting chips and workflow form.
   */
  private function buildHeader(EscalationInterface $escalation, string $title = ''): array {
    $status = (string) $escalation->get('status')->value;
    $priority = (string) $escalation->get('priority')->value;
    $waiting_on = $escalation->hasField('field_waiting_on') && !$escalation->get('field_waiting_on')->isEmpty()
      ? (string) $escalation->get('field_waiting_on')->value
      : '';

    $status_labels = [
      'new' => t('New'),
      'in_progress' => t('In progress'),
      'waiting_vendor' => t('Waiting on vendor'),
      'waiting_customer' => t('Waiting on customer'),
      'resolved' => t('Resolved'),
      'closed' => t('Closed'),
    ];
    $priority_labels = [
      'low' => t('Low'),
      'normal' => t('Normal'),
      'high' => t('High'),
      'urgent' => t('Urgent'),
    ];
    $waiting_labels = [
      'customer' => t('Waiting on customer'),
      'vendor' => t('Waiting on vendor'),
      'staff' => t('Internal review'),
    ];

    $chips = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-case-console__chips']],
    ];
    $chips['status'] = [
      '#type' => 'markup',
      '#markup' => '<span class="mel-chip mel-chip--status">' . htmlspecialchars($status_labels[$status] ?? $status) . '</span>',
    ];
    $chips['priority'] = [
      '#type' => 'markup',
      '#markup' => '<span class="mel-chip mel-chip--priority">' . htmlspecialchars($priority_labels[$priority] ?? $priority) . '</span>',
    ];
    if ($waiting_on && isset($waiting_labels[$waiting_on])) {
      $chips['waiting'] = [
        '#type' => 'markup',
        '#markup' => '<span class="mel-chip mel-chip--waiting">' . htmlspecialchars($waiting_labels[$waiting_on]) . '</span>',
      ];
    }
    if ($this->slaBadgeResolver !== NULL) {
      try {
        $badge = $this->slaBadgeResolver->resolve($escalation);
        $chips['sla'] = [
          '#theme' => 'escalation_sla_badge',
          '#badge' => $badge,
        ];
      }
      catch (\Throwable $e) {
        if ($this->logger) {
          $this->logger->debug('SLA badge skip: @message', ['@message' => $e->getMessage()]);
        }
      }
    }

    $actions_form = $this->formBuilder->getForm(
      \Drupal\myeventlane_escalations_portal\Form\AdminEscalationActionsForm::class,
      $escalation,
    );

    $left = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-case-console__header-left']],
      'chips' => $chips,
    ];
    if ($title !== '') {
      $left['title'] = [
        '#type' => 'markup',
        '#markup' => '<h1 class="mel-case-console__title">' . htmlspecialchars($title) . '</h1>',
        '#weight' => -10,
      ];
    }

    return [
      'left' => $left,
      'right' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-case-console__header-right']],
        'actions' => $actions_form,
      ],
    ];
  }

  /**
   * Builds the sidebar with order console and injected panels.
   */
  private function buildSidebar(array $build, EscalationInterface $escalation): array {
    $order = $escalation->get('order_id')->entity;
    $order_links = [];
    if ($order) {
      $order_links[] = [
        '#type' => 'link',
        '#title' => t('View order'),
        '#url' => $order->toUrl('canonical'),
        '#attributes' => ['class' => ['mel-link']],
      ];
      $order_summary = t('Order @number', ['@number' => $order->getOrderNumber() ?: '#' . $order->id()]);
    }
    else {
      $order_summary = t('No order linked');
    }

    $order_console = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-case-console__card', 'mel-case-console__order']],
      'summary' => [
        '#type' => 'markup',
        '#markup' => '<p class="mel-case-console__order-summary">' . htmlspecialchars((string) $order_summary) . '</p>',
      ],
      'links' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-case-console__row']],
      ],
    ];
    foreach ($order_links as $i => $link) {
      $order_console['links'][$i] = $link;
    }

    if (isset($build['refund_context'])) {
      $order_console['links']['refund_context'] = [
        '#type' => 'link',
        '#title' => t('Open refunds context'),
        '#url' => Url::fromRoute('entity.escalation.canonical', ['escalation' => $escalation->id()], ['fragment' => 'refund-context-panel']),
        '#attributes' => ['class' => ['mel-link']],
      ];
    }
    else {
      $order_console['helper'] = [
        '#type' => 'markup',
        '#markup' => '<p class="mel-case-console__helper">' . htmlspecialchars((string) t('Refunds are handled from the order screen.')) . '</p>',
      ];
    }

    $sidebar = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-case-console__sidebar']],
      'order' => $order_console,
    ];

    if (isset($build['refund_context'])) {
      $sidebar['refunds'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-case-console__card'],
          'id' => 'refund-context-panel',
        ],
        'content' => $build['refund_context'],
      ];
    }

    if (isset($build['myeventlane_staff_playbooks'])) {
      $sidebar['playbooks'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-case-console__card']],
        'content' => $build['myeventlane_staff_playbooks'],
      ];
    }

    if (isset($build['governance_communication_standards'])) {
      $sidebar['governance'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-case-console__card']],
        'content' => $build['governance_communication_standards'],
      ];
    }

    if (isset($build['mel_ai_draft_root'])) {
      $sidebar['ai_tools'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-case-console__card']],
        'content' => $build['mel_ai_draft_root'],
      ];
    }

    return $sidebar;
  }

}
