<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Event Studio operational merchandise authoring (metadata-only).
 */
final class EventStudioOperationalMerchandiseForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalCapabilityStudioManager $capabilityStudioManager,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_event_studio.operational_capability_studio_manager'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('current_user'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_operational_merchandise';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $event = $this->getRouteEvent($node);
    $this->assertCanManageEvent($event);

    $document = $this->capabilityStudioManager->extractFromEvent($event);
    $merch = is_array($document['operational_merchandise'] ?? NULL)
      ? $document['operational_merchandise']
      : $this->capabilityStudioManager->emptyDocument()['operational_merchandise'];

    $form['#attributes']['class'][] = 'mel-event-studio-operational-merchandise';
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_operational_merchandise_studio';

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];
    $form['mel_studio_section'] = [
      '#type' => 'hidden',
      '#value' => 'merchandise',
    ];
    $form['mel_studio_changed'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->getChangedTime(),
    ];
    $form['mel_studio_revision'] = [
      '#type' => 'hidden',
      '#value' => (string) (int) $event->getRevisionId(),
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-operational-merch-intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Operational merchandise & packages'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Link operational Commerce products to this event. Commerce owns catalog, pricing, carts, and orders — this section stores authoring metadata only (no stock, shipping, or scanner execution).'),
        '#attributes' => ['class' => ['mel-es-card__hint']],
      ],
    ];

    $form['linked_products_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Linked operational products (JSON)'),
      '#description' => $this->t('Provide {"linked_products":[{"product_id":123,"role":"merch_pickup","bundle_group":"vip_pack"}]} — products must use operational bundles, belong to this event (field_event), and must not include forbidden keys (QR, replay, stock, warehouse, shipment, scanner tokens).'),
      '#default_value' => json_encode(['linked_products' => $merch['linked_products'] ?? []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 12,
      '#required' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save operational merchandise'),
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }
    $baseChanged = (int) ($form_state->getValue('mel_studio_changed') ?? 0);
    $baseRevisionId = (int) ($form_state->getValue('mel_studio_revision') ?? 0);
    if ($this->autosaveService->isStaleSubmission($event, $baseChanged, $baseRevisionId)) {
      $form_state->setErrorByName('', $this->t('This section was updated elsewhere. Refresh to continue editing safely.'));
      return;
    }
    $raw = trim((string) $form_state->getValue('linked_products_json'));
    if ($raw === '') {
      return;
    }
    try {
      json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $form_state->setErrorByName('linked_products_json', $this->t('Invalid JSON: @message', ['@message' => $e->getMessage()]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }
    try {
      $base = $this->capabilityStudioManager->extractFromEvent($event);
      $raw = trim((string) $form_state->getValue('linked_products_json'));
      $incoming = $raw === '' ? ['linked_products' => []] : json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($incoming)) {
        throw new \InvalidArgumentException('Merchandise JSON must decode to an object.');
      }
      $base['operational_merchandise'] = [
        'schema_version' => 1,
        'linked_products' => is_array($incoming['linked_products'] ?? NULL) ? $incoming['linked_products'] : [],
      ];
      $this->capabilityStudioManager->persistToEvent($event, $base);
      EventNodeRevisionSave::prepare($event, 'Event Studio operational merchandise authoring save.');
      $event->save();
      $this->autosaveService->clearDraft($event, 'merchandise');
      $this->messenger()->addStatus($this->t('Operational merchandise authoring saved.'));
    }
    catch (\Throwable $e) {
      $this->logger->error('Operational merchandise save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not save operational merchandise authoring.'));
    }
    $form_state->setRebuild(TRUE);
  }

  private function getRouteEvent(?NodeInterface $node = NULL): NodeInterface {
    if ($node instanceof NodeInterface) {
      return $node;
    }
    $route_node = $this->getRouteMatch()->getParameter('node');
    if ($route_node instanceof NodeInterface) {
      return $route_node;
    }
    throw new NotFoundHttpException();
  }

  private function assertCanManageEvent(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventVendorAccessChecker->access($this->currentUser, $event)) {
      throw new AccessDeniedHttpException();
    }
  }

  private function loadSubmittedEvent(FormStateInterface $form_state): ?NodeInterface {
    $event_id = (int) ($form_state->getValue('event_id') ?? 0);
    if ($event_id < 1) {
      return NULL;
    }
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

}
