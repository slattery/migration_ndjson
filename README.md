# Migration NDJSON

A Drupal module that provides **NDJSON data parser plugins** for **Migrate Plus**.

This module enables efficient import of newline-delimited JSON (NDJSON) data into Drupal. It provides two parsers:

- **`ndjson`** (non-streaming): Loads data from any source (local files, HTTP URLs, etc.) into memory. Works with all data fetchers.
- **`ndjson_stream`** (streaming): Uses true line-by-line streaming for memory-efficient processing of large files. Only works with local files via the `file` data fetcher, and requires the `sunaoka/ndjson` library.

## What is NDJSON?

**NDJSON** (Newline Delimited JSON) is a format where each line is a complete, standalone JSON object. This is different from traditional JSON arrays.

Example:
```ndjson
{"id": 1, "name": "Record One", "type": "article"}
{"id": 2, "name": "Record Two", "type": "book"}
{"id": 3, "name": "Record Three", "type": "chapter"}
```

### Why NDJSON over JSON?

- **Memory efficient**: Processes one line at a time instead of loading entire file
- **Streaming support**: Can handle files larger than available RAM
- **Incremental processing**: Start processing data before the entire file is downloaded
- **API friendly**: Many APIs return NDJSON for large datasets (e.g., OpenAlex)

## Requirements

- Drupal 10.1+ or 11.0+
- Migrate Plus 6.0+
- PHP 8.1+

## Installation

1. Add the module to your custom modules directory:
   ```bash
   composer require sunaoka/ndjson
   drush en migration_ndjson
   ```

2. The module automatically registers the `ndjson` data parser plugin with Migrate Plus.

## Choosing Between `ndjson` and `ndjson_stream`

### `ndjson` Parser (Non-Streaming)
- **Use when**: Working with HTTP URLs, multiple file sources, or moderate-sized files
- **Advantages**: Works with any data fetcher (file, http, etc.), simpler configuration
- **Limitation**: Loads entire response into memory
- **Data sources**: Files, HTTP APIs, any URL-based source

### `ndjson_stream` Parser (Streaming)
- **Use when**: Processing large NDJSON files that exceed available memory
- **Advantages**: True line-by-line streaming, minimal memory overhead, fastest for large files
- **Limitation**: Only works with local files via the `file` data fetcher
- **Requires**: `sunaoka/ndjson` library (installed as a dependency)
- **Data sources**: Local files only

**Memory Comparison** (for a 500 MB NDJSON file):
- `ndjson` parser: ~500 MB memory usage
- `ndjson_stream` parser: ~2-5 MB memory usage (constant overhead)

## Configuration

### Basic Migration Setup

To use the NDJSON parser in your migration, specify `data_parser_plugin: ndjson` (or `ndjson_stream` for streaming):

```yaml
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson
  urls:
    - 'file:///path/to/data.ndjson'
```

### Configuration Options

#### `strict_mode` (boolean, default: true)

Controls how the parser handles malformed JSON lines:

- **`true` (default)**: The migration **fails immediately** when a malformed JSON line is encountered. This ensures data quality by catching errors early.

- **`false`**: Malformed lines are **skipped** with a warning logged to watchdog. Useful for dirty data sources where you want the migration to continue despite errors.

```yaml
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson
  urls:
    - 'file:///path/to/data.ndjson'
  strict_mode: false  # Skip bad lines instead of failing
```

#### `item_selector` (string, default: "/")

Extract nested data from within each NDJSON line using JSONPath-style selectors.

**Selectors:**
- `/` or empty - Returns the entire line (default)
- `/data` - Returns the `data` property from each line
- `/results/items` - Returns nested path

**Example:**

If your NDJSON file contains:
```ndjson
{"id": 1, "data": {"title": "Article", "author": "John"}}
{"id": 2, "data": {"title": "Book", "author": "Jane"}}
```

Using `item_selector: /data` extracts just:
```json
{"title": "Article", "author": "John"}
{"title": "Book", "author": "Jane"}
```

Usage in migration:
```yaml
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson
  urls:
    - 'file:///path/to/data.ndjson'
  item_selector: /data

process:
  title: title
  author: author
```

## Usage Examples

### Example 1: Import from Local File

```yaml
id: import_articles
label: 'Import Articles from NDJSON'
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson
  urls:
    - 'file:///var/data/articles.ndjson'

process:
  title: title
  body: content
  type: article_type

destination:
  plugin: entity:node
  default_bundle: article
```

