<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Form\EventStudioForm;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Event Studio pages. Route methods are buildCreate / buildEdit (not create — conflicts with ControllerBase::create()).
 */
final class EventStudioController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly VendorEventStudioCreateService $eventStudioCreate,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {
    // ControllerBase / EntityTypeManagerTrait already declare protected $entityTypeManager.
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.event_studio_create'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
      $container->get('request_stack'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  public function buildCreate(): RedirectResponse|array {
    $uid = (int) $this->currentUser()->id();
    $this->logger->notice('Vendor Event Studio entry: route=myeventlane_event_studio.create studio_selected=1 uid=@uid', [
      '@uid' => (string) $uid,
    ]);

    $request = $this->requestStack->getCurrentRequest();
    $destination = '/vendor/events/create';
    if ($request !== NULL && $request->query->count() > 0) {
      $destination .= '?' . http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986);
    }

    $draft_nid = $this->eventStudioCreate->findLatestUnpublishedEventNidForUser($uid);
    if ($draft_nid !== NULL) {
      $route_params = ['node' => $draft_nid];
      $url_options = [];
      if ($request !== NULL && $request->query->count() > 0) {
        $url_options['query'] = $request->query->all();
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.edit', $route_params, $url_options)->toString());
    }

    try {
      $storage = $this->entityTypeManager()->getStorage('node');
      /** @var \Drupal\node\NodeInterface $node */
      $node = $storage->create([
        'type' => 'event',
        'title' => $this->t('Untitled event'),
        'uid' => $uid,
        'status' => 0,
      ]);
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio create: failed to persist draft event uid=@uid @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    $new_id = (int) $node->id();
    $url_options = [];
    if ($request !== NULL && $request->query->count() > 0) {
      $url_options['query'] = $request->query->all();
    }
    $this->logger->notice('Event Studio create: new draft node @nid redirect to edit uid=@uid', [
      '@nid' => (string) $new_id,
      '@uid' => (string) $uid,
    ]);

    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.edit', ['node' => $new_id], $url_options)->toString());
  }

  public function buildEdit(NodeInterface $node): array {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      throw new AccessDeniedHttpException();
    }
    $this->logger->notice('Vendor Event Studio entry: route=myeventlane_event_studio.edit event_id=@eid uid=@uid', [
      '@eid' => (string) $node->id(),
      '@uid' => (string) $this->currentUser()->id(),
    ]);
    $form = $this->formBuilder()->getForm(EventStudioForm::class, $node);
    $form['#theme_wrappers'] = [];
    $form['#theme'] = 'mel_event_studio';
    $form['#mel_studio_mode'] = 'edit';
    $form['#attached']['library'] ??= [];
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_studio';
    return $form;
  }

  public function editTitle(NodeInterface $node): string {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    return (string) $this->t('Edit event — @title', ['@title' => $node->getTitle()]);
  }

}
