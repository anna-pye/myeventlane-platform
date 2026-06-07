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
        'Choose what we surface in the header bell, toasts, and your inbox. Preferences are grouped by personal, business, and platform context.'
      ) . '</p>',
    ];

    $sections = [
      'personal' => [
        '#title' => $this->t('Personal'),
        'categories' => [
          NotificationPreferenceService::PERSONAL_TICKET_PURCHASES => $this->t('Ticket purchases'),
          NotificationPreferenceService::PERSONAL_ORDER_UPDATES => $this->t('Order updates'),
          NotificationPreferenceService::PERSONAL_EVENT_REMINDERS => $this->t('Event reminders'),
        ],
      ],
      'business' => [
        '#title' => $this->t('Business'),
        'categories' => [
          NotificationPreferenceService::BUSINESS_SALES => $this->t('Sales'),
          NotificationPreferenceService::BUSINESS_REFUNDS => $this->t('Refunds'),
          NotificationPreferenceService::BUSINESS_RSVPS => $this->t('RSVPs'),
          NotificationPreferenceService::BUSINESS_FOLLOWERS => $this->t('Followers'),
          NotificationPreferenceService::BUSINESS_EVENT_UPDATES => $this->t('Event updates'),
          NotificationPreferenceService::BUSINESS_BOOSTS => $this->t('Boost activity'),
        ],
      ],
      'platform' => [
        '#title' => $this->t('Platform'),
        'categories' => [
          NotificationPreferenceService::PLATFORM_SECURITY => $this->t('Security'),
          NotificationPreferenceService::PLATFORM_ACCOUNT => $this->t('Account'),
          NotificationPreferenceService::PLATFORM_SYSTEM => $this->t('System alerts'),
        ],
      ],
    ];

    $form['category'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($sections as $sectionKey => $section) {
      $form['category'][$sectionKey] = [
        '#type' => 'details',
        '#title' => $section['#title'],
        '#open' => TRUE,
        '#attributes' => ['class' => ['mel-notif-prefs__section']],
      ];
      foreach ($section['categories'] as $key => $label) {
        $form['category'][$sectionKey][$key] = [
          '#type' => 'fieldset',
          '#title' => $label,
          '#attributes' => ['class' => ['mel-notif-prefs__fieldset']],
        ];
        $form['category'][$sectionKey][$key]['enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable notifications in this category'),
          '#default_value' => !empty($prefs[$key]['enabled']),
        ];
        $form['category'][$sectionKey][$key]['surface'] = [
          '#type' => 'radios',
          '#title' => $this->t('How they appear when enabled'),
          '#options' => $surface_options,
          '#default_value' => $prefs[$key]['surface'] ?? NotificationSurface::INBOX_ONLY,
          '#states' => [
            'visible' => [
              ':input[name="category[' . $sectionKey . '][' . $key . '][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
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
    $categoryValues = $form_state->getValue('category');
    if (!is_array($categoryValues)) {
      $categoryValues = [];
    }
    foreach (NotificationPreferenceService::categoryKeys() as $key) {
      $enabled = TRUE;
      $surface = NotificationSurface::INBOX_ONLY;
      foreach (['personal', 'business', 'platform'] as $section) {
        if (!isset($categoryValues[$section][$key]) || !is_array($categoryValues[$section][$key])) {
          continue;
        }
        $entry = $categoryValues[$section][$key];
        $enabled = (bool) ($entry['enabled'] ?? TRUE);
        $surface = (string) ($entry['surface'] ?? NotificationSurface::INBOX_ONLY);
        break;
      }
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
