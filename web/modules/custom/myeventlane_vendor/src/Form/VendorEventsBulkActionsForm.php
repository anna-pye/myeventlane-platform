<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds safe bulk publication controls to the canonical organiser event cards.
 *
 * The event cards and their checkboxes use the same managed-event view model.
 * Destructive removal remains an individual, booking-aware action.
 */
final class VendorEventsBulkActionsForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
    private readonly EventStudioSaveService $eventStudioSave,
    private readonly UserVendorMembershipQuery $membershipQuery,
  ) {
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
      $container->get('logger.channel.myeventlane_vendor'),
      $container->get('myeventlane_event_studio.save'),
      $container->get('myeventlane_vendor.user_vendor_membership_query'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_events_bulk_actions_form';
  }

  /**
   * Builds checkbox controls around the canonical event-index model.
   *
   * @param array<string, mixed> $form
   *   Initial form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   * @param array<string, mixed> $model
   *   Canonical, filtered event-index view model for the current page.
   *
   * @return array<string, mixed>
   *   Completed form render array.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    array $model = [],
  ): array {
    $form['#attributes']['class'][] = 'mel-vendor-events-form';
    $form['#vendor_event_index_model'] = $model;
    $form['#attached']['library'][] = 'myeventlane_vendor_theme/mel_vendor_events';

    $events = is_array($model['events'] ?? NULL) ? $model['events'] : [];
    $allowedPageIds = [];
    $form['events'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-vendor-events-selection']],
    ];

    foreach ($events as $event) {
      $nid = (int) ($event['nid'] ?? 0);
      if ($nid <= 0) {
        continue;
      }
      $allowedPageIds[] = $nid;
      $title = (string) ($event['title'] ?? $this->t('Untitled event'));
      $form['events'][$nid] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select @title', ['@title' => $title]),
        '#return_value' => $nid,
        '#attributes' => [
          'class' => ['mel-event-select'],
          'data-event-select' => (string) $nid,
        ],
        '#wrapper_attributes' => [
          'class' => ['mel-vendor-events-v2__selection'],
        ],
      ];
    }
    $form_state->set('allowed_page_event_ids', $allowedPageIds);

    if ($allowedPageIds !== []) {
      $form['bulk_actions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'mel-bulk-actions',
            'mel-vendor-events-v2__bulk-actions',
          ],
          'data-events-bulk-toolbar' => '',
          'hidden' => 'hidden',
        ],
      ];
      $form['bulk_actions']['action'] = [
        '#type' => 'select',
        '#title' => $this->t('Action for selected events'),
        '#options' => [
          '' => $this->t('- Select action -'),
          'publish' => $this->t('Publish'),
          'unpublish' => $this->t('Unpublish'),
        ],
        '#attributes' => ['class' => ['mel-bulk-actions__select']],
      ];
      $form['bulk_actions']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Apply'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--sm'],
        ],
      ];
      $form['bulk_actions']['select_all'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select this page'),
        '#attributes' => [
          'class' => ['mel-select-all'],
          'data-select-all' => 'events',
        ],
        '#wrapper_attributes' => [
          'class' => ['mel-bulk-actions__select-all'],
        ],
      ];
    }

    $form['#attached']['library'][] = 'myeventlane_vendor/bulk_actions';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $action = (string) $form_state->getValue('action', '');
    $selected = $this->selectedEventIds($form_state);

    if (!in_array($action, ['publish', 'unpublish'], TRUE)) {
      $form_state->setErrorByName(
        'action',
        $this->t('Select an action for these events.'),
      );
    }
    if ($selected === []) {
      $form_state->setErrorByName(
        'events',
        $this->t('Select at least one event.'),
      );
      return;
    }

    $pageIds = array_map(
      'intval',
      (array) $form_state->get('allowed_page_event_ids'),
    );
    $managedIds = $this->membershipQuery->getManagedEventNodeIds(
      (int) $this->currentUser->id(),
      FALSE,
    );
    foreach ($selected as $nid) {
      if (!in_array($nid, $pageIds, TRUE)
        || !in_array($nid, $managedIds, TRUE)) {
        $this->logger->warning(
          'Organiser event bulk action rejected nid @nid for uid @uid.',
          [
            '@nid' => (string) $nid,
            '@uid' => (string) $this->currentUser->id(),
          ],
        );
        $form_state->setErrorByName(
          'events',
          $this->t('Your selection could not be validated. Refresh and try again.'),
        );
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $publish = $form_state->getValue('action') === 'publish';
    $updated = 0;
    $failed = 0;
    $storage = $this->entityTypeManager->getStorage('node');

    foreach ($this->selectedEventIds($form_state) as $nid) {
      $node = $storage->load($nid);
      if (!$node instanceof NodeInterface
        || $node->bundle() !== 'event'
        || !$node->access('update', $this->currentUser, TRUE)->isAllowed()) {
        $failed++;
        continue;
      }

      try {
        $this->eventStudioSave->setNodePublishedState(
          $node,
          $this->currentUser,
          $publish,
          $publish
            ? 'Bulk publish from organiser events list.'
            : 'Bulk unpublish from organiser events list.',
        );
        $updated++;
      }
      catch (\InvalidArgumentException $e) {
        $this->messenger()->addWarning($this->t(
          'Could not publish “@event”: @reason',
          [
            '@event' => (string) $node->label(),
            '@reason' => $e->getMessage(),
          ],
        ));
        $this->logger->notice(
          'Organiser event bulk publish blocked for nid @nid: @message',
          [
            '@nid' => (string) $nid,
            '@message' => $e->getMessage(),
          ],
        );
      }
      catch (\Throwable $e) {
        $failed++;
        $this->logger->warning(
          'Organiser event bulk update failed for nid @nid: @message',
          [
            '@nid' => (string) $nid,
            '@message' => $e->getMessage(),
          ],
        );
      }
    }

    if ($updated > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $updated,
        'Updated 1 event.',
        'Updated @count events.',
      ));
    }
    if ($failed > 0) {
      $this->messenger()->addWarning($this->formatPlural(
        $failed,
        '1 event could not be updated.',
        '@count events could not be updated.',
      ));
    }

    $query = $this->getRequest()->query->all();
    unset($query['page']);
    $form_state->setRedirect(
      'myeventlane_vendor.console.events',
      [],
      ['query' => $query],
    );
  }

  /**
   * Extracts positive event node IDs from submitted checkbox values.
   *
   * @return list<int>
   *   Selected event node IDs.
   */
  private function selectedEventIds(FormStateInterface $form_state): array {
    $raw = $form_state->getValue('events', []);
    if (!is_array($raw)) {
      return [];
    }
    return array_values(array_filter(
      array_map('intval', $raw),
      static fn (int $nid): bool => $nid > 0,
    ));
  }

}
