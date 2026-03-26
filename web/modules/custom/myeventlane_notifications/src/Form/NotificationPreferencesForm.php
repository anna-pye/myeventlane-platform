<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\NotificationSurface;
use Drupal\myeventlane_notifications\Service\NotificationPreferenceService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-user notification preferences (stored as JSON on the user entity).
 */
final class NotificationPreferencesForm extends FormBase {

  public function __construct(
    private readonly NotificationPreferenceService $preferenceService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_notifications.preference'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_notifications_preferences';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->currentUser()->getAccount();
    if (!$account->isAuthenticated()) {
      return $form;
    }
    /** @var \Drupal\user\UserInterface $user */
    $user = User::load($account->id());
    if ($user === NULL) {
      return $form;
    }

    $prefs = $this->preferenceService->getPreferences($user);

    $surface_options = [
      NotificationSurface::TOAST_INBOX => $this->t('Toast + Inbox (most visible)'),
      NotificationSurface::BELL_INBOX => $this->t('Bell + Inbox'),
      NotificationSurface::INBOX_ONLY => $this->t('Inbox only (quietest)'),
    ];

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p class="mel-notif-prefs__intro">' . $this->t(
        'Choose what we surface in the header bell, toasts, and your inbox. Ticket confirmations always remain available in your inbox even if you turn off ticket alerts.'
      ) . '</p>',
    ];

    $categories = [
      NotificationPreferenceService::CATEGORY_TICKETS => $this->t('Tickets'),
      NotificationPreferenceService::CATEGORY_EVENTS => $this->t('Events'),
      NotificationPreferenceService::CATEGORY_REMINDERS => $this->t('Reminders'),
      NotificationPreferenceService::CATEGORY_PLATFORM => $this->t('Platform'),
      NotificationPreferenceService::CATEGORY_PROMO => $this->t('Promotions'),
    ];

    // #tree is required so each category gets distinct #parents (e.g.
    // category → tickets → surface). Without it, Drupal flattens #parents to
    // ['surface'] / ['enabled'] for every section, merging all radios into one
    // group and colliding checkbox values so preferences cannot save correctly.
    $form['category'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($categories as $key => $label) {
      $form['category'][$key] = [
        '#type' => 'fieldset',
        '#title' => $label,
        '#attributes' => ['class' => ['mel-notif-prefs__fieldset']],
      ];
      $form['category'][$key]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable notifications in this category'),
        '#default_value' => !empty($prefs[$key]['enabled']),
      ];
      $form['category'][$key]['surface'] = [
        '#type' => 'radios',
        '#title' => $this->t('How they appear when enabled'),
        '#options' => $surface_options,
        '#default_value' => $prefs[$key]['surface'] ?? NotificationSurface::INBOX_ONLY,
        '#states' => [
          'visible' => [
            ':input[name="category[' . $key . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      $form['category'][$key]['help'] = [
        '#type' => 'markup',
        '#markup' => '<p class="description">' . $this->t(
          'Toast appears briefly on screen. Bell shows a badge and dropdown preview. Inbox keeps everything on the notifications page.'
        ) . '</p>',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save preferences'),
      '#button_type' => 'primary',
    ];

    $form['#attributes']['class'][] = 'mel-notif-prefs';
    $form['#attached']['library'][] = 'myeventlane_notifications/user_experience';

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser()->getAccount();
    if (!$account->isAuthenticated()) {
      return;
    }
    /** @var \Drupal\user\UserInterface|null $user */
    $user = User::load($account->id());
    if ($user === NULL || !$user->hasField(NotificationPreferenceService::FIELD_NAME)) {
      $this->messenger()->addError($this->t('Could not update preferences.'));
      return;
    }

    $out = [];
    foreach (NotificationPreferenceService::categoryKeys() as $key) {
      $enabled = (bool) $form_state->getValue(['category', $key, 'enabled']);
      $surface = (string) $form_state->getValue(['category', $key, 'surface']);
      if (!in_array($surface, NotificationSurface::allowed(), TRUE)) {
        $surface = NotificationSurface::INBOX_ONLY;
      }
      $out[$key] = [
        'enabled' => $enabled,
        'surface' => $surface,
      ];
    }

    try {
      $json = json_encode($out, JSON_THROW_ON_ERROR);
      $user->set(NotificationPreferenceService::FIELD_NAME, $json);
      $user->save();
      $this->messenger()->addStatus($this->t('Your notification preferences were saved.'));
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_notifications')->error('Preference save failed for user @uid: @message', [
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not save preferences. Please try again.'));
    }

    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_notifications.preferences'));
  }

}
