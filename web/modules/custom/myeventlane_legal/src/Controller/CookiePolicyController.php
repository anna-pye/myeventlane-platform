<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cookie policy and preferences page.
 */
final class CookiePolicyController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly LegalSettingsService $legalSettings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_legal.settings')
    );
  }

  /**
   * Renders the cookie policy page.
   */
  public function page(): array {
    $cookieUrl = $this->legalSettings->getCookiePolicyUrl();
    $privacyUrl = $this->legalSettings->getPrivacyUrl();

    $build = [
      '#theme' => 'myeventlane_legal_cookie_policy',
      '#cookie_policy_url' => $cookieUrl,
      '#privacy_url' => $privacyUrl,
      '#attached' => [
        'library' => ['myeventlane_legal/cookie_consent'],
        'drupalSettings' => [
          'myeventlane_legal' => [
            'preferences_url' => '/cookies/preferences',
            'cookie_policy_url' => '/cookies',
          ],
        ],
      ],
    ];

    return $build;
  }

}
