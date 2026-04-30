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
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Form\VendorBrandingForm;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Pro-only branding settings page at /vendor/settings/branding.
 *
 * Reuses the vendor branding form from myeventlane_messaging,
 * gated by vendor role and canonical Pro entitlement resolver.
 */
final class ProBrandingController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ProActiveResolver $proActiveResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('myeventlane_pro.active_resolver'),
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

    $form = $this->formBuilder->getForm(
      VendorBrandingForm::class,
      $vendor,
      Url::fromRoute('myeventlane_pro.branding')->toString(),
    );

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
   * Access check: requires vendor role + active Pro entitlement.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $hasVendor = in_array('vendor', $account->getRoles(), TRUE);
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    $hasPro = $user instanceof UserInterface && $this->proActiveResolver->isUserProActive($user);

    return AccessResult::allowedIf($hasVendor && $hasPro)
      ->addCacheContexts(['user.roles'])
      ->addCacheTags(['user:' . (int) $account->id()]);
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
