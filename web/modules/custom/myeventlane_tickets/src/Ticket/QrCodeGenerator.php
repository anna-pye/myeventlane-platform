<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Psr\Log\LoggerInterface;

/**
 * Generates QR codes for ticket PDFs.
 */
final class QrCodeGenerator {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a PNG QR data URI for use in ticket PDF templates.
   */
  public function buildDataUri(string $payload, int $size = 260): ?string {
    $payload = trim($payload);
    if ($payload === '') {
      return NULL;
    }

    if (!class_exists(Builder::class)) {
      $this->logger->error('QR generation dependency missing: class {class} not found.', [
        'class' => Builder::class,
      ]);
      return NULL;
    }

    $targetSize = max(220, min(300, $size));

    try {
      if (method_exists(Builder::class, 'create')) {
        // Older endroid/qr-code API.
        $result = Builder::create()
          ->writer(new PngWriter())
          ->data($payload)
          ->encoding(new Encoding('UTF-8'))
          ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
          ->size($targetSize)
          ->margin(8)
          ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
          ->build();
      }
      else {
        // Newer endroid/qr-code API: constructor-based builder.
        $reflection = new \ReflectionClass(Builder::class);
        $constructor = $reflection->getConstructor();
        if ($constructor === NULL) {
          throw new \RuntimeException('QR Builder constructor is unavailable.');
        }

        $argumentMap = [
          'writer' => new PngWriter(),
          'data' => $payload,
          'encoding' => new Encoding('UTF-8'),
          'errorCorrectionLevel' => ErrorCorrectionLevel::Medium,
          'size' => $targetSize,
          'margin' => 8,
          'roundBlockSizeMode' => RoundBlockSizeMode::Margin,
        ];

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
          $name = $parameter->getName();
          if (array_key_exists($name, $argumentMap)) {
            $arguments[] = $argumentMap[$name];
            continue;
          }
          if ($parameter->isDefaultValueAvailable()) {
            $arguments[] = $parameter->getDefaultValue();
            continue;
          }
          throw new \RuntimeException(sprintf('Unsupported QR Builder constructor parameter: %s', $name));
        }

        $builder = $reflection->newInstanceArgs($arguments);
        if (!method_exists($builder, 'build')) {
          throw new \RuntimeException('QR Builder build() method is unavailable.');
        }
        $result = $builder->build();
      }

      return $result->getDataUri();
    }
    catch (\Throwable $exception) {
      $this->logger->error('QR generation failed for ticket payload hash {hash}: {message}', [
        'hash' => hash('sha256', $payload),
        'message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

}
