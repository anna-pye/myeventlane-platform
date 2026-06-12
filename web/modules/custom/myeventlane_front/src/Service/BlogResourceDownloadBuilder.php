<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\link\LinkItemInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Builds download card data for organiser playbook resources.
 */
final class BlogResourceDownloadBuilder {

  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * @return list<array{
   *   title: string,
   *   url: string,
   *   kind: string,
   *   is_external: bool
   * }>
   */
  public function build(NodeInterface $node): array {
    if ($node->bundle() !== 'blog_post') {
      return [];
    }

    $downloads = [];

    if ($node->hasField('field_resource_downloads') && !$node->get('field_resource_downloads')->isEmpty()) {
      foreach ($node->get('field_resource_downloads') as $item) {
        $media = $item->entity;
        if (!$media instanceof MediaInterface || $media->bundle() !== 'document') {
          continue;
        }
        if (!$media->hasField('field_media_document') || $media->get('field_media_document')->isEmpty()) {
          continue;
        }
        $file = $media->get('field_media_document')->entity;
        if (!$file instanceof FileInterface) {
          continue;
        }

        $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        $downloads[] = [
          'title' => $this->resolveMediaTitle($media, $file),
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'kind' => $extension !== '' ? strtoupper($extension) : 'FILE',
          'is_external' => FALSE,
        ];
      }
    }

    if ($node->hasField('field_resource_links') && !$node->get('field_resource_links')->isEmpty()) {
      foreach ($node->get('field_resource_links') as $item) {
        if (!$item instanceof LinkItemInterface || $item->isEmpty()) {
          continue;
        }
        $url = $item->getUrl()->toString();
        if ($url === '') {
          continue;
        }
        $title = trim((string) $item->title);
        if ($title === '') {
          $title = (string) $item->getUrl()->toString();
        }
        $downloads[] = [
          'title' => $title,
          'url' => $url,
          'kind' => 'LINK',
          'is_external' => TRUE,
        ];
      }
    }

    return $downloads;
  }

  private function resolveMediaTitle(MediaInterface $media, FileInterface $file): string {
    $name = trim($media->label());
    if ($name !== '') {
      return $name;
    }
    return $file->getFilename();
  }

}
