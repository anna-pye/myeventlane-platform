<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_pro\Form\MessageAttendeesForm;
use Drupal\myeventlane_pro\Service\ProAccessService;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for messaging event attendees (Pro feature).
 *
 * Safe v1: form structure + UI only. Sends are stubbed/logged.
 * Future: queue-based delivery to attendees of this event only.
 */
final class MessageAttendeesController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly ProAccessService $proAccess,
    private readonly FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_pro.pro_access'),
      $container->get('form_builder'),
    );
  }

  /**
   * Builds the message attendees form page.
   */
  public function message(NodeInterface $node): array {
    $this->assertVendorAccess();
    $this->assertAccess($node);

    $form = $this->formBuilder->getForm(MessageAttendeesForm::class, $node);

    $attendeesUrl = Url::fromRoute('myeventlane_vendor.console.event_attendees', [
      'event' => $node->id(),
    ])->toString();

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $this->t('Message attendees — @event', ['@event' => $node->label()]),
      'header_actions' => [
        [
          'label' => $this->t('View attendees'),
          'url' => $attendeesUrl,
          'class' => 'mel-btn--secondary',
        ],
      ],
      'body' => [
        '#theme' => 'myeventlane_pro_message_attendees',
        '#event' => $node,
        '#form' => $form,
      ],
    ]);
  }

  /**
   * Asserts the current user can message attendees of this event.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertAccess(NodeInterface $node): void {
    if ($node->bundle() !== 'event') {
      throw new AccessDeniedHttpException();
    }

    $account = $this->currentUser;
    if (!$this->proAccess->hasFeature($account, 'audience_messaging')) {
      throw new AccessDeniedHttpException('Message attendees requires Pro.');
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException();
    }

    if ((int) $node->getOwnerId() !== $uid) {
      $vendor = $this->resolveEventVendor($node);
      if (!$vendor || !$this->isVendorMember($vendor, $uid)) {
        throw new AccessDeniedHttpException();
      }
    }
  }

  /**
   * Resolves the vendor entity for an event.
   */
  private function resolveEventVendor(NodeInterface $node): ?object {
    if ($node->hasField('field_event_vendor') && !$node->get('field_event_vendor')->isEmpty()) {
      return $node->get('field_event_vendor')->entity;
    }
    if ($node->hasField('field_vendor') && !$node->get('field_vendor')->isEmpty()) {
      return $node->get('field_vendor')->entity;
    }
    return NULL;
  }

  /**
   * Checks if the user is a member of the vendor.
   */
  private function isVendorMember(object $vendor, int $uid): bool {
    if ($vendor->hasField('uid') && (int) $vendor->get('uid')->target_id === $uid) {
      return TRUE;
    }
    if ($vendor->hasField('field_vendor_users')) {
      foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
        if (isset($item['target_id']) && (int) $item['target_id'] === $uid) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