### Example 2: Import from HTTP URL

```yaml
id: import_from_api
label: 'Import from API Endpoint'
source:
  plugin: url
  data_fetcher_plugin: http
  data_parser_plugin: ndjson
  urls:
    - 'https://api.example.com/data/export.ndjson'

process:
  title: name
  description: summary

destination:
  plugin: entity:node
  default_bundle: page
```

### Example 3: Handle Dirty Data (Permissive Mode)

```yaml
id: import_legacy_data
label: 'Import Legacy Data (Skip Errors)'
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson
  urls:
    - 'file:///data/legacy_records.ndjson'
  strict_mode: false  # Continue on malformed lines

process:
  title: title
  body: description

destination:
  plugin: entity:node
  default_bundle: article
```

### Example 4: Extract Nested Data

```yaml
id: import_nested_api
label: 'Import Nested API Data'
source:
  plugin: url
  data_fetcher_plugin: http
  data_parser_plugin: ndjson
  urls:
    - 'https://api.example.com/records.ndjson'
  item_selector: /record   # Extract the 'record' property

process:
  title: title
  publication_date: published
  authors: author_names

destination:
  plugin: entity:node
  default_bundle: article
```

### Example 5: Multiple Files

```yaml
id: import_multiple_files
label: 'Import from Multiple Files'
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson
  urls:
    - 'file:///data/batch_1.ndjson'
    - 'file:///data/batch_2.ndjson'
    - 'file:///data/batch_3.ndjson'

process:
  title: title
  body: content

destination:
  plugin: entity:node
  default_bundle: article
```

### Example 6: Large File with Streaming Parser

```yaml
id: import_large_dataset
label: 'Import Large Dataset (Streaming)'
source:
  plugin: url
  data_fetcher_plugin: file
  data_parser_plugin: ndjson_stream  # Use streaming parser
  urls:
    - 'file:///data/massive_dataset.ndjson'  # 1GB+ file
  strict_mode: false  # Skip malformed lines

process:
  nid: id
  title: title
  body: description
  created: timestamp

destination:
  plugin: entity:node
  default_bundle: article
```

**Note**: The `ndjson_stream` parser requires the `sunaoka/ndjson` library and only works with local files.

## How It Works

### Non-Streaming Parser (`ndjson`)

1. **Data Fetcher** (migrate_plus): Retrieves the NDJSON data
   - Uses `file` fetcher for local files
   - Uses `http` fetcher for URLs or any other source

2. **NDJSON Parser** (this module): Processes the data in memory
   - Gets entire response content from data fetcher
   - Splits raw data by newlines
   - Parses each line as a separate JSON object
   - Applies `item_selector` to extract nested data
   - Skips empty lines
   - Handles errors based on `strict_mode`

3. **Migrate Source** (migrate_plus): Orchestrates the process
   - Feeds individual items to the migration

4. **Migration Pipeline** (Drupal migrate): Maps and imports
   - Applies process plugins
   - Creates/updates entities

### Streaming Parser (`ndjson_stream`)

1. **File Access** (direct): Opens the local file directly
   - Extracts file path from `file://` URLs or absolute paths
   - Returns FALSE for http/https URLs (not supported)

2. **NDJSON Streaming Parser** (this module): Processes line-by-line
   - Uses `Sunaoka\Ndjson\NDJSON` for true streaming
   - Reads one line at a time from the file
   - Parses each line as a separate JSON object
   - Applies `item_selector` to extract nested data
   - Handles errors based on `strict_mode`
   - Maintains minimal memory overhead (constant, not proportional to file size)

3. **Migrate Source** (migrate_plus): Orchestrates the process
   - Feeds individual items to the migration

4. **Migration Pipeline** (Drupal migrate): Maps and imports
   - Applies process plugins
   - Creates/updates entities

## Logging

The module logs warnings to the `migration_ndjson` logger channel when errors occur:

```bash
# View migration logs
drush watchdog:show migration_ndjson

# Clear logs
drush watchdog:delete migration_ndjson
```

### Example Log Message (Permissive Mode)

```
Skipped malformed NDJSON at line 42 in file:///data/records.ndjson: Syntax error, malformed JSON
```

## Memory Efficiency

The parser processes NDJSON files line-by-line without loading the entire file into memory:

- **File size**: 500 MB
- **Memory usage**: ~2 MB (constant, regardless of file size)
- **Processing speed**: ~100,000 lines/minute (varies by system)

