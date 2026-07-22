<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\node\NodeInterface;

/**
 * Presentation-only render scaffolding for vendor productisation Event Studio.
 *
 * No persistence, no Commerce writes, no checkout or entitlement mutation.
 */
final class VendorProductisationStudioBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly VendorProductisationStudioManager $productisationManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalCapabilityCommercePreviewBuilder $commercePreviewBuilder,
    private readonly CustomerOperationalCommerceExperienceBuilder $customerExperienceBuilder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $authoring_state
   *   Output of {@see VendorProductisationStudioManager::buildAuthoringStateFromMerchandise()}.
   * @param array<string, mixed> $document
   *   Full operational capabilities document for customer experience projection.
   *
   * @return array<string, mixed>
   */
  public function buildProductisationWorkspace(array $authoring_state, array $document, NodeInterface $event): array {
    $items = is_array($authoring_state['items'] ?? NULL) ? $authoring_state['items'] : [];
    $cards = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $type = (string) ($item['productisation_type'] ?? '');
      $cards[] = [
        'type' => $type,
        'label' => $this->typeLabel($type),
        'readiness' => $this->readinessChipForItem($event, $item),
        'commerce' => $this->commercePresentationForItem($item),
        'customer_preview' => $this->customerPreviewForItem($item),
      ];
    }

    $experience = $this->customerExperienceBuilder->buildFromOperationalDocument($document);

    return [
      '#theme' => 'mel_event_studio_productisation',
      '#cards' => $cards,
      '#customer_experience_data' => $experience,
      '#validation' => $this->buildValidationSummary(is_array($authoring_state['validation_messages'] ?? NULL) ? $authoring_state['validation_messages'] : []),
      '#cta_rows' => $this->buildCtaRows(),
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => ['user.permissions', 'languages:language_interface'],
      ],
    ];
  }

  /**
   * @param list<string> $messages
   *
   * @return array<string, mixed>
   */
  public function buildValidationSummary(array $messages): array {
    $rows = [];
    foreach ($messages as $message) {
      $message = trim((string) $message);
      if ($message === '') {
        continue;
      }
      $rows[] = ['text' => $message];
    }
    return [
      'has_errors' => $rows !== [],
      'messages' => $rows,
    ];
  }

  /**
   * @return list<array<string, string>>
   */
  private function buildCtaRows(): array {
    $out = [];
    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $type) {
      $out[] = [
        'type' => $type,
        'label' => (string) $this->t('Add or refine @label', ['@label' => $this->typeLabel($type)]),
      ];
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function commercePresentationForItem(array $item): array {
    $commerce = is_array($item['commerce'] ?? NULL) ? $item['commerce'] : [];
    $fake_row = [
      'commerce_linkage' => [
        'product_id' => (int) ($commerce['product_id'] ?? 0),
        'variation_ids' => $commerce['variation_ids'] ?? [],
        'linkage_mode' => (string) ($commerce['linkage_mode'] ?? OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT),
        'capability_binding_state' => OperationalCapabilityCommerceLinkManager::BINDING_UNBOUND,
      ],
    ];
    return $this->commercePreviewBuilder->buildCardLinkagePresentation($fake_row);
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function customerPreviewForItem(array $item): array {
    $commerce = is_array($item['commerce'] ?? NULL) ? $item['commerce'] : [];
    $pid = (int) ($commerce['product_id'] ?? 0);
    if ($pid < 1) {
      return [
        'headline' => $this->customerFacingHeadline($item),
        'presentation' => [],
        'note' => (string) $this->t('Link a product or use the create path — after save, the customer-safe operational summary appears here.'),
      ];
    }
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($pid);
    if (!$product instanceof ProductInterface) {
      return [
        'headline' => $this->customerFacingHeadline($item),
        'presentation' => [],
        'note' => '',
      ];
    }
    $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
    return [
      'headline' => $this->customerFacingHeadline($item),
      'presentation' => $presentation,
      'note' => (string) $this->t('Preview uses the same customer-safe operational product presentation as storefront summaries.'),
    ];
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function readinessChipForItem(NodeInterface $event, array $item): array {
    $errors = $this->productisationManager->validateProductisationItem($event, $item);
    if ($errors !== []) {
      return ['label' => (string) $this->t('Needs review'), 'tone' => 'warning'];
    }
    $commerce = is_array($item['commerce'] ?? NULL) ? $item['commerce'] : [];
    if ((int) ($commerce['product_id'] ?? 0) < 1) {
      return ['label' => (string) $this->t('Draft only'), 'tone' => 'muted'];
    }
    return ['label' => (string) $this->t('Tickets connected'), 'tone' => 'success'];
  }

  private function typeLabel(string $type): string {
    return match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => (string) $this->t('Hospitality package'),
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => (string) $this->t('Timed collection'),
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => (string) $this->t('Parking add-on'),
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => (string) $this->t('Operational bundle'),
      default => (string) $this->t('Merchandise'),
    };
  }

  /**
   * @param array<string, mixed> $item
   */
  private function customerFacingHeadline(array $item): string {
    $type = (string) ($item['productisation_type'] ?? '');
    return match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => trim((string) ($item['package_name'] ?? '')),
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => trim((string) ($item['collection_label'] ?? '')),
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => (string) $this->t('Parking guidance'),
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => trim((string) ($item['bundle_label'] ?? '')),
      default => trim((string) ($item['title'] ?? '')),
    };
  }

}
