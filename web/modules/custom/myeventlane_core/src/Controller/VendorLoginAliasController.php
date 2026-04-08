<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\VendorLoginDestinationNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Redirects /vendor/login to user.login (Drupal login is /user/login, not /vendor/login).
 *
 * Without this route, /vendor/login is handled as /vendor/{myeventlane_vendor} with slug
 * "login", which does not exist and yields a 404 on both public and vendor hosts.
 */
final class VendorLoginAliasController extends ControllerBase {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly VendorLoginDestinationNormalizer $normalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('myeventlane_core.vendor_login_destination_normalizer'),
    );
  }

  public function loginAlias(Request $request): TrustedRedirectResponse {
    $raw = $request->query->get('destination');
    $destination = NULL;
    if (is_string($raw) && $raw !== '') {
      $destination = $this->normalizer->trustedOrganiserDestination($raw);
    }
    if ($destination === NULL) {
      try {
        $destination = $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
      }
      catch (\Throwable $e) {
        $this->getLogger('myeventlane_core')->error(
          'Vendor login alias default destination failed: @message',
          ['@message' => $e->getMessage()]
        );
        $destination = '/vendor/dashboard';
      }
    }

    $url = Url::fromRoute('user.login', [], [
      'query' => ['destination' => $destination],
    ])->setAbsolute()->toString();

    return new TrustedRedirectResponse($url, 302);
  }

}
