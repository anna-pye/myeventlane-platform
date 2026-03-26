<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Service;

/**
 * Normalises queries and audience labels for deduplication.
 */
final class DocumentationOpportunityNormalizer {

  /**
   * Collapses whitespace and lowercases for stable dedupe keys.
   */
  public function normalizeTopic(string $text): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text) > 250) {
      $text = mb_substr($text, 0, 250);
    }
    return $text;
  }

  /**
   * Maps analytics context_audience to opportunity audience.
   */
  public function mapAnalyticsAudience(?string $contextAudience): string {
    if ($contextAudience === NULL || $contextAudience === '') {
      return 'unknown';
    }
    $v = mb_strtolower(trim($contextAudience));
    return in_array($v, ['public', 'vendor', 'staff'], TRUE) ? $v : 'unknown';
  }

  /**
   * Validates opportunity audience values.
   */
  public function sanitizeAudience(string $audience): string {
    $v = mb_strtolower(trim($audience));
    return in_array($v, ['public', 'vendor', 'staff', 'unknown'], TRUE) ? $v : 'unknown';
  }

  /**
   * Human title suggestion from a raw query.
   */
  public function suggestTitleFromQuery(string $query): string {
    $q = trim(preg_replace('/\s+/u', ' ', strip_tags($query)) ?? '');
    if ($q === '') {
      return 'Untitled documentation opportunity';
    }
    if (mb_strlen($q) > 200) {
      $q = mb_substr($q, 0, 197) . '…';
    }
    return $q;
  }

  /**
   * Parses Help Assistant analytics payload: query=...|rc=N|cf=...
   *
   * @return array{query: string, result_count: int}
   */
  public function parseAiAnalyticsPayload(?string $payload): array {
    if ($payload === NULL || $payload === '') {
      return ['query' => '', 'result_count' => 0];
    }
    if (!str_contains($payload, 'query=')) {
      return ['query' => trim($payload), 'result_count' => 0];
    }
    if (!preg_match('/query=([^|]+)/', $payload, $m)) {
      return ['query' => '', 'result_count' => 0];
    }
    $q = trim((string) $m[1]);
    $rc = 0;
    if (preg_match('/rc=(\d+)/', $payload, $rm)) {
      $rc = (int) $rm[1];
    }
    return ['query' => $q, 'result_count' => $rc];
  }

}
