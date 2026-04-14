<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Placeholder for future email-first / magic-link entry; links into core login.
 */
final class MelEmailEntryPlaceholderController extends ControllerBase {

  /**
   * Minimal UI: sign in with optional destination + intent preserved via mel_continue.
   */
  public function build(): array {
    $continue = Url::fromRoute('myeventlane_auth.mel_continue', [], [
      'absolute' => TRUE,
    ])->toString();

    return [
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Magic links and OTP can plug in here later. For now use password login.'),
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Sign in with password'),
        '#url' => Url::fromRoute('user.login', [], [
          'query' => ['destination' => $continue],
        ]),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
