<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for checking in an attendee (delegates to MelAttendeeCheckinManager).
 */
final class CheckInForm extends FormBase {

  public function __construct(
    private readonly MelAttendeeCheckinManager $checkinManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_checkout_flow.attendee_checkin_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_checkin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ParagraphInterface $paragraph = NULL): array {
    if (!$paragraph || $paragraph->bundle() !== 'attendee_answer') {
      $form['error'] = [
        '#markup' => $this->t('Invalid paragraph.'),
      ];
      return $form;
    }

    $form['paragraph_id'] = [
      '#type' => 'hidden',
      '#value' => $paragraph->id(),
    ];

    $checked_in = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value : FALSE;

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $checked_in ? $this->t('Undo Check In') : $this->t('Check In'),
      '#attributes' => [
        'class' => ['mel-btn', $checked_in ? 'mel-btn-warning' : 'mel-btn-primary'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $paragraph_id = $form_state->getValue('paragraph_id');
    if (!$paragraph_id) {
      return;
    }

    $paragraph_storage = $this->entityTypeManager()->getStorage('paragraph');
    $paragraph = $paragraph_storage->load($paragraph_id);

    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
      return;
    }

    // Verify access.
    $accessHandler = $this->entityTypeManager()->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->currentUser());
    if (!$access) {
      $this->messenger()->addError($this->t('Access denied.'));
      return;
    }

    // Get event.
    $accessResolver = \Drupal::service('myeventlane_checkout_paragraph.access_resolver');
    $event = $accessResolver->getEvent($paragraph);
    if (!$event instanceof NodeInterface) {
      return;
    }

    $is_checked_in = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value
      : FALSE;

    $actor = $this->currentUser();

    if (!$is_checked_in) {
      $result = $this->checkinManager->checkInForTicketParagraph(
        $paragraph,
        $event,
        $actor,
        MelAttendeeCheckinManager::SOURCE_VENDOR_LIST,
      );
      if (!$result['success'] && $result['reason'] === 'forbidden') {
        $this->messenger()->addError($this->t('Access denied.'));
        return;
      }
      if (!$result['success']) {
        $this->messenger()->addError($this->t('Check-in could not be completed.'));
        return;
      }
      $this->messenger()->addStatus($this->t('Attendee checked in successfully.'));
    }
    else {
      $result = $this->checkinManager->undoCheckInForTicketParagraph($paragraph, $event, $actor);
      if (!$result['success'] && $result['reason'] === 'forbidden') {
        $this->messenger()->addError($this->t('Access denied.'));
        return;
      }
      if (!$result['success']) {
        $this->messenger()->addError($this->t('Could not undo check-in.'));
        return;
      }
      $this->messenger()->addStatus($this->t('Check-in undone.'));
    }

    try {
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => $event->id()]));
    }
    catch (\Throwable $e) {
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event_attendees.vendor_operations', ['node' => $event->id()]));
    }
  }

}
