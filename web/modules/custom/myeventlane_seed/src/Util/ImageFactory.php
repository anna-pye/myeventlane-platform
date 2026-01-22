<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Util;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory as CoreImageFactory;
use Drupal\file\FileInterface;

/**
 * Factory for generating placeholder images for demo data.
 */
final class ImageFactory {

  /**
   * Constructs an ImageFactory.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Image\ImageFactory $imageFactory
   *   The core image factory.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly CoreImageFactory $imageFactory,
  ) {}

  /**
   * Creates a placeholder PNG image file.
   *
   * @param string $filename
   *   The filename (without extension).
   * @param int $width
   *   Image width in pixels.
   * @param int $height
   *   Image height in pixels.
   * @param string $text
   *   Text to render on the image.
   * @param string $directory
   *   Subdirectory within public:// (e.g., 'events', 'vendor_logos').
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity, or NULL on failure.
   */
  public function createPlaceholderImage(
    string $filename,
    int $width = 1200,
    int $height = 630,
    string $text = '',
    string $directory = 'seed',
  ): ?FileInterface {
    $public_path = 'public://' . $directory;
    if (!$this->fileSystem->prepareDirectory($public_path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return NULL;
    }

    // Sanitize filename.
    $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    $uri = $public_path . '/' . $safe_filename . '.png';

    // Create a GD image resource.
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
      return NULL;
    }

    // Fill with a gradient background (light blue to light purple).
    $color1 = imagecolorallocate($image, 200, 220, 255);
    $color2 = imagecolorallocate($image, 255, 220, 255);
    for ($i = 0; $i < $height; $i++) {
      $ratio = $i / $height;
      $r = (int) (200 + ($ratio * (255 - 200)));
      $g = (int) (220 + ($ratio * (220 - 220)));
      $b = (int) (255 + ($ratio * (255 - 255)));
      $color = imagecolorallocate($image, $r, $g, $b);
      imageline($image, 0, $i, $width, $i, $color);
    }

    // Add text if provided (using built-in fonts for portability).
    if (!empty($text)) {
      $text_color = imagecolorallocate($image, 60, 60, 60);
      $text_short = substr($text, 0, 50);
      $text_width = imagefontwidth(5) * strlen($text_short);
      $text_height = imagefontheight(5);
      $x = (int) (($width - $text_width) / 2);
      $y = (int) (($height - $text_height) / 2);
      imagestring($image, 5, $x, $y, $text_short, $text_color);
    }

    // Save to temporary file first.
    $temp_file = tempnam(sys_get_temp_dir(), 'seed_img_');
    if ($temp_file === FALSE) {
      imagedestroy($image);
      return NULL;
    }

    if (!imagepng($image, $temp_file)) {
      imagedestroy($image);
      @unlink($temp_file);
      return NULL;
    }

    imagedestroy($image);

    // Copy to public directory.
    $destination = $this->fileSystem->realpath($uri);
    if (!$destination) {
      @unlink($temp_file);
      return NULL;
    }

    if (!copy($temp_file, $destination)) {
      @unlink($temp_file);
      return NULL;
    }
    @unlink($temp_file);

    // Create file entity.
    /** @var \Drupal\file\FileInterface $file */
    $file = \Drupal::entityTypeManager()->getStorage('file')->create([
      'uri' => $uri,
      'uid' => 1,
    // FILE_STATUS_PERMANENT.
      'status' => 1,
      'filename' => $safe_filename . '.png',
    ]);
    $file->save();

    return $file;
  }

}
