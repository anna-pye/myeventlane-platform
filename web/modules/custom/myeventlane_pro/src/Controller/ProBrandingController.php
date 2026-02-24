<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_messaging\Form\VendorBrandConfigForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Pro-only branding settings page at /vendor/settings/branding.
 *
 * Reuses the existing VendorBrandConfigForm from myeventlane_messaging,
 * gated by vendor + pro_organiser role requirement.
 */
final class ProBrandingController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  private const PRO_ROLE = 'pro_organiser';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
    );
  }

  /**
   * Renders the branding settings form.
   */
  public function settings(): array {
    $vendor = $this->loadCurrentVendor();

    if (!$vendor) {
      return [
        '#markup' => $this->t('No vendor profile found. Please complete your vendor setup first.'),
      ];
    }

    $form = $this->formBuilder->getForm(VendorBrandConfigForm::class, $vendor);

    return [
      'intro' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-pro-branding-intro']],
        'badge' => [
          '#markup' => '<span class="mel-pro-badge" style="font-size:11px;padding:2px 8px;margin-right:8px;">PRO</span>',
        ],
        'text' => [
          '#markup' => '<span style="font-size:14px;color:#666;">' . $this->t('Customise how your brand appears in emails and ticket PDFs.') . '</span>',
        ],
      ],
      'form' => $form,
      '#attached' => [
        'library' => ['myeventlane_pro/pro'],
      ],
    ];
  }

  /**
   * Access check: requires vendor + pro_organiser roles.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $hasVendor = in_array('vendor', $account->getRoles(), TRUE);
    $hasPro = in_array(self::PRO_ROLE, $account->getRoles(), TRUE);

    return AccessResult::allowedIf($hasVendor && $hasPro)
      ->addCacheContexts(['user.roles']);
  }

  /**
   * Loads the vendor entity for the current user.
   */
  private function loadCurrentVendor(): ?object {
    $ids = $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->currentUser->id())
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->load(reset($ids));
  }

}
