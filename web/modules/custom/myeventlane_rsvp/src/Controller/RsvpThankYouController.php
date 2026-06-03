<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_rsvp\Service\RsvpMailer;
use Drupal\myeventlane_surface\MelCustomerContinuityPresenter;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RSVP Thank You controller.
 */
final class RsvpThankYouController {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly RsvpMailer $mailer,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountProxyInterface $currentUser,
    private readonly MelCustomerContinuityPresenter $customerContinuityPresenter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('myeventlane_rsvp.mailer'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('myeventlane_surface.customer_continuity_presenter'),
    );
  }

  public function page(RouteMatchInterface $route_match): array {
    $event_param = $route_match->getParameter('event');
    if (!$event_param) {
      throw new NotFoundHttpException();
    }

    $event = $event_param instanceof NodeInterface
      ? $event_param
      : $this->entityTypeManager->getStorage('node')->load((int) $event_param);

    if (!$event instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }

    $logger = $this->loggerFactory->get('myeventlane_rsvp');

    $request = $this->requestStack->getCurrentRequest();
    $order_id = (int) ($request?->query->get('order') ?? 0);

    $submission = NULL;
    $donation = 0.0;
    $donation_order_id = NULL;
    $donation_checkout_url = NULL;
    $donation_completed = FALSE;
    $donation_pending = FALSE;
    $can_add_post_rsvp_contribution = FALSE;

    if ($order_id > 0) {
      $order = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($order_id);

      if ($order instanceof OrderInterface && $order->bundle() === 'rsvp_donation') {
        $context = $this->extractDonationContextFromOrder($order);
        if ($context['event_id'] !== NULL && (int) $context['event_id'] !== (int) $event->id()) {
          $logger->warning('Ignoring RSVP donation order @order_id on mismatched event @event_id (current @current_event_id).', [
            '@order_id' => (int) $order->id(),
            '@event_id' => (int) $context['event_id'],
            '@current_event_id' => (int) $event->id(),
          ]);
        }
        else {
          $submission = $context['submission'];
          if ($context['donation'] > 0.0) {
            $donation = $context['donation'];
          }
          $donation_order_id = (int) $order->id();
          $donation_completed = $this->isDonationOrderCompleted($order);
          $donation_pending = $this->isDonationOrderPending($order);
          if ($donation_pending) {
            $donation_checkout_url = $this->buildDonationCheckoutUrl($order);
          }
        }
      }
    }

    if (!$submission instanceof EntityInterface) {
      $submission = $this->resolveLatestSubmissionForCurrentUser($event);
    }

    $attendee_name = '';
    $attendee_email = '';
    $donation = (float) $donation;
    $guests = 1;
    if ($submission instanceof EntityInterface) {
      $attendee_name = $submission->hasField('name') ? (string) ($submission->get('name')->value ?? '') : '';
      $attendee_email = $submission->hasField('email') ? (string) ($submission->get('email')->value ?? '') : '';
      $submissionDonation = $submission->hasField('donation')
        ? (float) ($submission->get('donation')->value ?? 0)
        : 0.0;
      if ($submissionDonation > 0) {
        $donation = $submissionDonation;
      }
      $guests = $submission->hasField('quantity')
        ? max(1, (int) ($submission->get('quantity')->value ?? 1))
        : ($submission->hasField('guests') ? max(1, (int) ($submission->get('guests')->value ?? 1)) : 1);
    }

    if ($submission instanceof EntityInterface
      && $donation_order_id === NULL
      && $donation > 0.0
    ) {
      $recovered_order = $this->findLatestDonationOrderForSubmission((int) $submission->id(), (int) $event->id());
      if ($recovered_order instanceof OrderInterface) {
        $donation_order_id = (int) $recovered_order->id();
        $donation_completed = $this->isDonationOrderCompleted($recovered_order);
        $donation_pending = $this->isDonationOrderPending($recovered_order);
        if ($donation_pending) {
          $donation_checkout_url = $this->buildDonationCheckoutUrl($recovered_order);
        }
      }
    }

    if ($donation <= 0.0) {
      $donation_completed = FALSE;
      $donation_pending = FALSE;
    }
    elseif ($donation > 0.0 && !$donation_completed && $donation_order_id !== NULL) {
      $pending_order = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($donation_order_id);
      if ($pending_order instanceof OrderInterface && $pending_order->bundle() === 'rsvp_donation') {
        $donation_pending = $this->isDonationOrderPending($pending_order);
        if ($donation_pending && ($donation_checkout_url === NULL || $donation_checkout_url === '')) {
          $donation_checkout_url = $this->buildDonationCheckoutUrl($pending_order);
        }
      }
    }

    if ($donation_completed && $submission instanceof EntityInterface && $submission->hasField('status')) {
      $current = (string) $submission->get('status')->value;
      if ($current !== 'confirmed') {
        $submission->set('status', 'confirmed');
        $submission->save();
        try {
          $this->mailer->sendConfirmation($submission, $event);
        }
        catch (\Throwable $e) {
          $logger->warning('RSVP email failed after checkout: @msg', [
            '@msg' => $e->getMessage(),
          ]);
        }
      }
    }
    if ($donation <= 0.0 && !$can_add_post_rsvp_contribution) {
      $logger->notice('Post-confirmation donation creation path is not available for RSVP thank-you upsell.');
    }

    $cancel_url = '';
    if ($submission instanceof EntityInterface) {
      $cancel_url = Url::fromRoute('myeventlane_rsvp.cancel_confirm', [
        'rsvp_id' => (int) $submission->id(),
      ])->toString();
    }

    $mel_continuity = $this->customerContinuityPresenter->buildRsvpThankYouPresentation(
      $event,
      $event->toUrl()->toString(),
      $donation_pending,
      $donation_completed,
      $donation,
      $donation_checkout_url,
      $cancel_url,
      $can_add_post_rsvp_contribution,
      $attendee_name,
      $attendee_email,
      $guests,
    );

    return [
      '#theme' => 'mel_rsvp_thankyou',
      '#event' => $event,
      '#submission' => $submission,
      '#attendee_name' => $attendee_name,
      '#attendee_email' => $attendee_email,
      '#donation' => $donation,
      '#guests' => $guests,
      '#event_url' => $event->toUrl()->toString(),
      '#checkout_url' => $donation_checkout_url ?? '',
      '#is_payment_pending' => $donation_pending,
      '#donation_order_id' => $donation_order_id,
      '#donation_checkout_url' => $donation_checkout_url,
      '#donation_completed' => $donation_completed,
      '#donation_pending' => $donation_pending,
      '#can_add_post_rsvp_contribution' => $can_add_post_rsvp_contribution,
      '#cancel_url' => $cancel_url,
      '#mel_continuity' => $mel_continuity,
      '#attached' => [
        'library' => [
          'myeventlane_rsvp/rsvp-thankyou',
        ],
      ],
      '#cache' => [
        'contexts' => ['url', 'url.query_args:order', 'user'],
        'tags' => $event->getCacheTags(),
      ],
    ];
  }

  private function resolveLatestSubmissionForCurrentUser(NodeInterface $event): ?EntityInterface {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('rsvp_submission');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', (int) $event->id())
      ->condition('user_id', $uid)
      ->condition('status', 'cancelled', '<>')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $id = (int) reset($ids);
    $entity = $storage->load($id);
    return $entity instanceof EntityInterface ? $entity : NULL;
  }

  private function extractDonationContextFromOrder(OrderInterface $order): array {
    $donation = 0.0;
    $submission = NULL;
    $event_id = NULL;
    $submission_id = 0;

    foreach ($order->getItems() as $item) {
      if ((string) $item->bundle() !== 'rsvp_donation') {
        continue;
      }

      $unitPrice = $item->getUnitPrice();
      if ($unitPrice) {
        $donation += (float) $unitPrice->getNumber();
      }

      if ($item->hasField('field_attendee_data') && !$item->get('field_attendee_data')->isEmpty()) {
        $raw = (string) $item->get('field_attendee_data')->value;
        $data = json_decode($raw, TRUE);
        if ($submission_id === 0 && isset($data['rsvp_submission_id'])) {
          $submission_id = (int) $data['rsvp_submission_id'];
        }
        if ($event_id === NULL && isset($data['event_id'])) {
          $event_id = (int) $data['event_id'];
        }
      }
    }

    if ($submission_id > 0) {
      $loaded = $this->entityTypeManager->getStorage('rsvp_submission')->load($submission_id);
      if ($loaded instanceof EntityInterface) {
        $submission = $loaded;
      }
    }

    return [
      'donation' => $donation,
      'submission' => $submission,
      'event_id' => $event_id,
    ];
  }

  private function isDonationOrderCompleted(OrderInterface $order): bool {
    $state = (string) ($order->getState()->getId() ?? $order->getState()->value ?? 'draft');
    if ($state === 'completed') {
      return TRUE;
    }

    $totalPrice = $order->getTotalPrice();
    $totalPaid = $order->getTotalPaid();
    if ($totalPrice && $totalPaid) {
      return ((float) $totalPaid->getNumber()) >= ((float) $totalPrice->getNumber());
    }

    return FALSE;
  }

  private function buildDonationCheckoutUrl(OrderInterface $order): ?string {
    if (!$this->moduleHandler->moduleExists('commerce_checkout')) {
      return NULL;
    }

    return Url::fromRoute('commerce_checkout.form', [
      'commerce_order' => (int) $order->id(),
      'step' => 'checkout',
    ])->toString();
  }

  private function isDonationOrderPending(OrderInterface $order): bool {
    $state = (string) ($order->getState()->getId() ?? $order->getState()->value ?? 'draft');
    if (in_array($state, ['completed', 'canceled'], TRUE)) {
      return FALSE;
    }
    return !$this->isDonationOrderCompleted($order);
  }

  private function findLatestDonationOrderForSubmission(int $submission_id, int $event_id): ?OrderInterface {
    if ($submission_id <= 0) {
      return NULL;
    }

    $logger = $this->loggerFactory->get('myeventlane_rsvp');
    $field_storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('commerce_order_item.field_rsvp_submission');

    if ($field_storage) {
      $orders = $this->findDonationOrdersForSubmissionReference($submission_id, $event_id);
      if ($orders !== []) {
        return $this->selectPreferredDonationOrder($orders);
      }
      return NULL;
    }

    $logger->warning(
      'RSVP donation recovery: commerce_order_item.field_rsvp_submission is missing; attempting legacy field_attendee_data lookup.'
    );

    return $this->findLatestDonationOrderForSubmissionLegacy($submission_id, $event_id);
  }

  /**
   * Finds rsvp_donation orders linked to a submission via field_rsvp_submission.
   *
   * @return list<\Drupal\commerce_order\Entity\OrderInterface>
   */
  private function findDonationOrdersForSubmissionReference(int $submission_id, int $event_id): array {
    $item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $item_ids = $item_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'rsvp_donation')
      ->condition('field_rsvp_submission', $submission_id)
      ->sort('changed', 'DESC')
      ->execute();

    if ($item_ids === []) {
      return [];
    }

    /** @var list<\Drupal\commerce_order\Entity\OrderInterface> $orders */
    $orders = [];
    $seen_order_ids = [];
    foreach ($item_storage->loadMultiple($item_ids) as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      if (!$this->donationOrderItemMatchesEvent($item, $event_id)) {
        continue;
      }
      $order = $item->getOrder();
      if (!$order instanceof OrderInterface || $order->bundle() !== 'rsvp_donation') {
        continue;
      }
      $order_id = (int) $order->id();
      if (isset($seen_order_ids[$order_id])) {
        continue;
      }
      $seen_order_ids[$order_id] = TRUE;
      $orders[] = $order;
    }

    return $orders;
  }

  /**
   * Prefers a draft/pending donation order so the user can complete payment.
   */
  private function selectPreferredDonationOrder(array $orders): ?OrderInterface {
    if ($orders === []) {
      return NULL;
    }

    foreach ($orders as $order) {
      if ($this->isDonationOrderPending($order)) {
        return $order;
      }
    }

    foreach ($orders as $order) {
      if ($this->isDonationOrderCompleted($order)) {
        return $order;
      }
    }

    return $orders[0];
  }

  private function donationOrderItemMatchesEvent(OrderItemInterface $item, int $event_id): bool {
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      return (int) $item->get('field_target_event')->target_id === $event_id;
    }

    return TRUE;
  }

  /**
   * Legacy recovery via JSON in field_attendee_data when field_rsvp_submission is absent.
   */
  private function findLatestDonationOrderForSubmissionLegacy(int $submission_id, int $event_id): ?OrderInterface {
    $logger = $this->loggerFactory->get('myeventlane_rsvp');
    $field_storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('commerce_order_item.field_attendee_data');
    if (!$field_storage) {
      $logger->warning(
        'RSVP donation recovery failed: commerce_order_item.field_rsvp_submission and field_attendee_data are both missing.'
      );
      return NULL;
    }

    $item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $ids = $item_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'rsvp_donation')
      ->condition('field_attendee_data', '"rsvp_submission_id":' . $submission_id, 'CONTAINS')
      ->sort('changed', 'DESC')
      ->range(0, 10)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $orders = [];
    $seen_order_ids = [];
    foreach ($item_storage->loadMultiple($ids) as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      $order = $item->getOrder();
      if (!$order instanceof OrderInterface || $order->bundle() !== 'rsvp_donation') {
        continue;
      }
      $context = $this->extractDonationContextFromOrder($order);
      if ($context['event_id'] !== NULL && (int) $context['event_id'] !== $event_id) {
        continue;
      }
      $order_id = (int) $order->id();
      if (isset($seen_order_ids[$order_id])) {
        continue;
      }
      $seen_order_ids[$order_id] = TRUE;
      $orders[] = $order;
    }

    return $this->selectPreferredDonationOrder($orders);
  }

}