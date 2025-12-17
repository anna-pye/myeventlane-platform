<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for listing vendor events with bulk actions functionality.
 */
final class VendorEventsBulkActionsForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs the form.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_events_bulk_actions_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $userId = (int) $this->currentUser->id();

    // Query events owned by current user.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $query = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', $userId)
      ->sort('created', 'DESC');

    $eventIds = $query->execute();

    if (empty($eventIds)) {
      // Empty state.
      $form['empty_state'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-empty-state']],
        'icon' => [
          '#markup' => '<div class="mel-empty-state__icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>',
        ],
        'message' => [
          '#markup' => '<p class="mel-empty-state__text">' . $this->t("You haven't created any events yet.") . '</p>',
        ],
        'action' => [
          '#type' => 'link',
          '#title' => $this->t('Create Event'),
          '#url' => Url::fromUri('internal:/vendor/events/add'),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ],
      ];
      return $form;
    }

    $nodes = $nodeStorage->loadMultiple($eventIds);

    // Build options for checkboxes.
    $eventData = [];

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $nodeId = $node->id();

      // Get event date.
      $startDate = '';
      if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
        $dateItem = $node->get('field_event_start');
        if ($dateItem->date) {
          $startDate = $dateItem->date->format('M j, Y');
        }
        elseif (!empty($dateItem->value)) {
          $startDate = date('M j, Y', strtotime($dateItem->value));
        }
      }

      // Get venue name.
      $venue = '';
      if ($node->hasField('field_event_venue') && !$node->get('field_event_venue')->isEmpty()) {
        $venue = $node->get('field_event_venue')->value;
      }

      // Get event status.
      $status = $node->isPublished() ? 'Published' : 'Draft';
      $statusClass = $node->isPublished() ? 'success' : 'neutral';

      $eventData[$nodeId] = [
        'title' => $node->label(),
        'date' => $startDate,
        'venue' => $venue,
        'status' => $status,
        'status_class' => $statusClass,
        'view_url' => $node->toUrl()->toString(),
        'edit_url' => $node->toUrl('edit-form')->toString(),
        'manage_url' => '/vendor/events/' . $nodeId . '/overview',
      ];
    }

    // Bulk actions wrapper.
    $form['bulk_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-bulk-actions']],
    ];

    $form['bulk_actions']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Bulk Actions'),
      '#title_display' => 'invisible',
      '#options' => [
        '' => $this->t('- Select action -'),
        'publish' => $this->t('Publish'),
        'unpublish' => $this->t('Unpublish'),
        'delete' => $this->t('Delete'),
      ],
      '#attributes' => [
        'class' => ['mel-bulk-actions__select'],
      ],
    ];

    $form['bulk_actions']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--sm'],
      ],
    ];

    $form['bulk_actions']['select_all_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-bulk-actions__select-all']],
    ];

    $form['bulk_actions']['select_all_wrapper']['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select all'),
      '#attributes' => [
        'class' => ['mel-select-all'],
        'data-select-all' => 'events',
      ],
    ];

    // Events table with checkboxes.
    $form['events_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-events-table-wrapper']],
    ];

    $form['events_wrapper']['events'] = [
      '#type' => 'tableselect',
      '#header' => [
        'title' => $this->t('Event'),
        'date' => $this->t('Date'),
        'venue' => $this->t('Venue'),
        'status' => $this->t('Status'),
        'actions' => $this->t('Actions'),
      ],
      '#options' => [],
      '#empty' => $this->t('No events found.'),
      '#attributes' => ['class' => ['mel-table', 'mel-table--selectable']],
      '#js_select' => FALSE,
    ];

    // Build table rows.
    foreach ($eventData as $nodeId => $event) {
      $form['events_wrapper']['events']['#options'][$nodeId] = [
        'title' => [
          'data' => [
            '#markup' => '<span class="mel-table__cell--title">' . htmlspecialchars($event['title']) . '</span>',
          ],
        ],
        'date' => $event['date'],
        'venue' => $event['venue'],
        'status' => [
          'data' => [
            '#markup' => '<span class="mel-badge mel-badge--' . $event['status_class'] . '">' . $event['status'] . '</span>',
          ],
        ],
        'actions' => [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-table__actions']],
            'manage' => [
              '#type' => 'link',
              '#title' => $this->t('Manage'),
              '#url' => Url::fromUri('internal:' . $event['manage_url']),
              '#attributes' => ['class' => ['mel-btn', 'mel-btn--sm']],
            ],
            'edit' => [
              '#type' => 'link',
              '#title' => $this->t('Edit'),
              '#url' => Url::fromUri('internal:' . $event['edit_url']),
              '#attributes' => ['class' => ['mel-btn', 'mel-btn--sm', 'mel-btn--secondary']],
            ],
          ],
        ],
      ];
    }

    // Add JavaScript for select all functionality.
    $form['#attached']['library'][] = 'myeventlane_vendor/bulk_actions';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $action = $form_state->getValue('action');
    $selectedEvents = array_filter($form_state->getValue('events') ?? []);

    if (empty($action)) {
      $form_state->setErrorByName('action', $this->t('Please select an action.'));
      return;
    }

    if (empty($selectedEvents)) {
      $form_state->setErrorByName('events', $this->t('Please select at least one event.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $action = $form_state->getValue('action');
    $selectedEvents = array_filter($form_state->getValue('events') ?? []);

    if (empty($selectedEvents)) {
      return;
    }

    $userId = (int) $this->currentUser->id();
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    switch ($action) {
      case 'delete':
        $this->handleDelete($selectedEvents, $userId, $nodeStorage);
        break;

      case 'publish':
        $this->handlePublish($selectedEvents, $userId, $nodeStorage, TRUE);
        break;

      case 'unpublish':
        $this->handlePublish($selectedEvents, $userId, $nodeStorage, FALSE);
        break;
    }

    // Redirect back to the events list.
    $form_state->setRedirect('myeventlane_vendor.console.events');
  }

  /**
   * Handle bulk delete action.
   */
  private function handleDelete(array $selectedEvents, int $userId, $nodeStorage): void {
    $deletedCount = 0;

    foreach ($selectedEvents as $nodeId) {
      $node = $nodeStorage->load($nodeId);

      // Security check: ensure the node exists, is an event, and belongs to current user.
      if ($node instanceof NodeInterface
          && $node->bundle() === 'event'
          && (int) $node->getOwnerId() === $userId) {
        $node->delete();
        $deletedCount++;
      }
    }

    if ($deletedCount > 0) {
      $this->messenger()->addStatus($this->t('Successfully deleted @count event(s).', [
        '@count' => $deletedCount,
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No events were deleted.'));
    }
  }

  /**
   * Handle bulk publish/unpublish action.
   */
  private function handlePublish(array $selectedEvents, int $userId, $nodeStorage, bool $publish): void {
    $updatedCount = 0;
    $action = $publish ? 'published' : 'unpublished';

    foreach ($selectedEvents as $nodeId) {
      $node = $nodeStorage->load($nodeId);

      // Security check: ensure the node exists, is an event, and belongs to current user.
      if ($node instanceof NodeInterface
          && $node->bundle() === 'event'
          && (int) $node->getOwnerId() === $userId) {
        $node->setPublished($publish);
        $node->save();
        $updatedCount++;
      }
    }

    if ($updatedCount > 0) {
      $this->messenger()->addStatus($this->t('Successfully @action @count event(s).', [
        '@action' => $action,
        '@count' => $updatedCount,
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No events were updated.'));
    }
  }

}
















