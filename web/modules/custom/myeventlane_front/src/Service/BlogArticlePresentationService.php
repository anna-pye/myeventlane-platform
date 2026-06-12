<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Builds presentation variables for public blog article pages.
 */
final class BlogArticlePresentationService {

  use StringTranslationTrait;

  private const WORDS_PER_MINUTE = 200;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BlogRelatedContentService $relatedContent,
    private readonly BlogResourceDownloadBuilder $resourceDownloads,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function build(NodeInterface $node): array {
    if ($node->bundle() !== 'blog_post') {
      return [];
    }

    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $relatedBlog = [];
    $relatedTags = [];
    foreach ($this->relatedContent->getRelatedBlogPosts($node) as $related) {
      $build = $viewBuilder->view($related, 'teaser');
      $relatedBlog[] = $build;
      $relatedTags = array_merge($relatedTags, $related->getCacheTags());
    }

    $relatedHelp = [];
    foreach ($this->relatedContent->getRelatedHelpArticles($node) as $help) {
      $relatedHelp[] = [
        'title' => $help->label(),
        'url' => $help->toUrl()->toString(),
      ];
      $relatedTags = array_merge($relatedTags, $help->getCacheTags());
    }

    $featureLinks = [];
    $config = $this->configFactory->get('myeventlane_front.organiser_hub');
    foreach ($config->get('feature_links') ?? [] as $link) {
      if (!is_array($link)) {
        continue;
      }
      $title = trim((string) ($link['title'] ?? ''));
      $url = trim((string) ($link['url'] ?? ''));
      if ($title === '' || $url === '') {
        continue;
      }
      $featureLinks[] = [
        'title' => $title,
        'url' => Url::fromUri(str_starts_with($url, 'http') ? $url : 'internal:' . $url)->toString(),
      ];
    }

    $isPlaybook = $node->hasField('field_playbook') && (bool) $node->get('field_playbook')->value;
    $resourceDownloads = $this->resourceDownloads->build($node);
    $createEventUrl = Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString();

    return [
      'mel_blog_is_playbook' => $isPlaybook,
      'mel_blog_category' => $this->buildCategory($node),
      'mel_blog_excerpt' => $this->buildExcerpt($node),
      'mel_blog_hero' => $this->buildHero($node),
      'mel_blog_author' => $this->buildAuthor($node),
      'mel_blog_published_date' => $this->buildPublishedDate($node),
      'mel_blog_reading_time' => $this->buildReadingTime($node),
      'mel_blog_tags' => $this->buildTags($node),
      'mel_blog_resource_downloads' => $resourceDownloads,
      'mel_blog_related_articles' => $relatedBlog,
      'mel_blog_related_help' => $relatedHelp,
      'mel_blog_feature_links' => $featureLinks,
      'mel_blog_cta' => $this->buildCta($node, $isPlaybook, $createEventUrl),
      'mel_blog_share_links' => $this->buildShareLinks($node),
      'mel_blog_journey' => $isPlaybook ? [
        'steps' => [
          [
            'id' => 'read',
            'label' => (string) $this->t('Read'),
            'status' => 'current',
          ],
          [
            'id' => 'download',
            'label' => (string) $this->t('Download'),
            'status' => $resourceDownloads === [] ? 'upcoming' : 'available',
            'anchor' => $resourceDownloads === [] ? NULL : '#mel-blog-downloads',
          ],
          [
            'id' => 'create',
            'label' => (string) $this->t('Create event'),
            'status' => 'cta',
            'url' => $createEventUrl,
          ],
        ],
        'cta' => [
          'title' => (string) $this->t('Ready to create your event?'),
          'url' => $createEventUrl,
        ],
      ] : NULL,
      '#cache' => [
        'tags' => array_values(array_unique(array_merge($node->getCacheTags(), $relatedTags, ['config:myeventlane_front.organiser_hub']))),
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * @return array{title: string, url: string|null}|null
   */
  private function buildCategory(NodeInterface $node): ?array {
    if (!$node->hasField('field_organiser_category') || $node->get('field_organiser_category')->isEmpty()) {
      return NULL;
    }
    $term = $node->get('field_organiser_category')->entity;
    if (!$term instanceof TermInterface) {
      return NULL;
    }
    $title = trim((string) $term->label());
    if ($title === '') {
      return NULL;
    }
    return [
      'title' => $title,
      'url' => Url::fromRoute('myeventlane_front.blog_landing', [], [
        'query' => ['category' => (string) $term->id()],
      ])->toString(),
    ];
  }

  private function buildExcerpt(NodeInterface $node): ?string {
    if (!$node->hasField('field_excerpt') || $node->get('field_excerpt')->isEmpty()) {
      return NULL;
    }
    $excerpt = trim((string) $node->get('field_excerpt')->value);
    return $excerpt !== '' ? $excerpt : NULL;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildHero(NodeInterface $node): ?array {
    if (!$node->hasField('field_blog_hero_image') || $node->get('field_blog_hero_image')->isEmpty()) {
      return NULL;
    }
    $media = $node->get('field_blog_hero_image')->entity;
    if ($media === NULL) {
      return NULL;
    }
    $build = $this->entityTypeManager->getViewBuilder('media')->view($media, 'default');
    $build['#attributes']['class'][] = 'mel-blog-article__hero-image';
    return $build;
  }

  /**
   * @return list<array{title: string, url: string}>
   */
  private function buildTags(NodeInterface $node): array {
    if (!$node->hasField('field_tags') || $node->get('field_tags')->isEmpty()) {
      return [];
    }
    $tags = [];
    foreach ($node->get('field_tags') as $item) {
      $term = $item->entity;
      if (!$term instanceof TermInterface) {
        continue;
      }
      $title = trim((string) $term->label());
      if ($title === '') {
        continue;
      }
      $tags[] = [
        'title' => $title,
        'url' => $term->toUrl()->toString(),
      ];
    }
    return $tags;
  }

  /**
   * @return array{variant: string, title: string, body: string, button: string, url: string, secondary?: array{button: string, url: string}}
   */
  private function buildCta(NodeInterface $node, bool $isPlaybook, string $createEventUrl): array {
    $audience = $this->getAudience($node);
    $eventsUrl = Url::fromRoute('view.upcoming_events.page_events')->toString();
    $hubUrl = Url::fromRoute('myeventlane_front.organiser_hub')->toString();
    $playbooksUrl = $hubUrl . '#mel-organiser-hub-playbooks';

    if ($isPlaybook) {
      return [
        'variant' => 'create_event',
        'title' => (string) $this->t('Ready to create your event?'),
        'body' => (string) $this->t('Put what you have learned into action on MyEventLane.'),
        'button' => (string) $this->t('Create your event'),
        'url' => $createEventUrl,
        'secondary' => [
          'button' => (string) $this->t('Browse playbooks'),
          'url' => $playbooksUrl,
        ],
      ];
    }

    if ($audience === 'organiser') {
      return [
        'variant' => 'create_event',
        'title' => (string) $this->t('Ready to create your event?'),
        'body' => (string) $this->t('Put what you have learned into action on MyEventLane.'),
        'button' => (string) $this->t('Create your event'),
        'url' => $createEventUrl,
        'secondary' => [
          'button' => (string) $this->t('Organiser Hub'),
          'url' => $hubUrl,
        ],
      ];
    }

    if ($audience === 'both') {
      return [
        'variant' => 'create_event',
        'title' => (string) $this->t('Ready to create your event?'),
        'body' => (string) $this->t('Host your own event or discover what is happening near you.'),
        'button' => (string) $this->t('Create your event'),
        'url' => $createEventUrl,
        'secondary' => [
          'button' => (string) $this->t('Browse events'),
          'url' => $eventsUrl,
        ],
      ];
    }

    return [
      'variant' => 'discover_events',
      'title' => (string) $this->t('Find something to do this weekend'),
      'body' => (string) $this->t('Discover events happening near you on MyEventLane.'),
      'button' => (string) $this->t('Discover events'),
      'url' => $eventsUrl,
      'secondary' => [
        'button' => (string) $this->t('Browse events'),
        'url' => $eventsUrl,
      ],
    ];
  }

  private function getAudience(NodeInterface $node): string {
    if ($node->hasField('field_blog_audience') && !$node->get('field_blog_audience')->isEmpty()) {
      return (string) $node->get('field_blog_audience')->value;
    }
    return 'attendee';
  }

  /**
   * @return list<array{id: string, title: string, url: string}>
   */
  private function buildShareLinks(NodeInterface $node): array {
    $canonical = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $encodedUrl = rawurlencode($canonical);
    $encodedTitle = rawurlencode($node->label());

    return [
      [
        'id' => 'facebook',
        'title' => (string) $this->t('Share on Facebook'),
        'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl,
      ],
      [
        'id' => 'linkedin',
        'title' => (string) $this->t('Share on LinkedIn'),
        'url' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl,
      ],
      [
        'id' => 'x',
        'title' => (string) $this->t('Share on X'),
        'url' => 'https://twitter.com/intent/tweet?url=' . $encodedUrl . '&text=' . $encodedTitle,
      ],
    ];
  }

  /**
   * @return array{name: string, url: string|null}|null
   */
  private function buildAuthor(NodeInterface $node): ?array {
    $type = 'staff';
    if ($node->hasField('field_blog_author_type') && !$node->get('field_blog_author_type')->isEmpty()) {
      $type = (string) $node->get('field_blog_author_type')->value;
    }

    return match ($type) {
      'myeventlane' => [
        'name' => (string) $this->t('MyEventLane'),
        'url' => NULL,
      ],
      'guest' => $this->buildGuestAuthor($node),
      default => $this->buildStaffAuthor($node),
    };
  }

  /**
   * @return array{name: string, url: string|null}|null
   */
  private function buildGuestAuthor(NodeInterface $node): ?array {
    if (!$node->hasField('field_blog_author_name') || $node->get('field_blog_author_name')->isEmpty()) {
      return NULL;
    }
    $name = trim((string) $node->get('field_blog_author_name')->value);
    if ($name === '') {
      return NULL;
    }
    return [
      'name' => $name,
      'url' => NULL,
    ];
  }

  /**
   * @return array{name: string, url: string|null}|null
   */
  private function buildStaffAuthor(NodeInterface $node): ?array {
    $owner = $node->getOwner();
    if (!$owner instanceof UserInterface) {
      return NULL;
    }
    $name = trim($owner->getDisplayName());
    if ($name === '') {
      return NULL;
    }
    return [
      'name' => $name,
      'url' => $owner->isAnonymous() ? NULL : $owner->toUrl()->toString(),
    ];
  }

  private function buildPublishedDate(NodeInterface $node): string {
    if ($node->hasField('field_publish_date') && !$node->get('field_publish_date')->isEmpty()) {
      $date = $node->get('field_publish_date')->date;
      if ($date !== NULL) {
        return $this->dateFormatter->format($date->getTimestamp(), 'custom', 'j F Y');
      }
    }
    return $this->dateFormatter->format((int) $node->getChangedTime(), 'custom', 'j F Y');
  }

  /**
   * @return array{minutes: int, label: string}|null
   */
  private function buildReadingTime(NodeInterface $node): ?array {
    $text = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $text = (string) $node->get('body')->value;
    }
    $plain = Html::decodeEntities(strip_tags($text));
    $words = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY);
    $count = is_array($words) ? count($words) : 0;
    if ($count === 0) {
      return NULL;
    }
    $minutes = max(1, (int) ceil($count / self::WORDS_PER_MINUTE));
    return [
      'minutes' => $minutes,
      'label' => (string) $this->formatPlural($minutes, '1 min read', '@count min read'),
    ];
  }

}
