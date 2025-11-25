<?php

declare(strict_types=1);

namespace Drupal\migration_ndjson\Plugin\migrate_plus\data_parser;

use Drupal\migrate_plus\Plugin\migrate_plus\data_parser\Json;
use Sunaoka\Ndjson\NDJSON;

/**
 * Provides NDJSON streaming data parser plugin.
 *
 * Parses newline-delimited JSON (NDJSON) data using true streaming.
 * Each line in the NDJSON file must be a complete, valid JSON object.
 *
 * NOTE: This parser only works with the 'file' data fetcher plugin for
 * local files. It provides true streaming (line-by-line) processing and is
 * more memory-efficient than the non-streaming 'ndjson' parser for large files.
 *
 * @DataParser(
 *   id = "ndjson_stream",
 *   title = @Translation("NDJSON (Streaming)")
 * )
 */
class NdjsonStream extends Json {

  /**
   * Current line number being processed.
   *
   * @var int
   */
  protected int $currentLineNumber = 0;

  /**
   * Whether to fail on malformed JSON (strict mode).
   *
   * @var bool
   */
  protected bool $strictMode = TRUE;

  /**
   * The NDJSON file reader instance.
   *
   * @var \Sunaoka\Ndjson\NDJSON|null
   */
  protected ?NDJSON $ndjsonReader = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    $dataFetcherPluginManager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $dataFetcherPluginManager);
    $this->strictMode = $configuration['strict_mode'] ?? TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function openSourceUrl(string $url): bool {
    // Extract the file path from the URL.
    $file_path = $this->extractFilePath($url);

    if (empty($file_path) || !file_exists($file_path)) {
      return FALSE;
    }

    // Use the streaming NDJSON reader for true line-by-line processing.
    try {
      $this->ndjsonReader = new NDJSON($file_path, 'r');
      $items = $this->parseNdjsonStream($this->itemSelector);

      // Create an iterator even if items is empty (empty file is valid).
      $this->iterator = new \ArrayIterator($items);
      $this->currentUrl = $url;
      return TRUE;
    }
    catch (\Exception $e) {
      \Drupal::logger('migration_ndjson')->error(
        'Failed to open NDJSON stream at @url: @error',
        [
          '@url' => $url,
          '@error' => $e->getMessage(),
        ]
      );
      return FALSE;
    }
  }

  /**
   * Extract file path from a URL or return it if already a file path.
   *
   * @param string $url
   *   URL or file path.
   *
   * @return string|null
   *   The file path, or NULL if unable to extract.
   */
  protected function extractFilePath(string $url): ?string {
    // Handle file:// URLs.
    if (str_starts_with($url, 'file://')) {
      return substr($url, 7);
    }

    // If it looks like an drupal filesystem alias, find and return path.
    if (str_starts_with($url, 'public://') || str_starts_with($url, 'private://')) {
      $path = \Drupal::service('stream_wrapper_manager')->getViaUri($url)->realpath();
      return $path;
    }

    // If it looks like an absolute path, return as-is.
    if (str_starts_with($url, '/')) {
      return $url;
    }

    // For other schemes (http, https, etc.), this parser cannot handle them.
    // Log a warning and return NULL.
    if (str_contains($url, '://')) {
      \Drupal::logger('migration_ndjson')->warning(
        'NDJSON streaming parser only supports file:// URLs and local paths. Got: @url',
        ['@url' => $url]
      );
      return NULL;
    }

    // Assume it's a relative path; make it absolute.
    if (file_exists($url)) {
      return realpath($url) ?: $url;
    }

    return NULL;
  }

  /**
   * Parse NDJSON stream and return array of items.
   *
   * Uses the Sunaoka NDJSON reader for true streaming, reading one line at
   * a time instead of loading the entire file into memory.
   *
   * @param string|int $item_selector
   *   Item selector for nested data extraction.
   *
   * @return array
   *   Array of parsed items.
   *
   * @throws \Exception
   *   If strict mode is enabled and malformed JSON is encountered.
   */
  protected function parseNdjsonStream(string|int $item_selector = ''): array {
    $items = [];
    $this->currentLineNumber = 0;

    if (empty($this->ndjsonReader)) {
      return $items;
    }

    // Read lines using the streaming reader.
    while (!$this->ndjsonReader->eof()) {
      $this->currentLineNumber++;

      try {
        // Use the NDJSON reader's readline() method for streaming.
        // It automatically skips empty lines and trims whitespace.
        // Note: Sunaoka's readline() returns NULL for both empty lines AND
        // malformed JSON, so we need to check the raw line to detect errors.
        $rawLine = $this->ndjsonReader->fgets();

        if (empty($rawLine)) {
          // Check if we're at EOF or if this is an empty line.
          if ($this->ndjsonReader->eof() === FALSE) {
            // Empty line in the middle of the file, skip it.
            continue;
          }
          else {
            // End of file.
            break;
          }
        }

        // Try to decode the line with JSON_THROW_ON_ERROR to detect errors.
        $decoded = json_decode(trim($rawLine), TRUE, flags: JSON_THROW_ON_ERROR);

        // Apply item selector if provided (for nested data extraction).
        if (!empty($item_selector)) {
          $decoded = $this->selectItem($decoded, (string) $item_selector);
        }

        if (!empty($decoded)) {
          $items[] = $decoded;
        }
      }
      catch (\JsonException $e) {
        $error_msg = sprintf(
          'Malformed NDJSON at line %d in %s: %s',
          $this->currentLineNumber,
          $this->currentUrl ?? '(unknown)',
          $e->getMessage()
        );

        if ($this->strictMode) {
          throw new \Exception($error_msg, 0, $e);
        }
        else {
          \Drupal::logger('migration_ndjson')->warning(
            'Skipped malformed NDJSON at line @line in @url: @error',
            [
              '@line' => $this->currentLineNumber,
              '@url' => $this->currentUrl ?? '(unknown)',
              '@error' => $e->getMessage(),
            ]
          );
        }
      }
    }

    // Close the reader to release the file handle.
    if ($this->ndjsonReader instanceof NDJSON) {
      $this->ndjsonReader = NULL;
    }

    return $items;
  }

  /**
   * Extract an item from the data using JSONPath-style selector.
   *
   * Supports simple paths like:
   * - "/" (root, entire object)
   * - "/data" (top-level key)
   * - "/data/results" (nested keys)
   *
   * @param array $data
   *   The decoded JSON data.
   * @param string $selector
   *   The JSONPath-style selector.
   *
   * @return mixed
   *   The selected item, or NULL if not found.
   */
  protected function selectItem(array $data, string $selector) {
    // Root selector returns the entire object.
    if ($selector === '/' || $selector === '') {
      return $data;
    }

    // Remove leading/trailing slashes for processing.
    $selector = trim($selector, '/');

    if (empty($selector)) {
      return $data;
    }

    // Split the selector by '/' and navigate through the nested data.
    $parts = explode('/', $selector);
    $current = $data;

    foreach ($parts as $part) {
      if (!is_array($current) || !isset($current[$part])) {
        return NULL;
      }
      $current = $current[$part];
    }

    return $current;
  }

  /**
   * {@inheritdoc}
   */
  public function __destruct() {
    // Ensure the reader is properly closed when the parser is destroyed.
    if ($this->ndjsonReader instanceof NDJSON) {
      $this->ndjsonReader = NULL;
    }
  }

}
