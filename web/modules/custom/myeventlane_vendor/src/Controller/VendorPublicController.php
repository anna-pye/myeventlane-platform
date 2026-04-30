<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\VendorCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public controller for Vendor directory.
 */
class VendorPublicController extends ControllerBase {

  /**
   * Constructs a VendorPublicController object.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PagerManagerInterface $pagerManager,
    protected readonly VendorCardBuilder $vendorCardBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('pager.manager'),
      $container->get('myeventlane_vendor.vendor_card_builder'),
    );
  }

  /**
   * Public organiser list.
   *
   * @return array
   *   A render array for the vendor list.
   */
  public function list(): array {
    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('name');

    $count_query = clone $query;
    $total = (int) $count_query->count()->execute();
    $pager = $this->pagerManager->createPager($total, 12);
    $ids = $query
      ->range($pager->getCurrentPage() * 12, 12)
      ->execute();

    $vendors = $ids ? $storage->loadMultiple($ids) : [];
    $cards = [];
    $cache_tags = [
      'myeventlane_vendor_list',
      'myeventlane_vendor_list:myeventlane_vendor',
      'node_list:event',
    ];

    foreach ($vendors as $vendor) {
      if ($vendor instanceof Vendor) {
        $cards[] = $this->vendorCardBuilder->build($vendor);
        $cache_tags = array_merge($cache_tags, $vendor->getCacheTags());
      }
    }

    return [
      '#theme' => 'myeventlane_vendor_list',
      '#vendors' => $cards,
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'tags' => array_values(array_unique($cache_tags)),
        'contexts' => ['route', 'url.query_args:page', 'user.permissions'],
      ],
    ];
  }

  /**
   * Title callback for vendor canonical pages.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $myeventlane_vendor
   *   The vendor entity.
   *
   * @return string|null
   *   The vendor label as page title.
   */
  public function title(Vendor $myeventlane_vendor): ?string {
    return $myeventlane_vendor->label();
  }

}
