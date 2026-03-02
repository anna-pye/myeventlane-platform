<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a list controller for the escalation entity type.
 */
final class EscalationListBuilder extends EntityListBuilder {

  protected DateFormatterInterface $dateFormatter;

  protected RequestStack $requestStack;

  protected EntityFieldManagerInterface $entityFieldManager;

  private ?bool $proFieldExists = NULL;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->requestStack = $container->get('request_stack');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * Checks whether the Pro support field is available on escalation entities.
   *
   * Returns TRUE only when the myeventlane_pro module is installed and the
   * field_is_pro_support base field has been registered.
   */
  private function hasProField(): bool {
    if ($this->proFieldExists === NULL) {
      $definitions = $this->entityFieldManager->getBaseFieldDefinitions('escalation');
      $this->proFieldExists = isset($definitions['field_is_pro_support']);
    }
    return $this->proFieldExists;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    if ($this->hasProField()) {
      $header['pro'] = $this->t('PRO');
    }
    $header['subject'] = $this->t('Subject');
    $header['type'] = $this->t('Type');
    $header['status'] = $this->t('Status');
    $header['priority'] = $this->t('Priority');
    $header['customer'] = $this->t('Customer');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_escalations\Entity\EscalationInterface $entity */
    $row['id'] = $entity->id();
    if ($this->hasProField()) {
      $isPro = (bool) $entity->get('field_is_pro_support')->value;
      $row['pro'] = [
        'data' => [
          '#markup' => $isPro
            ? '<span class="mel-pro-badge" style="font-size:11px;padding:2px 8px;">PRO</span>'
            : '',
        ],
      ];
    }
    $row['subject'] = $entity->toLink();
    $row['type'] = $entity->get('type')->value;
    $row['status'] = $entity->get('status')->value;
    $row['priority'] = $entity->get('priority')->value;
    $customer = $entity->getCustomer();
    $row['customer'] = $customer ? $customer->getDisplayName() : '-';
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->pager(50);

    if ($this->hasProField()) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request && $request->query->get('pro_only') === '1') {
        $query->condition('field_is_pro_support', 1);
      }
      $query->sort('field_is_pro_support', 'DESC');
    }

    $query->sort($this->entityType->getKey('id'), 'DESC');

    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = [];

    if ($this->hasProField()) {
      $request = $this->requestStack->getCurrentRequest();
      $proOnly = $request && $request->query->get('pro_only') === '1';

      $build['filters'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['escalation-pro-filter'],
          'style' => 'margin-bottom:1rem;display:flex;gap:0.5rem;',
        ],
      ];

      $build['filters']['all'] = [
        '#type' => 'link',
        '#title' => $this->t('All escalations'),
        '#url' => Url::fromRoute('entity.escalation.collection'),
        '#attributes' => [
          'class' => array_merge(
            ['button', 'button--small'],
            $proOnly ? [] : ['button--primary'],
          ),
        ],
      ];

      $build['filters']['pro_only'] = [
        '#type' => 'link',
        '#title' => $this->t('Pro only'),
        '#url' => Url::fromRoute('entity.escalation.collection', [], ['query' => ['pro_only' => '1']]),
        '#attributes' => [
          'class' => array_merge(
            ['button', 'button--small'],
            $proOnly ? ['button--primary'] : [],
          ),
        ],
      ];
    }

    $build += parent::render();

    if ($this->hasProField()) {
      $build['#attached']['library'][] = 'myeventlane_pro/pro';
      $build['table']['#cache']['contexts'][] = 'url.query_args:pro_only';
    }

    return $build;
  }

}
