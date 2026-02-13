<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders vendor nudges for the dashboard.
 *
 * Builds the complete render array including the nudge container,
 * individual nudge cards, and dismiss UI. Dismiss is local only —
 * no server-side tracking or storage is involved.
 *
 * When a nudge returns a help category via getHelpCategory(), this
 * renderer resolves the matching taxonomy term and builds a contextual
 * "Learn more" link to the vendor help centre topic page.
 * If help_centre module is not enabled, "Learn more" links are omitted
 * and no errors are logged.
 */
final class VendorNudgeRenderer {

  use StringTranslationTrait;

  /**
   * Resolved help category term IDs, keyed by term name.
   *
   * Populated lazily on first use. NULL means not yet resolved.
   *
   * @var array<string, int>|null
   */
  private ?array $helpCategoryMap = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a render array for the given nudges.
   *
   * @param \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface[] $nudges
   *   An array of applicable nudge instances.
   *
   * @return array
   *   A Drupal render array using the 'vendor_nudges' theme hook,
   *   or an empty array if no nudges are provided.
   */
  public function build(array $nudges): array {
    if (empty($nudges)) {
      return [];
    }

    $items = [];
    foreach ($nudges as $nudge) {
      if (!$nudge instanceof VendorNudgeInterface) {
        continue;
      }

      $rendered = $nudge->render();
      $learnMoreUrl = $rendered['learn_more_url'] ?? NULL;

      // If no explicit learn_more_url, try resolving from help category.
      if ($learnMoreUrl === NULL) {
        $learnMoreUrl = $this->resolveHelpCategoryUrl($nudge);
      }

      $items[] = [
        'id' => $nudge->id(),
        'label' => $nudge->label(),
        'message' => $rendered['message'] ?? '',
        'learn_more_url' => $learnMoreUrl,
      ];
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#theme' => 'vendor_nudges',
      '#nudges' => $items,
      '#heading' => $this->t('Helpful tips'),
      '#attached' => [
        'library' => ['myeventlane_vendor_nudges/vendor-nudges'],
      ],
      '#cache' => ['max-age' => 0],
      '#weight' => -10,
    ];
  }

  /**
   * Resolves a help category URL from a nudge's getHelpCategory() value.
   *
   * Looks up the help_categories taxonomy vocabulary for a term matching
   * the name returned by the nudge. Returns a vendor help topic URL if
   * found, NULL otherwise.
   *
   * @param \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface $nudge
   *   The nudge instance.
   *
   * @return string|null
   *   The URL string, or NULL if no matching category exists.
   */
  private function resolveHelpCategoryUrl(VendorNudgeInterface $nudge): ?string {
    if (!$this->moduleHandler->moduleExists('myeventlane_help_centre')) {
      return NULL;
    }

    $categoryName = $nudge->getHelpCategory();
    if ($categoryName === NULL || $categoryName === '') {
      return NULL;
    }

    $termId = $this->getHelpCategoryTermId($categoryName);
    if ($termId === NULL) {
      return NULL;
    }

    try {
      return Url::fromRoute('myeventlane_help_centre.vendor_topic', [
        'category' => $termId,
      ])->toString();
    }
    catch (\Exception $e) {
      // Route may not exist if help centre module is not installed.
      $this->logger->notice('Could not resolve help centre URL for category "@name": @message', [
        '@name' => $categoryName,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Returns the term ID for a help category by name.
   *
   * Results are cached for the lifetime of this service instance to
   * avoid repeated queries within the same request.
   *
   * @param string $name
   *   The taxonomy term name.
   *
   * @return int|null
   *   The term ID, or NULL if not found.
   */
  private function getHelpCategoryTermId(string $name): ?int {
    if ($this->helpCategoryMap === NULL) {
      $this->helpCategoryMap = [];

      try {
        $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
        $terms = $termStorage->loadByProperties([
          'vid' => 'help_categories',
        ]);

        foreach ($terms as $term) {
          $this->helpCategoryMap[(string) $term->label()] = (int) $term->id();
        }
      }
      catch (\Exception $e) {
        $this->logger->notice('Could not load help category terms: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $this->helpCategoryMap[$name] ?? NULL;
  }

}
