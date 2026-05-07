<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Session\AccountInterface;

/**
 * Explainable, deterministic prioritisation (tier → weight → id).
 *
 * Governance examples encoded as priority tiers on definitions:
 * - Incomplete onboarding / payouts (tier 100) outrank analytics (tier 35).
 * - Checkout recovery (tier 98) outrank discovery prompts (tier 50).
 * - Moderation recovery (tier 100) outrank generic engagement (tier 58).
 */
final class MelPriorityResolver {

  /**
   * @param list<IntelligenceDefinition> $definitions
   *
   * @return list<IntelligenceDefinition>
   */
  public function rank(AccountInterface $account, array $definitions): array {
    $defs = $definitions;
    usort($defs, static function (IntelligenceDefinition $a, IntelligenceDefinition $b): int {
      if ($a->priorityTier !== $b->priorityTier) {
        return $b->priorityTier <=> $a->priorityTier;
      }
      if ($a->priorityWeight !== $b->priorityWeight) {
        return $b->priorityWeight <=> $a->priorityWeight;
      }
      return strcmp($a->id, $b->id);
    });

    // Role-aware deterministic deprioritisation: anonymous cannot receive authenticated-only
    // hints beyond what triggers already enforced — this is an extra guard for future alters.
    if (!$account->isAuthenticated()) {
      $defs = array_values(array_filter($defs, static fn (IntelligenceDefinition $d): bool => !$d->requiresAuthentication));
    }

    return $defs;
  }

  /**
   * @param list<IntelligenceDefinition> $ordered
   *
   * @return list<array<string, mixed>>
   */
  public function explainOrder(array $ordered): array {
    $trace = [];
    $position = 0;
    foreach ($ordered as $definition) {
      $position++;
      $trace[] = [
        'position' => $position,
        'id' => $definition->id,
        'priority_tier' => $definition->priorityTier,
        'priority_weight' => $definition->priorityWeight,
        'rule' => 'sort_key: tier DESC, weight DESC, id ASC (lexical)',
        'category' => $definition->category->value,
      ];
    }
    return $trace;
  }

}
