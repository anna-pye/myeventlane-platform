<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorMessagesHubBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organiser Messages Hub (Communication Centre).
 *
 * Product language: Messages, Announcements, Reminders, Updates.
 * Never Vendor Comms / Queue / Mail plugin / Notification entity.
 */
final class VendorMessagesHubController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorMessagesHubBuilder $hubBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.messages_hub_builder'),
    );
  }

  /**
   * Renders /vendor/messages.
   */
  public function hub(): array {
    $hub = $this->hubBuilder->build();

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => (string) $this->t('Messages'),
      'body' => [
        '#theme' => 'myeventlane_vendor_messages_hub',
        '#hub' => $hub,
        '#attached' => [
          'library' => [
            'myeventlane_vendor_theme/mel_messages_hub',
          ],
          'drupalSettings' => [
            'melMessagesHub' => [
              'analytics' => $hub['analytics'] ?? [],
            ],
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 60,
      ],
    ]);
  }

}
