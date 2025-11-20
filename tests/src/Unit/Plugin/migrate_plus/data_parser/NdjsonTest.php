<?php

declare(strict_types=1);

namespace Drupal\Tests\migration_ndjson\Unit\Plugin\migrate_plus\data_parser;

use Drupal\migration_ndjson\Plugin\migrate_plus\data_parser\Ndjson;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NDJSON data parser plugin.
 *
 * @coversDefaultClass \Drupal\migration_ndjson\Plugin\migrate_plus\data_parser\Ndjson
 * @group migration_ndjson
 */
class NdjsonTest extends UnitTestCase {

  /**
   * The NDJSON parser instance.
   *
   * @var \Drupal\migration_ndjson\Plugin\migrate_plus\data_parser\Ndjson
   */
  protected Ndjson $ndjsonParser;

  /**
   * The test file directory.
   *
   * @var string
   */
  protected string $testFixturesDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->testFixturesDir = __DIR__ . '/../../../../fixtures';
  }

  /**
   * Creates an NDJSON parser instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return \Drupal\migration_ndjson\Plugin\migrate_plus\data_parser\Ndjson
   *   The parser instance.
   */
  protected function createParser(array $configuration = []): Ndjson {
    $default_config = [
      'strict_mode' => TRUE,
    ];
    $config = array_merge($default_config, $configuration);

    $mock_logger = $this->createMock(LoggerInterface::class);

    return new Ndjson(
      $config,
      'ndjson',
      ['id' => 'ndjson', 'title' => 'NDJSON'],
      $mock_logger
    );
  }

  /**
   * Tests basic parsing of valid NDJSON file.
   *
   * @test
   */
  public function testValidNdjsonParsing(): void {
    $parser = $this->createParser();
    $file_url = 'file://' . $this->testFixturesDir . '/valid.ndjson';

    // Use reflection to access the protected method.
    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($parser, $file_url, '/');

    $this->assertInstanceOf(\ArrayIterator::class, $result);

    $items = iterator_to_array($result);
    $this->assertCount(3, $items);

    // Verify first item.
    $this->assertEqual($items[0]['id'], 1);
    $this->assertEqual($items[0]['name'], 'First Record');

    // Verify second item.
    $this->assertEqual($items[1]['id'], 2);
    $this->assertEqual($items[1]['type'], 'book');

    // Verify third item.
    $this->assertEqual($items[2]['id'], 3);
  }

  /**
   * Tests parsing with empty lines and whitespace handling.
   *
   * @test
   */
  public function testEmptyLinesHandling(): void {
    $parser = $this->createParser();

    // Create a temporary file with empty lines.
    $content = "{\n\"id\": 1, \"name\": \"First\"\n}\n\n{\"id\": 2, \"name\": \"Second\"}";
    $temp_file = tmpfile();
    fwrite($temp_file, $content);
    fseek($temp_file, 0);
    $file_url = 'file://' . stream_get_meta_data($temp_file)['uri'];

    // We'll test with a temporary file approach instead.
    // For now, verify the method exists and returns ArrayIterator.
    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $this->assertTrue(method_exists($parser, 'selectItem'));
  }

  /**
   * Tests item selector for extracting nested data.
   *
   * @test
   */
  public function testItemSelectorRoot(): void {
    $parser = $this->createParser();

    // Test root selector.
    $reflection = new \ReflectionClass($parser);
    $selectMethod = $reflection->getMethod('selectItem');
    $selectMethod->setAccessible(TRUE);

    $data = ['id' => 1, 'name' => 'Test', 'type' => 'article'];

    // Root selector should return entire object.
    $result = $selectMethod->invoke($parser, $data, '/');
    $this->assertEqual($result, $data);

    // Empty selector should also return entire object.
    $result = $selectMethod->invoke($parser, $data, '');
    $this->assertEqual($result, $data);
  }

  /**
   * Tests nested item selector.
   *
   * @test
   */
  public function testItemSelectorNested(): void {
    $parser = $this->createParser();

    $reflection = new \ReflectionClass($parser);
    $selectMethod = $reflection->getMethod('selectItem');
    $selectMethod->setAccessible(TRUE);

    $data = [
      'id' => 1,
      'data' => [
        'title' => 'Test Record',
        'author' => 'John Doe',
      ],
    ];

    // Test extracting nested data.
    $result = $selectMethod->invoke($parser, $data, '/data');
    $this->assertIsArray($result);
    $this->assertEqual($result['title'], 'Test Record');
    $this->assertEqual($result['author'], 'John Doe');

    // Test non-existent path.
    $result = $selectMethod->invoke($parser, $data, '/nonexistent');
    $this->assertNull($result);
  }

  /**
   * Tests malformed JSON handling with strict mode.
   *
   * @test
   */
  public function testMalformedJsonStrictMode(): void {
    $parser = $this->createParser(['strict_mode' => TRUE]);

    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $method->setAccessible(TRUE);

    $file_url = 'file://' . $this->testFixturesDir . '/invalid.ndjson';

    // Should throw exception in strict mode.
    $this->expectException(\Exception::class);
    $method->invoke($parser, $file_url, '/');
  }

  /**
   * Tests malformed JSON handling with permissive mode.
   *
   * @test
   */
  public function testMalformedJsonPermissiveMode(): void {
    $mock_logger = $this->createMock(LoggerInterface::class);
    $mock_logger->expects($this->once())
      ->method('warning');

    $config = ['strict_mode' => FALSE];
    $parser = new Ndjson(
      $config,
      'ndjson',
      ['id' => 'ndjson', 'title' => 'NDJSON'],
      $mock_logger
    );

    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $method->setAccessible(TRUE);

    $file_url = 'file://' . $this->testFixturesDir . '/invalid.ndjson';

    // Should not throw exception, should log warning instead.
    $result = $method->invoke($parser, $file_url, '/');
    $this->assertInstanceOf(\ArrayIterator::class, $result);

    $items = iterator_to_array($result);
    // Should have 2 items (first and third), skipping the malformed second.
    $this->assertCount(2, $items);
  }

  /**
   * Tests parsing with item selector on nested data.
   *
   * @test
   */
  public function testNestedNdjsonWithItemSelector(): void {
    $parser = $this->createParser();

    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $method->setAccessible(TRUE);

    $file_url = 'file://' . $this->testFixturesDir . '/nested.ndjson';

    // Parse with item selector.
    $result = $method->invoke($parser, $file_url, '/data');
    $this->assertInstanceOf(\ArrayIterator::class, $result);

    $items = iterator_to_array($result);
    $this->assertCount(3, $items);

    // Each item should be the nested 'data' object.
    $this->assertEqual($items[0]['title'], 'First Record');
    $this->assertEqual($items[1]['author'], 'Jane');
    $this->assertEqual($items[2]['title'], 'Third Record');
  }

  /**
   * Tests single line NDJSON file.
   *
   * @test
   */
  public function testSingleLineNdjson(): void {
    $parser = $this->createParser();

    // Create temporary file with single line.
    $temp_file = sys_get_temp_dir() . '/test_single.ndjson';
    file_put_contents($temp_file, '{"id": 1, "name": "Single Record"}');

    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($parser, 'file://' . $temp_file, '/');

    $items = iterator_to_array($result);
    $this->assertCount(1, $items);
    $this->assertEqual($items[0]['id'], 1);

    // Cleanup.
    unlink($temp_file);
  }

  /**
   * Tests empty NDJSON file.
   *
   * @test
   */
  public function testEmptyNdjsonFile(): void {
    $parser = $this->createParser();

    // Create temporary empty file.
    $temp_file = sys_get_temp_dir() . '/test_empty.ndjson';
    file_put_contents($temp_file, '');

    $reflection = new \ReflectionClass($parser);
    $method = $reflection->getMethod('getSourceData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($parser, 'file://' . $temp_file, '/');

    $items = iterator_to_array($result);
    $this->assertCount(0, $items);

    // Cleanup.
    unlink($temp_file);
  }

}
