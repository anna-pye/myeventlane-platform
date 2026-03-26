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
use Drupal\views\Entity\View as ViewEntity;
use Drupal\views\ViewExecutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for listing vendor events with bulk actions functionality.
 *
 * Event rows are driven by the "mel_vendor_events" View (single source of
 * truth for filters/sorts) and rendered with the "vendor_card" view mode.
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
   * Logger for the myeventlane_vendor channel.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the form.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->setMessenger($messenger);
    $this->logger = $logger;
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
    $form['#attributes']['class'][] = 'mel-vendor-events-form';
    $form['#create_event_url'] = Url::fromRoute('myeventlane_event.wizard.create')->toString();
    $form['#attached']['library'][] = 'myeventlane_vendor_theme/mel_vendor_events';

    $executable = $this->loadVendorEventsViewExecutable();
    if ($executable === NULL) {
      $this->logger->error('View "mel_vendor_events" is missing or invalid; vendor events list cannot be built.');
      $form['fatal'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Events could not be loaded. Please try again later.'),
        '#attributes' => ['class' => ['messages', 'messages--error']],
      ];
      return $form;
    }

    $executable->execute();
    $nodes = $this->collectNodesFromViewResult($executable);

    $display = $executable->getDisplay();
    $cache_metadata = $display->getCacheMetadata();
    $form['#cache']['tags'] = $cache_metadata->getCacheTags();
    $form['#cache']['contexts'] = $cache_metadata->getCacheContexts();
    $max_age = $cache_metadata->getCacheMaxAge();
    if ($max_age !== NULL) {
      $form['#cache']['max-age'] = $max_age;
    }

    if (empty($nodes)) {
      $form['empty_state'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-empty--events-inner']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('No events yet'),
          '#attributes' => ['class' => ['mel-empty__title']],
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Create your first event and start selling tickets.'),
          '#attributes' => ['class' => ['mel-empty__text']],
        ],
        'action' => [
          '#type' => 'link',
          '#title' => $this->t('Create your first event'),
          '#url' => Url::fromRoute('myeventlane_event.wizard.create'),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ],
      ];
      return $form;
    }

    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');

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

    $form['events_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-events-bulk-list'],
      ],
    ];

    foreach ($nodes as $nid => $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $nid = (int) $nid;
      $form['events_wrapper'][$nid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-vendor-event-row']],
        'select' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Select @title', ['@title' => $node->label()]),
          '#title_display' => 'invisible',
          '#return_value' => $nid,
          '#parents' => ['events', $nid],
        ],
        'card' => $viewBuilder->view($node, 'vendor_card'),
      ];
      $form['events_wrapper'][$nid]['select']['#weight'] = -10;
    }

    $form['#attached']['library'][] = 'myeventlane_vendor/bulk_actions';

    return $form;
  }

  /**
   * Loads the executable for the canonical vendor events View.
   */
  private function loadVendorEventsViewExecutable(): ?ViewExecutable {
    $storage = $this->entityTypeManager->getStorage('view');
    $view = $storage->load('mel_vendor_events');
    if (!$view instanceof ViewEntity) {
      return NULL;
    }
    $executable = $view->getExecutable();
    if (!$executable instanceof ViewExecutable) {
      return NULL;
    }
    $executable->initDisplay();
    $executable->setDisplay('default');
    return $executable;
  }

  /**
   * Collects event nodes from a executed view result set.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Keyed by node ID.
   */
  private function collectNodesFromViewResult(ViewExecutable $executable): array {
    $out = [];
    foreach ($executable->result as $row) {
      if (!isset($row->_entity)) {
        continue;
      }
      $entity = $row->_entity;
      if ($entity instanceof NodeInterface && $entity->bundle() === 'event') {
        $out[(int) $entity->id()] = $entity;
      }
    }
    return $out;
  }

  /**
   * Returns allowed event node IDs from the view (authoritative for access).
   *
   * @return array<int, int>
   */
  private function getAllowedEventNidsFromView(): array {
    $executable = $this->loadVendorEventsViewExecutable();
    if ($executable === NULL) {
      return [];
    }
    $executable->execute();
    $nodes = $this->collectNodesFromViewResult($executable);
    return array_map(static fn (NodeInterface $node): int => (int) $node->id(), $nodes);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $action = $form_state->getValue('action');
    $selectedRaw = $form_state->getValue('events') ?? [];
    $selectedEvents = array_filter(
      is_array($selectedRaw) ? $selectedRaw : [],
      static fn($v): bool => $v !== 0 && $v !== '0' && $v !== '',
    );

    if (empty($action)) {
      $form_state->setErrorByName('action', $this->t('Please select an action.'));
      return;
    }

    if (empty($selectedEvents)) {
      $form_state->setErrorByName('events', $this->t('Please select at least one event.'));
      return;
    }

    $allowed = array_flip($this->getAllowedEventNidsFromView());
    foreach (array_keys($selectedEvents) as $nid) {
      $nid = (int) $nid;
      if (!isset($allowed[$nid])) {
        $this->logger->warning('Vendor events bulk: rejected selection for disallowed nid @nid.', ['@nid' => (string) $nid]);
        $form_state->setErrorByName('events', $this->t('Your selection could not be validated. Please refresh and try again.'));
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $action = $form_state->getValue('action');
    $selectedRaw = $form_state->getValue('events') ?? [];
    $selectedEvents = array_filter(
      is_array($selectedRaw) ? $selectedRaw : [],
      static fn($v): bool => $v !== 0 && $v !== '0' && $v !== '',
    );

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

    $form_state->setRedirect('myeventlane_vendor.console.events');
  }

  /**
   * Handle bulk delete action.
   */
  private function handleDelete(array $selectedEvents, int $userId, $nodeStorage): void {
    $deletedCount = 0;

    foreach (array_keys($selectedEvents) as $nodeId) {
      $nodeId = (int) $nodeId;
      $node = $nodeStorage->load($nodeId);

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

    foreach (array_keys($selectedEvents) as $nodeId) {
      $nodeId = (int) $nodeId;
      $node = $nodeStorage->load($nodeId);

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
