<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\VendorLoginDestinationNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Logs out a customer session so organiser login can run on /user/login.
 */
final class OrganiserLoginSwitchController extends ControllerBase {

  public function __construct(
    private readonly VendorLoginDestinationNormalizer $normalizer,
    private readonly DomainDetector $domainDetector,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.vendor_login_destination_normalizer'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('logger.channel.myeventlane_core'),
    );
  }

  /**
   * Validates destination, logs out non-organisers, redirects to login.
   */
  public function switchAccount(Request $request): TrustedRedirectResponse|RedirectResponse {
    $raw = $request->query->get('destination');
    $trusted = is_string($raw) ? $this->normalizer->trustedOrganiserDestination($raw) : NULL;
    if ($trusted === NULL) {
      $this->logger->warning('Organiser login switch rejected: invalid or non-organiser destination.');
      $uid = $this->currentUser()->id();
      return new RedirectResponse(
        Url::fromRoute('entity.user.canonical', ['user' => $uid])->toString(),
        302
      );
    }

    if ($this->currentUser()->hasPermission('access vendor console')) {
      try {
        return new TrustedRedirectResponse(
          $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor'),
          302
        );
      }
      catch (\Throwable $e) {
        $this->logger->error('Organiser switch redirect for vendor user failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        return new RedirectResponse(Url::fromRoute('<front>')->toString(), 302);
      }
    }

    user_logout();

    return new TrustedRedirectResponse(
      Url::fromRoute('user.login', [], [
        'query' => ['destination' => $trusted],
      ])->setAbsolute()->toString(),
      302
    );
  }

}
