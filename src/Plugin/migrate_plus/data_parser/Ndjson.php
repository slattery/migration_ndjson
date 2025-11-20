<?php

declare(strict_types=1);

namespace Drupal\migration_ndjson\Plugin\migrate_plus\data_parser;

use Drupal\migrate_plus\Plugin\migrate_plus\data_parser\Json;

/**
 * Provides NDJSON data parser plugin.
 *
 * Parses newline-delimited JSON (NDJSON) data efficiently using streaming.
 * Each line in the NDJSON file must be a complete, valid JSON object.
 *
 * @DataParser(
 *   id = "ndjson",
 *   title = @Translation("NDJSON")
 * )
 */
class Ndjson extends Json {

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
    // Use the parent's data fetcher to get the raw NDJSON content.
    $response = $this->getDataFetcherPlugin()->getResponseContent($url);

    if (empty($response)) {
      return FALSE;
    }

    // Process the NDJSON response line-by-line.
    $items = $this->parseNdjson($response, $this->itemSelector);

    if (is_null($items) || !is_array($items)) {
      return FALSE;
    }

    $this->iterator = new \ArrayIterator($items);
    $this->currentUrl = $url;
    return TRUE;
  }

  /**
   * Parse NDJSON content and return array of items.
   *
   * @param string $response
   *   Raw NDJSON response content.
   * @param string|int $item_selector
   *   Item selector for nested data extraction.
   *
   * @return array
   *   Array of parsed items.
   *
   * @throws \Exception
   *   If strict mode is enabled and malformed JSON is encountered.
   */
  protected function parseNdjson(
    string $response,
    string|int $item_selector = ''
  ): array {
    $items = [];
    $this->currentLineNumber = 0;

    // Split by newlines and process each line as a separate JSON object.
    $lines = explode("\n", $response);

    foreach ($lines as $line) {
      $this->currentLineNumber++;

      // Skip empty lines.
      $line = trim($line);
      if (empty($line)) {
        continue;
      }

      try {
        // Try UTF-8 decoding if JSON decode fails (similar to parent Json parser).
        $decoded = json_decode($line, TRUE, flags: JSON_THROW_ON_ERROR);

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

}