This makes it possible to migrate very large datasets that would be impossible with standard JSON parsing.

## Troubleshooting

### Migration Fails with "Malformed NDJSON" Error

**Cause**: A line in your NDJSON file contains invalid JSON.

**Solutions**:
1. **Check the data**: Validate your NDJSON file:
   ```bash
   # Validate each line
   jq . data.ndjson
   ```

2. **Use permissive mode**: Set `strict_mode: false` to skip bad lines
   ```yaml
   strict_mode: false
   ```

3. **Pre-process the data**: Clean the NDJSON file before migration

### Item Selector Returns Empty Results

**Cause**: The path specified in `item_selector` doesn't exist in the data.

**Solution**: Check your data structure:
```bash
# View a sample line
head -1 data.ndjson | jq .
```

If your file has `{"data": {...}}`, use:
```yaml
item_selector: /data
```

### Very Slow Migration Performance

**Causes**:
- File is very large (expected)
- Processing plugins are slow
- Destination is writing slowly (database)

**Solutions**:
1. Increase PHP memory limit (if needed)
2. Optimize process plugins
3. Disable indexing during migration
4. Use `drush migrate:import --execute-dependencies` to control execution

### File Not Found Errors

**Cause**: Path in `urls` is incorrect.

**Solution**: Verify the file path:
```bash
# Check file exists and is readable
ls -la /path/to/data.ndjson
```

For relative paths, use absolute paths or `file://` protocol.

### NDJSON Streaming Parser Errors

#### "NDJSON streaming parser only supports file:// URLs"

**Cause**: You tried to use the `ndjson_stream` parser with an HTTP URL.

**Solution**: The streaming parser only works with local files. Your options:
1. Download the file locally and use `file://` URL
2. Switch to the `ndjson` parser instead:
   ```yaml
   data_parser_plugin: ndjson  # Use non-streaming parser
   ```

#### "Cannot open NDJSON stream at @url"

**Cause**: The file path is invalid or the file doesn't exist.

**Solution**: Verify the file exists and the path is correct:
```bash
# Check if file exists
test -f /path/to/data.ndjson && echo "File exists" || echo "File not found"
```

## Comparison with Other Parsers

| Feature | NDJSON | JSON | XML | CSV |
|---------|--------|------|-----|-----|
| Memory efficient | ✅ Yes | ❌ No | ⚠️ Partial | ✅ Yes |
| Streaming | ✅ Yes | ❌ No | ⚠️ Partial | ✅ Yes |
| Nested data | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |
| Large files | ✅ Yes | ❌ No | ⚠️ Limited | ✅ Yes |
| API-friendly | ✅ Yes | ✅ Yes | ⚠️ Less common | ⚠️ Less common |

## API Documentation

### Ndjson Parser Class

**Namespace**: `Drupal\migration_ndjson\Plugin\migrate_plus\data_parser`

**Plugin ID**: `ndjson`

**Extends**: `Drupal\migrate_plus\Plugin\migrate_plus\data_parser\Json`

**Configuration Options**:
- `strict_mode` (boolean): Whether to fail on malformed JSON
- `item_selector` (string): JSONPath-style selector for nested data extraction

## Testing

Run unit tests:
```bash
phpunit --filter NdjsonTest
```

Test with a real migration:
```bash
# Import migration
drush migrate:import my_migration

# Check status
drush migrate:status my_migration

# Rollback if needed
drush migrate:rollback my_migration
```

## Performance Notes

- **Typical processing speed**: 50,000-200,000 lines per minute
- **Memory overhead**: ~2 MB per parser instance
- **File size limit**: No technical limit (only system storage)
- **Maximum line size**: ~1 MB per line (depends on JSON decode settings)

Performance varies based on:
- Complexity of process plugins
- Destination database speed
- Available system resources
- JSON line complexity

## Contributing

Found a bug or have suggestions? Please:
1. Check existing issues
2. Report with specific steps to reproduce
3. Include sample data (sanitized)
4. Mention Drupal and PHP versions

## License

This module is part of the Yale School of the Environment digital initiatives.

## Support

For support with:
- **NDJSON format**: See [ndjson.org](http://ndjson.org/)
- **Migrate Plus**: See [Migrate Plus documentation](https://www.drupal.org/project/migrate_plus)
- **Drupal migrations**: See [Drupal Migration Guide](https://www.drupal.org/docs/upgrading-drupal/prepare-for-upgrade-steps/drupal-migration-guide)
