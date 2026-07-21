<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_pro\Form\MessageAttendeesForm;
use Drupal\myeventlane_pro\Service\ProAccessService;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
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
    private readonly MelAttendeeOperationsAccessInterface $attendeeOperationsAccess,
    ?EventVendorAccessCheckerInterface $eventVendorAccessChecker = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger, $eventVendorAccessChecker);
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
      $container->get('myeventlane_checkout_flow.attendee_operations_access'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Builds the message attendees form page.
   */
  public function message(NodeInterface $node): array {
    $this->assertVendorAccess();
    $this->assertAccess($node);

    $form = $this->formBuilder->getForm(MessageAttendeesForm::class, $node);

    $attendeesUrl = Url::fromRoute('myeventlane_event_attendees.vendor_list', [
      'node' => $node->id(),
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
   * Pro product gate preserved; organiser membership via
   * MelAttendeeOperationsAccess → EventVendorAccessChecker.
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

    if (!$this->attendeeOperationsAccess->accountHasOrganiserOwnership($node, $account)) {
      throw new AccessDeniedHttpException();
    }
  }

}
