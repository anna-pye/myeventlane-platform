<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\myeventlane_page_visuals\Service\PageVisualMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete form for Page Visual config entities.
 */
final class PageVisualDeleteForm extends EntityConfirmFormBase {

  /**
   * Page visual media and file helper.
   *
   * @var \Drupal\myeventlane_page_visuals\Service\PageVisualMediaManager
   */
  protected PageVisualMediaManager $pageVisualMediaManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->pageVisualMediaManager = $container->get('myeventlane.page_visual_media_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to delete the page visual %label?', [
      '%label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('entity.myeventlane_page_visual.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity = $this->entity;
    $this->pageVisualMediaManager->releaseFileUsageForVisual(
      (string) $entity->id(),
      $entity->getMediaUuidDesktop(),
      $entity->getMediaUuidMobile(),
    );

    $entity->delete();
    $this->messenger()->addStatus($this->t('Page visual %label has been deleted.', [
      '%label' => $entity->label(),
    ]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
