<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Entity\VenueIssue;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for reporting venue issues.
 */
class VenueIssueForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mail manager.
   */
  protected MailManagerInterface $mailManager;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The venue entity.
   */
  protected ?Venue $venue = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->logger = $container->get('logger.channel.myeventlane_venue');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_venue_issue_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $myeventlane_venue = NULL): array {
    // Get the venue from the route parameter.
    if (is_string($myeventlane_venue) || is_numeric($myeventlane_venue)) {
      $myeventlane_venue = $this->entityTypeManager->getStorage('myeventlane_venue')->load($myeventlane_venue);
    }

    if (!$myeventlane_venue instanceof Venue) {
      $this->messenger()->addError($this->t('Venue not found.'));
      return $form;
    }

    $this->venue = $myeventlane_venue;

    $form['#attributes']['class'][] = 'mel-form';
    $form['#attributes']['class'][] = 'mel-venue-issue-form';

    // Venue info header.
    $form['venue_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-venue-issue-header']],
      'title' => [
        '#markup' => '<h3>' . $this->t('Report an issue with: @venue', [
          '@venue' => $this->venue->getName(),
        ]) . '</h3>',
      ],
    ];

    // Hidden venue ID.
    $form['venue_id'] = [
      '#type' => 'hidden',
      '#value' => $this->venue->id(),
    ];

    // Issue type.
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Issue type'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        VenueIssue::TYPE_ADDRESS => $this->t('Incorrect address'),
        VenueIssue::TYPE_DUPLICATE => $this->t('Duplicate venue'),
        VenueIssue::TYPE_ACCESSIBILITY => $this->t('Accessibility issue'),
        VenueIssue::TYPE_INAPPROPRIATE => $this->t('Inappropriate content'),
        VenueIssue::TYPE_OTHER => $this->t('Other'),
      ],
      '#attributes' => ['class' => ['mel-select']],
    ];

    // Message.
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Describe the issue'),
      '#required' => TRUE,
      '#rows' => 5,
      '#attributes' => [
        'class' => ['mel-textarea'],
        'placeholder' => $this->t('Please provide details about the issue...'),
      ],
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit report'),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->venue->toUrl(),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (empty($form_state->getValue('type'))) {
      $form_state->setErrorByName('type', $this->t('Please select an issue type.'));
    }

    if (empty(trim($form_state->getValue('message')))) {
      $form_state->setErrorByName('message', $this->t('Please describe the issue.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $venueId = $form_state->getValue('venue_id');
    $venue = $this->entityTypeManager->getStorage('myeventlane_venue')->load($venueId);

    if (!$venue instanceof Venue) {
      $this->messenger()->addError($this->t('Venue not found.'));
      return;
    }

    $issueType = $form_state->getValue('type');
    $message = $form_state->getValue('message');

    // Create the venue issue entity.
    $issue = $this->entityTypeManager->getStorage('myeventlane_venue_issue')->create([
      'venue_id' => $venueId,
      'type' => $issueType,
      'message' => $message,
      'status' => VenueIssue::STATUS_OPEN,
      'uid' => $this->currentUser()->id(),
    ]);

    try {
      $issue->save();
      $this->messenger()->addStatus($this->t('Thank you for your report. We will review it shortly.'));

      // Notify admins about the new issue.
      $this->notifyAdmins($venue, $issue, $issueType, $message);

      $form_state->setRedirectUrl($venue->toUrl());
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('There was an error submitting your report. Please try again.'));
      $this->logger->error('Error saving venue issue: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Sends email notification to admins about a new venue issue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   * @param \Drupal\myeventlane_venue\Entity\VenueIssue $issue
   *   The venue issue entity.
   * @param string $issueType
   *   The issue type key.
   * @param string $message
   *   The issue message.
   */
  protected function notifyAdmins(Venue $venue, VenueIssue $issue, string $issueType, string $message): void {
    // Get admin emails - users with 'administer myeventlane venues' permission.
    $adminEmails = $this->getAdminEmails();

    if (empty($adminEmails)) {
      $this->logger->warning('No admin emails found to notify about venue issue @id', [
        '@id' => $issue->id(),
      ]);
      return;
    }

    // Get issue type label.
    $typeLabels = [
      VenueIssue::TYPE_ADDRESS => $this->t('Incorrect address'),
      VenueIssue::TYPE_DUPLICATE => $this->t('Duplicate venue'),
      VenueIssue::TYPE_ACCESSIBILITY => $this->t('Accessibility issue'),
      VenueIssue::TYPE_INAPPROPRIATE => $this->t('Inappropriate content'),
      VenueIssue::TYPE_OTHER => $this->t('Other'),
    ];
    $typeLabel = $typeLabels[$issueType] ?? $issueType;

    // Get reporter info.
    $reporter = $issue->getOwner();
    $reporterName = $reporter ? $reporter->getDisplayName() : $this->t('Anonymous');
    $reporterEmail = $reporter ? $reporter->getEmail() : '';

    // Build review URL.
    try {
      $reviewUrl = Url::fromRoute('entity.myeventlane_venue_issue.collection', [], [
        'absolute' => TRUE,
      ])->toString();
    }
    catch (\Exception $e) {
      $reviewUrl = '';
    }

    // Prepare email params.
    $params = [
      'venue_name' => $venue->getName(),
      'venue_id' => $venue->id(),
      'issue_id' => $issue->id(),
      'issue_type' => $typeLabel,
      'message' => $message,
      'reporter_name' => $reporterName,
      'reporter_email' => $reporterEmail,
      'review_url' => $reviewUrl,
    ];

    // Send email to each admin.
    foreach ($adminEmails as $email) {
      try {
        $result = $this->mailManager->mail(
          'myeventlane_venue',
          'venue_issue_reported',
          $email,
          'en',
          $params,
          NULL,
          TRUE
        );

        if (!$result['result']) {
          $this->logger->warning('Failed to send venue issue notification to @email', [
            '@email' => $email,
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Error sending venue issue notification: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Gets email addresses of users with admin permission.
   *
   * @return array
   *   Array of admin email addresses.
   */
  protected function getAdminEmails(): array {
    $emails = [];

    // Get all roles with 'administer myeventlane venues' permission.
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $adminRoles = [];

    foreach ($roles as $role) {
      if ($role->hasPermission('administer myeventlane venues') || $role->isAdmin()) {
        $adminRoles[] = $role->id();
      }
    }

    if (empty($adminRoles)) {
      return $emails;
    }

    // Get users with those roles.
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties([
        'status' => 1,
      ]);

    foreach ($users as $user) {
      $userRoles = $user->getRoles();
      if (array_intersect($userRoles, $adminRoles)) {
        $email = $user->getEmail();
        if ($email) {
          $emails[] = $email;
        }
      }
    }

    return array_unique($emails);
  }

}
