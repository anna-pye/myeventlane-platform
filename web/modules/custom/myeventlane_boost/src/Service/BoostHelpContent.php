<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

/**
 * Centralised Boost help content (tooltips, PDF, onboarding, FAQ).
 *
 * IMPORTANT:
 * - Tooltip copy must match the approved wording exactly.
 * - Onboarding copy is a simplified adaptation (no CSV mention).
 */
final class BoostHelpContent {

  /**
   * Approved inline tooltip copy for Boost exports and analytics UI.
   *
   * @return array<string, string>
   *   Tooltip text keyed by machine name.
   */
  public function getInlineTooltipCopy(): array {
    return [
      'event_title' => 'The event this Boost placement was promoting.',
      'event_id' => 'Internal reference for this event. Useful if you run multiple events with similar names.',
      'boost_placement' => 'Where your event was featured, such as the homepage, a category page, or a featured carousel.',
      'status' => 'The current or final state of this Boost placement: Active, Completed, Cancelled, or Refunded.',
      'start_date' => 'When this Boost placement started. Shown in site time.',
      'end_date' => 'When this Boost placement ended or is scheduled to end.',
      'spend' => 'The amount paid for this Boost placement. This is a fixed amount and does not change if the boost is cancelled or refunded.',
      'impressions' => 'How many times your event was shown in this placement.',
      'clicks' => 'How many times people clicked through to your event page from this placement.',
      'ctr' => 'Click-through rate. Calculated as clicks divided by impressions. Useful for comparing placements fairly.',
      'sales_during_boost' => 'Orders placed for this event while the Boost was active. This shows timing, not causation.',
      'orders_following_click_24h' => 'Orders placed within 24 hours of a Boost click. This is contextual and does not guarantee the click caused the sale.',
      'boost_performance_section' => 'This data shows how your Boost placements performed. Metrics are conservative and do not guess attribution.',
    ];
  }

  /**
   * Approved long-form content for the PDF guide.
   *
   * @return array<string, mixed>
   *   Structured content for the PDF template.
   */
  public function getPdfGuideContent(): array {
    return [
      'title' => 'Boost Performance Summary',
      'what_this_shows' => [
        'This CSV shows how your Boosted event placements performed.',
        'It’s designed for clarity, not hype.',
        'Use it to review performance, report to sponsors, or decide whether to boost again.',
        '',
        'Each row = one Boost placement for an event.',
        'If you boosted the same event in multiple places (for example, Homepage + Category), you’ll see multiple rows.',
      ],
      'important_notes' => [
        'Boost data is honest and conservative',
        'We do not guess attribution',
        'We do not inflate conversions',
        'Cancelled boosts keep historical data',
        'Draft or incomplete orders are excluded',
        'RSVP events won’t show sales metrics',
        'Not recommended: Claiming direct causation (“this boost caused X sales”)',
      ],
      'need_help' => [
        'If something looks unclear or unexpected, contact support and include:',
        'Event name',
        'Export file',
        'Placement you’re reviewing',
      ],
    ];
  }

  /**
   * Simplified onboarding content for vendors (no CSV mention).
   *
   * @return array<string, mixed>
   *   Structured onboarding content.
   */
  public function getOnboardingContent(): array {
    return [
      'title' => 'Promote your event with Boost',
      'intro' => [
        'Boost can feature your event in promoted placements across the site.',
        'You’ll see clear performance metrics so you can understand visibility and interest.',
      ],
      'sections' => [
        [
          'heading' => 'What Boost does',
          'body' => [
            'Boost increases visibility by featuring your event in specific placements, such as the homepage or category pages.',
          ],
        ],
        [
          'heading' => 'What the metrics mean',
          'body' => [
            'Impressions show how many times your event was shown.',
            'Clicks show how many times people clicked through to your event page.',
            'CTR compares engagement fairly by dividing clicks by impressions.',
          ],
        ],
        [
          'heading' => 'What Boost does not claim',
          'body' => [
            'Boost does not guarantee sales.',
            'We do not guess attribution or claim that clicks caused purchases.',
            'Cancelled boosts keep historical performance data.',
          ],
        ],
      ],
    ];
  }

  /**
   * FAQ content (foundation), structured for later migration.
   *
   * Customer-facing answers are intentionally left empty until approved copy
   * exists; questions are provided as the initial foundation.
   *
   * @return array<string, array<int, array{question: string, answer: string}>>
   *   FAQs grouped by audience.
   */
  public function getFaqContent(): array {
    return [
      'vendors' => [
        [
          'question' => 'What is Boost?',
          'answer' => 'Boost increases visibility by featuring your event in specific placements, such as the homepage or category pages.',
        ],
        [
          'question' => 'How is performance measured?',
          'answer' => 'Boost performance is shown using impressions, clicks, and click-through rate (CTR). Metrics are conservative and do not guess attribution.',
        ],
        [
          'question' => 'Why don’t we show guaranteed conversions?',
          'answer' => 'Sales metrics show timing, not causation. We do not guess attribution or claim that clicks caused purchases.',
        ],
        [
          'question' => 'What happens if a Boost is cancelled?',
          'answer' => 'Cancelled boosts keep historical data. Spend is a fixed amount and does not change if the boost is cancelled or refunded.',
        ],
        [
          'question' => 'Why do some events show no sales?',
          'answer' => 'Draft or incomplete orders are excluded, and RSVP events won’t show sales metrics.',
        ],
        [
          'question' => 'Can I share this with sponsors?',
          'answer' => 'Yes. The export is designed for sponsor reporting, with honest and conservative metrics that do not guess attribution.',
        ],
      ],
      'customers' => [
        [
          'question' => 'Why am I seeing promoted events?',
          'answer' => '',
        ],
        [
          'question' => 'Does Boost affect ticket prices?',
          'answer' => '',
        ],
        [
          'question' => 'Are promoted events paid ads?',
          'answer' => '',
        ],
      ],
    ];
  }

}
